<?php

namespace Z77\Module\Financial\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The one door into the journal (plan §1 «one write path», §5.4; ADR-040
 * decision 2, ADR-042 decision 6): every posting source — debtor, a payment
 * run, the manual-entry screen, an import — posts through {@see post()} and
 * corrects through {@see reverse()}. financial knows none of them: a source
 * is an opaque origin and an idempotency key.
 *
 * The caller OWNS the unit of work (ADR-039 decision 10): both methods
 * assert `getTransaction(JournalEntry::class)->isOpen()` and never open one
 * — an invoice and its posting commit together or not at all, and a
 * rolled-back caller consumes no number. Inside, the order is fixed:
 *
 *   1. idempotency (a read): a repeated key returns the existing `EntryRef`
 *      — with the SAME content; different content is refused loudly
 *      ({@see IdempotencyConflictException});
 *   2. every rule that can refuse (`PostingRules`): fiscal year and period by
 *      date, period state, accounts, tax codes;
 *   3. the number — the FIRST write, drawn from `journal-entry.{code}`
 *      (`NumberRangeRepository::next()`, lock order `NumberRange` first);
 *      never before validation, so a refused posting never holds the lock;
 *   4. the entry with its lines, `persist()`ed — written by the caller's
 *      flush at commit.
 *
 * The «who» is {@see Actor::current()} — the logged-in backend user or the
 * job runner's actor — unless the caller names one (a CLI script, the
 * import, the harness).
 *
 * What a caller sees under a RACE, and what it must do (review 2026-09-22
 * M4): the idempotency lookup is a read, so the same key posted in two
 * parallel units of work — or twice inside ONE unit of work before its
 * flush — passes the lookup twice and fails at the caller's COMMIT on
 * `uniq_journal_entry_idempotency` (Doctrine's
 * `UniqueConstraintViolationException`); two parallel reversals of one entry
 * fail the same way (their key is `reversal:{year}/{number}`), and on
 * `uniq_journal_entry_reversal_of` if the keys somehow differed. The WHOLE
 * unit of work rolls back, no number is consumed, nothing is half-written.
 * The caller lets the exception propagate and does not retry inside the
 * unit of work (ADR-039 decision 10: no retry loop) — a retry is a new unit
 * of work, and with the same key it then returns the existing entry.
 *
 * `accountExists()` of plan §5.4 is NOT here yet: nothing in this change
 * validates a configuration by account number (the first caller is debtor's
 * account settings, P3), and a method without a production caller is not
 * built (CLAUDE.md). It arrives with that caller.
 */
final class LedgerService
{
    /** Rows the journal screen shows per fiscal year when financialConfig carries no `journalListLimit`. */
    public const DEFAULT_LIST_LIMIT = 200;

