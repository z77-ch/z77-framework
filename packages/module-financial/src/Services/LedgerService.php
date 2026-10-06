<?php

namespace Z77\Module\Financial\Services;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\ChangeAction;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Vat\Entities\TaxCategory;
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
 *      right after it the accounts are re-checked on a locking read that
 *      holds their rows SHARED until commit (`PostingRules::lockAccounts()`,
 *      FIN-TYPE-001: an account cannot become a group while a posting on
 *      it is in flight — refused here only in that race, and the refusal
 *      must propagate so the number goes back with the rollback);
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
 * `accountExists()` of plan §5.4 validates a configuration that names an
 * account by number; its callers are the one-line manual entry (the VAT
 * account of the mandator record), debtor's account settings and the
 * mandator screen itself. The module's setting readers live here as well:
 * `baseCurrency()`, `listLimit()`, `vatAccountFor()` — the last one reads
 * the MANDATOR record since E2 (2026-09-23), not a config key.
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
     * CHANGE a GENERATED entry in place — by the module that posted it, inside
     * its unit of work (ADR-042 addendum 2026-10-06, owner: «eine aus dem
     * Debitorenprogramm generierte Buchung kann nur über das
     * Debitorenprogramm geändert werden — die Fibu-Buchung wird damit
     * geändert; im Journal ist sie gesperrt»). The entry is found by its
     * idempotency KEY — the source names what it posted, never a number —
     * and $new is the posting as it should read now, from the same source
     * (`sourceType` must match; `sourceRef` and the key are taken from $new
     * for the stored entry). The number stays; the date may move within the
     * fiscal year; a date in another year is refused (retract and post anew
     * there). Every change writes an {@see EntryChange} (who, when,
     * before / after), exactly as a manual edit does — the bookkeeper sees
     * it in the journal's change log, can never edit it there. The same
     * period rules as a manual edit: `closed` refuses, `vat-settled` refuses
     * with a tax line involved (`PostingRules::assertPeriodAllowsChange()`).
     * An entry that was reversed, or is itself a reversal, is frozen.
     * A $new whose content equals the stored entry is a no-op (no change
     * row, no version bump).
     *
     * Lock order (the manual edit's): entry (X) → accounts (S) → year (S) →
     * period (S).
     *
     * @return EntryRef where the entry is (unchanged by an amend)
     * @throws \LogicException              no unit of work is open, or $new is not generated
     * @throws EntryNotEditableException    NOT_FOUND | MANUAL | SOURCE_MISMATCH | REVERSED | PERIOD_CLOSED | PERIOD_VAT_SETTLED | FISCAL_YEAR_CHANGED
     * @throws PostingRefusedException      an account or tax code of the new version is refused
     */
    public function amend(string $idempotencyKey, PostingRequest $new): EntryRef
    {
        $this->assertUnitOfWork('amend()');
        if ($new->kind !== EntryKind::Generated) {
            throw new \LogicException('amend() takes a generated PostingRequest — the source\'s posting as it should read now');
        }
        $entry = $this->ownedGeneratedEntry($idempotencyKey, $new->sourceType, 'amended');
        $ref   = new EntryRef($entry->getFiscalYear()->getCode(), $entry->getNumber());
        if (PostingRequest::fingerprintOf($entry->snapshot()) === $new->fingerprint()) {
            return $ref;
        }

        $year    = $entry->getFiscalYear();
        $newYear = $this->rules->yearFor($new->date);
        if ($newYear->getId() !== $year->getId()) {
            throw new EntryNotEditableException(
                EntryNotEditableException::FISCAL_YEAR_CHANGED,
                "Journal entry {$year->getCode()}/{$entry->getNumber()}: the new date lies in fiscal year {$newYear->getCode()} — "
                . 'the number belongs to its year; retract the entry and post it anew there'
            );
        }
        $withTax   = $entry->hasTaxLine() || $new->hasTaxLine();
        $oldPeriod = $this->rules->periodFor($year, $entry->getDate());
        $newPeriod = $this->rules->periodFor($year, $new->date);
        $this->rules->assertPeriodAllowsChange($oldPeriod, $withTax, $entry, $oldPeriod->getState());
        $this->rules->assertPeriodAllowsChange($newPeriod, $withTax, $entry, $newPeriod->getState());
        // A changed posting needs postable accounts; an active one is not required (the counterpart of what exists, M5).
        $accounts = $this->rules->resolveAccounts($new->lines, false);
        $this->rules->assertTaxCodesExist($new->lines);
        $actor = $this->actorName();
        $this->rules->lockAccounts($new->lines, $accounts, false);
        $this->rules->assertPeriodAllowsChange($oldPeriod, $withTax, $entry, $this->rules->lockedPeriodState($oldPeriod));
        $this->rules->assertPeriodAllowsChange($newPeriod, $withTax, $entry, $this->rules->lockedPeriodState($newPeriod));

        $now    = new \DateTimeImmutable();
        $before = $entry->snapshot();
        $entry->amend($new->date, $new->text, $this->rules->buildLines($entry, $new, $accounts), $actor, $now);
        $this->em->persist(new EntryChange($entry, ChangeAction::Update, $actor, $now, $before, $entry->snapshot()));
        $this->em->persist($entry);

        return $ref;
    }

    /**
     * REMOVE a GENERATED entry — by the module that posted it, inside its
     * unit of work (the counterpart of {@see amend()}, the manual delete's
     * rules): the entry and its lines go, its number stays consumed, and the
     * change row with the full snapshot explains the gap (ADR-042
     * decision 9). The same period rules; a reversed entry or a reversal is
     * frozen. Answers null when no entry carries the key (nothing to retract
     * — a source that posted through the Null gateway, or retracted
     * already), so a caller can retract blindly.
     *
     * @throws \LogicException              no unit of work is open
     * @throws EntryNotEditableException    MANUAL | SOURCE_MISMATCH | REVERSED | PERIOD_CLOSED | PERIOD_VAT_SETTLED
     */
    public function retract(string $idempotencyKey, string $sourceType): ?EntryRef
    {
        $this->assertUnitOfWork('retract()');
        try {
            $entry = $this->ownedGeneratedEntry($idempotencyKey, $sourceType, 'retracted');
        } catch (EntryNotEditableException $e) {
            if ($e->reason === EntryNotEditableException::NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        $ref    = new EntryRef($entry->getFiscalYear()->getCode(), $entry->getNumber());
        $period = $this->rules->periodFor($entry->getFiscalYear(), $entry->getDate());
        $this->rules->assertPeriodAllowsChange($period, $entry->hasTaxLine(), $entry, $this->rules->lockedPeriodState($period));

        $this->em->persist(new EntryChange($entry, ChangeAction::Delete, $this->actorName(), new \DateTimeImmutable(), $entry->snapshot(), null));
        $this->em->remove($entry);

        return $ref;
    }

    /**
     * The generated entry under $idempotencyKey, LOCKED (`lockForUpdate`,
     * lines loaded), owned by $sourceType, not reversed and not a reversal.
     *
     * @throws EntryNotEditableException NOT_FOUND | MANUAL | SOURCE_MISMATCH | REVERSED
     */
    private function ownedGeneratedEntry(string $idempotencyKey, ?string $sourceType, string $verb): JournalEntry
    {
        $found = $this->entries()->findOneBy(['idempotencyKey' => $idempotencyKey]);
        if ($found === null) {
            throw new EntryNotEditableException(EntryNotEditableException::NOT_FOUND, "No journal entry carries the idempotency key '{$idempotencyKey}' — nothing to be {$verb}");
        }
        $entry = $this->entries()->lockForUpdate((int) $found->getId());
        if ($entry === null) {
            throw new EntryNotEditableException(EntryNotEditableException::NOT_FOUND, "Journal entry under key '{$idempotencyKey}' vanished before it could be {$verb}");
        }
        $ref = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
        if ($entry->isManual()) {
            throw new EntryNotEditableException(EntryNotEditableException::MANUAL, "Journal entry {$ref} is manual — it is edited by the bookkeeper, not by a module");
        }
        if ($sourceType === null || $entry->getSourceType() !== $sourceType) {
            throw new EntryNotEditableException(EntryNotEditableException::SOURCE_MISMATCH, "Journal entry {$ref} belongs to source '{$entry->getSourceType()}' — only that source may have it {$verb}");
        }
        if ($entry->isReversal() || $this->entries()->findReversalOf($entry) !== null) {
            throw new EntryNotEditableException(EntryNotEditableException::REVERSED, "Journal entry {$ref} is part of a reversal pair — a reversed entry and its reversal are frozen");
        }

        return $entry;
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

    /**
     * Whether a configuration may name this account (plan §5.4, «for
     * configuration validation»): it exists AND can take a new posting —
     * postable (not a group) and active. A configured account that fails
     * this would be refused by {@see post()} anyway; asking first lets the
     * caller name the configuration key in its message instead of a bare
     * «account unknown». A plain read, no lock — `post()` checks again under
     * its own rules.
     */
    public function accountExists(string $number): bool
    {
        $account = $this->em->getRepository(Account::class)->findOneBy(['number' => $number]);

        return $account !== null && $account->isPostable() && $account->isActive();
    }

    /**
     * The account number the VAT of a tax-code category is posted to — read
     * from the MANDATOR record since owner decision E2 (2026-09-23; before
     * that `financialConfig → vatAccounts`): `input-material` → the
     * record's `vat-input-material`, `input-other` → `vat-input-other`,
     * `standard` / `reduced` / `special` → `vat-owed` (KMU 1170 / 1171 /
     * 2200 as start values). The ONE reader of those fields in this module
     * (Rule 2) — no caller asks the mandator for an account directly.
     *
     * Null when there is no account to post to: a category without a tax
     * line (zero, exempt, reverse-charge), no mandator saved yet, or the
     * field left empty on it. The caller refuses with a message naming the
     * mandator ({@see MANDATOR_HINT}) — never a 500, never a fallback.
     *
     * A `vatAccounts` key still present in financialConfig (a project
     * override copied before the move, BOOT-CONFIG-001) is refused loudly:
     * a second source that is silently ignored would be worse than a typo
     * ({@see listLimit()}'s stance). So is a mandator that cannot be read
     * at all (module not registered, table missing). Both come out as
     * {@see VatAccountUnavailableException} with a GERMAN sentence naming
     * the next step, which the callers show as a band or a refusal — never
     * a 500 (owner decision, review 2026-09-23).
     *
     * @throws VatAccountUnavailableException financialConfig still carries `vatAccounts`, or the mandator record cannot be read
     */
    public function vatAccountFor(string $category): ?string
    {
        self::refuseLegacyVatAccounts();

        $key = match (TaxCategory::tryFrom($category)) {
            TaxCategory::InputMaterial => 'vat-input-material',
            TaxCategory::InputOther    => 'vat-input-other',
            TaxCategory::Standard, TaxCategory::Reduced, TaxCategory::Special => 'vat-owed',
            default                    => null,
        };
        if ($key === null) {
            return null;
        }

        try {
            $number = (new CurrentMandator($this->em))->find()?->account($key) ?? '';
        } catch (MandatorUnavailableException $e) {
            throw new VatAccountUnavailableException(VatAccountUnavailableException::MANDATOR_UNAVAILABLE, $e->getMessage(), $e);
        }

        return $number === '' ? null : $number;
    }

    /**
     * The German sentence to show as a band when NO VAT account can be
     * resolved at all — a leftover `vatAccounts`, an unregistered mandator
     * module, a missing table ({@see VatAccountUnavailableException}) —
     * or null when the resolution works (whether or not a number is set).
     * The journal screens ask it once per page (review 2026-09-23: the
     * state must show, not 500).
     */
    public function vatAccountNotice(): ?string
    {
        try {
            $this->vatAccountFor(TaxCategory::Standard->value);
        } catch (VatAccountUnavailableException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Where a missing VAT account is set — the sentence every refusal of {@see vatAccountFor()}'s callers ends with. */
    public const MANDATOR_HINT = 'im Mandanten hinterlegen (Finanzen → Mandant, Konten)';

    /** @throws VatAccountUnavailableException the key of the pre-E2 model is still in the config (German, for the screen) */
    private static function refuseLegacyVatAccounts(): void
    {
        if (DI::getModuleManager()->getModuleConfig('financial')?->has('vatAccounts')) {
            throw new VatAccountUnavailableException(
                VatAccountUnavailableException::LEGACY_CONFIG,
                'financialConfig enthält noch den Schlüssel «vatAccounts» — die MWST-Konten liegen seit dem 23.09.2026 im Mandanten (Finanzen → Mandant, Konten). '
                . 'Den Schlüssel aus dem Projekt-Override (override/z77/module/financial/…/financialConfig.inc.php) entfernen; bis dahin wird keine MWST-Zeile gebucht.'
            );
        }
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
        // 3b. The accounts again, share-locked until commit (FIN-TYPE-001) — after the range lock.
        $this->rules->lockAccounts($request->lines, $accounts, $requireActiveAccounts);
        // 3c. The period again, share-locked until commit (P5 part 1): a year close committed
        //     since the check above refuses here — the number goes back with the rollback.
        $this->rules->assertPeriodAccepts($period, $request->hasTaxLine(), $this->rules->lockedPeriodState($period));

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