    private readonly PostingRules $rules;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null)
    {
        $this->rules = new PostingRules($em);
    }

    /**
     * Post an entry inside the caller's open unit of work and return where
     * it went. See the class docblock for the order of things.
     *
     * @throws \LogicException                no unit of work is open — run through `getTransaction(JournalEntry::class)->run()`
     * @throws IdempotencyConflictException   the key exists with other content
     * @throws PostingRefusedException        no year / period, period closed or VAT-settled, account or tax code refused
     */
    public function post(PostingRequest $request): EntryRef
    {
        $this->assertUnitOfWork('post()');

        return $this->postInternal($request, null);
    }

    /**
     * Reverse a GENERATED entry (plan §1, correction principle; ADR-042
     * decisions 7 and 8): a generated counterpart with debit and credit
     * swapped and the tax data negated, dated $date, its text the $reason —
     * the reason IS the reversal's text, so the journal shows it where every
     * other entry shows what happened. It inherits the origin of the entry
     * it reverses (the same source is being corrected) and carries the key
     * `reversal:{year}/{number}`, and `reversalOf` links the two. The
     * reversal's date goes through the same period rules as any posting.
     *
     * The reversal's accounts must be POSTABLE but need not be ACTIVE (review
     * 2026-09-22 M5, ADR-043 decision 19): a counterpart of an existing entry
     * must always be possible, also on an account deactivated since. The
     * reversal's date may lie in a LATER fiscal year than the entry — a
     * correction happens in an open period (ADR-042 decision 10), and it
     * takes the number of the year it is dated in.
     *
     * Refused: a manual entry (edit or delete it), a reversal (reverse the
     * original, or post anew), an entry already reversed (at most once — the
     * schema says so as well), an empty or over-long reason.
     *
     * @throws \LogicException            no unit of work is open
     * @throws ReversalRefusedException   see above
     * @throws PostingRefusedException    the reversal date's period refuses
     */
    public function reverse(EntryRef $ref, \DateTimeImmutable $date, string $reason): EntryRef
    {
        $this->assertUnitOfWork('reverse()');
        $reason = trim($reason);
        if ($reason === '') {
            throw new ReversalRefusedException(ReversalRefusedException::NO_REASON, 'A reversal needs a reason — it becomes the reversal entry\'s text');
        }
        if (mb_strlen($reason) > JournalEntry::TEXT_LENGTH) {
            throw new ReversalRefusedException(ReversalRefusedException::REASON_TOO_LONG, 'The reversal reason is the entry text and holds at most ' . JournalEntry::TEXT_LENGTH . ' characters');
        }

        $entry = $this->entries()->findByRef($ref);
        if ($entry === null) {
            throw new ReversalRefusedException(ReversalRefusedException::NOT_FOUND, "Journal entry {$ref->fiscalYear}/{$ref->number} does not exist");
        }
        if ($entry->isManual()) {
            throw new ReversalRefusedException(ReversalRefusedException::MANUAL, "Journal entry {$ref->fiscalYear}/{$ref->number} is manual — a manual entry is edited or deleted, not reversed");
        }
        if ($entry->isReversal()) {
            throw new ReversalRefusedException(ReversalRefusedException::IS_REVERSAL, "Journal entry {$ref->fiscalYear}/{$ref->number} is itself a reversal — post the original anew instead");
        }
        $reversedBy = $this->entries()->findReversalOf($entry);
        if ($reversedBy !== null) {
            throw new ReversalRefusedException(ReversalRefusedException::ALREADY_REVERSED, "Journal entry {$ref->fiscalYear}/{$ref->number} was already reversed by {$reversedBy->getFiscalYear()->getCode()}/{$reversedBy->getNumber()}");
        }

        $lines = [];
        foreach ($entry->getLines() as $line) {
            $lines[] = (new PostingLine(
                $line->getAccount()->getNumber(),
                $line->getDebit(),
                $line->getCredit(),
                $line->getTaxCode(),
                $line->getTaxRate(),
                $line->getTaxBase(),
                $line->getTaxAmount(),
                $line->getText(),
            ))->swapped();
        }
        if ($entry->getSourceType() === null || $entry->getSourceRef() === null) {
            // A generated entry always names its origin (PostingRequest refuses otherwise) — a
            // null here is broken data, not a case to paper over with an empty string.
            throw new \LogicException("Journal entry {$ref->fiscalYear}/{$ref->number} is generated but carries no origin — data invariant broken");
        }
        $request = PostingRequest::generated(
            $date,
            $reason,
            $entry->getSourceType(),
            $entry->getSourceRef(),
            'reversal:' . $ref->fiscalYear . '/' . $ref->number,
            $lines
        );

        return $this->postInternal($request, $entry, requireActiveAccounts: false);
    }

    /**
     * The installation's base currency — what every amount of the ledger is
     * in (ADR-042 decision 4): `systemConfig → baseCurrency`, the same key
     * the Doctrine bootstrap hands `MoneyType` (which exposes no getter, and
     * asking it would tie the caller to the driver having booted first). The
     * ONE place this module reads it: the manual-entry form parses amounts in
     * it, the reports turn SQL sums into `Money` in it.
     */
    public static function baseCurrency(): string
    {
        return (string) DI::getConfigManager()
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false)
            ->get('baseCurrency', 'CHF');
    }

    /**
     * How many entries the journal screen shows per fiscal year at most —
     * financialConfig `journalListLimit`, default {@see DEFAULT_LIST_LIMIT}
     * (the `ContactService::listLimit()` model). Fails loudly instead of
     * falling back: a wrong value in a config file is a typo to report.
     *
     * @throws \UnexpectedValueException the configured value is not a positive int
     */
    public static function listLimit(): int
    {
        $config     = DI::getModuleManager()->getModuleConfig('financial');
        $configured = $config?->has('journalListLimit')
            ? $config->get('journalListLimit')
            : self::DEFAULT_LIST_LIMIT;

        if (!is_int($configured) || $configured < 1) {
            throw new \UnexpectedValueException(
                'financialConfig: journalListLimit must be a positive int, got ' . var_export($configured, true) . '.'
            );
        }

        return $configured;
    }

    /** The posting proper — `post()` and `reverse()` both end here. */
    private function postInternal(PostingRequest $request, ?JournalEntry $reversalOf, bool $requireActiveAccounts = true): EntryRef
    {
        // 1. Idempotency: a read. Same content → the existing entry; other content → refused.
        if ($request->kind === EntryKind::Generated) {
            $existing = $this->entries()->findOneBy(['idempotencyKey' => $request->idempotencyKey]);
            if ($existing !== null) {
                $ref = new EntryRef($existing->getFiscalYear()->getCode(), $existing->getNumber());
                if (PostingRequest::fingerprintOf($existing->snapshot()) !== $request->fingerprint()) {
                    throw new IdempotencyConflictException((string) $request->idempotencyKey, $ref);
                }

                return $ref;
            }
        }

        // 2. Everything that can refuse — before any write, before any lock.
        $year   = $this->rules->yearFor($request->date);
        $period = $this->rules->periodFor($year, $request->date);
        $this->rules->assertPeriodAccepts($period, $request->hasTaxLine());
        $accounts = $this->rules->resolveAccounts($request->lines, $requireActiveAccounts);
        $this->rules->assertTaxCodesExist($request->lines);
        $actor = $this->actorName();   // an unnamed author refuses here, before the lock

        // 3. The number: the FIRST write of the unit of work (lock order).
        /** @var NumberRangeRepository $ranges */
        $ranges = $this->em->getRepository(NumberRange::class);
        $number = $ranges->next($year->journalEntryRange());

        // 4. The entry, written by the caller's flush at commit.
        $entry = new JournalEntry(
            $year,
            $number,
            $request->date,
            $request->text,
            $request->kind,
            $request->sourceType,
            $request->sourceRef,
            $request->idempotencyKey,
            $actor,
            new \DateTimeImmutable(),
            $reversalOf,
        );
        foreach ($this->rules->buildLines($entry, $request, $accounts) as $line) {
            $entry->addLine($line);
        }
        $this->em->persist($entry);

        return new EntryRef($year->getCode(), $number);
    }

    private function assertUnitOfWork(string $method): void
    {
        if (!$this->em->getTransaction(JournalEntry::class)->isOpen()) {
            throw new \LogicException(
                "LedgerService::{$method} joins the caller's unit of work and never opens one (ADR-040 decision 7, "
                . 'ADR-039 decision 10) — run it inside getTransaction(JournalEntry::class)->run(fn() => …), '
                . 'together with the writes that belong to the posting.'
            );
        }
    }

    private function actorName(): string
    {
        return $this->actor !== null ? Actor::normalize($this->actor) : Actor::current();
    }

    private function entries(): JournalEntryRepository
    {
        return $this->em->getRepository(JournalEntry::class);
    }
}
