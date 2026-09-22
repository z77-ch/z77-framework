<?php

namespace Z77\Module\Financial\Services;

use Doctrine\ORM\OptimisticLockException;
use Z77\Module\Financial\Entities\ChangeAction;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Persistence\Interface\TransactionInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Manual entries — the bookkeeper's own work in financial (ADR-042
 * decision 7, owner 2026-09-22): created through `LedgerService::post()`
 * like every other posting, and — unlike generated entries — CHANGED and
 * DELETED here until the accounting close of their period, every change
 * written as an {@see EntryChange} in the same unit of work.
 *
 * The rules, for an edit and a delete alike:
 *
 *   - a GENERATED entry is refused — in the domain, not only in the UI
 *     (`JournalEntry::amend()` refuses as well);
 *   - period `open`: allowed;
 *   - `vat-settled`: allowed only if neither the old nor the new version
 *     carries a tax line (the filed return summed the tax lines);
 *   - `closed`: refused. Nothing changes after the close;
 *   - an edit that moves the date into ANOTHER fiscal year is refused: the
 *     number belongs to the year — delete and re-create instead;
 *   - an UNCHANGED line may keep its account although it was deactivated
 *     since; a new or changed line needs an active account (ADR-043
 *     decision 19, review M5);
 *   - a delete leaves a gap in the numbering, documented by its change row
 *     (decision 9).
 *
 * Concurrency (review 2026-09-22, H1/M1–M3 — two parallel edits doubled the
 * lines, a stale delete logged twice, a stale edit after a delete hit a raw
 * FK error): {@see update()} and {@see delete()} take the entry's ID and the
 * VERSION the caller saw (`JournalEntry::getVersion()`, `#[ORM\Version]`),
 * not a pre-loaded entity. Inside the unit of work the entry is RE-READ; a
 * missing row or another version is refused with
 * {@see EntryConflictException} before anything is checked or touched, and
 * the same exception is raised when Doctrine's optimistic lock fails at
 * flush because the other writer committed between the re-read and the
 * commit. Every rule then runs on the fresh entity, and only then is it
 * mutated (ADR-039 decision 9, DOCTRINE-TX-007). A manual edit always
 * bumps the version: `amend()` stamps `changed_at`, so the header row is
 * updated even when only the lines changed.
 *
 * Each method OWNS its unit of work and refuses to run inside an open one
 * (the `FiscalYearService::open()` model): the manual-entry screen is the
 * caller and holds none. A module that wants to post many manual entries in
 * one transaction (the import) uses `LedgerService::post()` with a manual
 * request directly.
 */
final class ManualEntryService
{
    private readonly PostingRules $rules;
    private readonly LedgerService $ledger;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) */
    public function __construct(private readonly UnifiedEntityManager $em, private readonly ?string $actor = null)
    {
        $this->rules  = new PostingRules($em);
        $this->ledger = new LedgerService($em, $actor);
    }

    /**
     * Post a NEW manual entry — `LedgerService::post()` inside a unit of work
     * opened here.
     *
     * @throws \LogicException            the request is not manual, or a unit of work is open
     * @throws PostingRefusedException    year / period / account / tax code refused
     */
    public function create(PostingRequest $request): EntryRef
    {
        if ($request->kind !== EntryKind::Manual) {
            throw new \LogicException('ManualEntryService::create() takes a manual PostingRequest — a module posts through LedgerService::post()');
        }
        $transaction = $this->ownUnitOfWork('create()');

        return $transaction->run(fn(): EntryRef => $this->ledger->post($request));
    }

    /**
     * Replace date, text and lines of the MANUAL entry $entryId — as the
     * caller saw it at $expectedVersion — with $new, logging before and
     * after. The number stays.
     *
     * @throws \LogicException              $new is not manual, or a unit of work is open
     * @throws EntryConflictException       the entry is gone or was changed in the meantime
     * @throws EntryNotEditableException    generated, closed, VAT-settled with a tax line, other fiscal year
     * @throws PostingRefusedException      an account or tax code of the new version is refused
     */
    public function update(int $entryId, int $expectedVersion, PostingRequest $new): void
    {
        if ($new->kind !== EntryKind::Manual) {
            throw new \LogicException('update() takes a manual PostingRequest as the new version');
        }
        $transaction = $this->ownUnitOfWork('update()');

        $this->runGuarded($transaction, $entryId, $expectedVersion, function () use ($entryId, $expectedVersion, $new): void {
            $entry = $this->freshEntry($entryId, $expectedVersion);
            $this->assertManual($entry, 'edited');

            $year    = $entry->getFiscalYear();
            $newYear = $this->rules->yearFor($new->date);
            if ($newYear->getId() !== $year->getId()) {
                throw new EntryNotEditableException(
                    EntryNotEditableException::FISCAL_YEAR_CHANGED,
                    "Journal entry {$year->getCode()}/{$entry->getNumber()}: the new date lies in fiscal year {$newYear->getCode()} — "
                    . 'the number belongs to its year; delete the entry and post it anew there'
                );
            }
            $withTax = $entry->hasTaxLine() || $new->hasTaxLine();
            $this->assertPeriodAllows($this->rules->periodFor($year, $entry->getDate()), $withTax, $entry);
            $this->assertPeriodAllows($this->rules->periodFor($year, $new->date), $withTax, $entry);
            $accounts = $this->rules->resolveAccounts($new->lines, self::requireActiveFor($entry, $new));
            $this->rules->assertTaxCodesExist($new->lines);
            $this->rules->lockAccounts($new->lines, $accounts, self::requireActiveFor($entry, $new));   // FIN-TYPE-001, last — as in post() (no number drawn here)

            // Everything passed on the fresh entity — only now is it touched.
            $now    = new \DateTimeImmutable();
            $actor  = $this->actorName();
            $before = $entry->snapshot();
            $entry->amend($new->date, $new->text, $this->rules->buildLines($entry, $new, $accounts), $actor, $now);
            $this->em->persist(new EntryChange($entry, ChangeAction::Update, $actor, $now, $before, $entry->snapshot()));
            $this->em->persist($entry);
        });
    }

    /**
     * Delete the MANUAL entry $entryId as seen at $expectedVersion, logging
     * what it was. Its number stays consumed — the gap is explained by the
     * change row (ADR-042 decision 9).
     *
     * @throws \LogicException              a unit of work is open
     * @throws EntryConflictException       the entry is gone or was changed in the meantime
     * @throws EntryNotEditableException    generated, closed, VAT-settled with a tax line
     */
    public function delete(int $entryId, int $expectedVersion): void
    {
        $transaction = $this->ownUnitOfWork('delete()');

        $this->runGuarded($transaction, $entryId, $expectedVersion, function () use ($entryId, $expectedVersion): void {
            $entry = $this->freshEntry($entryId, $expectedVersion);
            $this->assertManual($entry, 'deleted');
            $this->assertPeriodAllows($this->rules->periodFor($entry->getFiscalYear(), $entry->getDate()), $entry->hasTaxLine(), $entry);

            $this->em->persist(new EntryChange($entry, ChangeAction::Delete, $this->actorName(), new \DateTimeImmutable(), $entry->snapshot(), null));
            $this->em->remove($entry);
        });
    }

    /**
     * Which lines of the new version need an ACTIVE account: every line that
     * is not identical to a line of the stored entry (account, amounts, tax
     * data, text). An unchanged line keeps its account, deactivated or not.
     *
     * @return list<bool> by index of $new->lines
     */
    private static function requireActiveFor(JournalEntry $entry, PostingRequest $new): array
    {
        $kept = [];
        foreach ($entry->getLines() as $line) {
            $kept[self::lineKey($line->snapshot())] = true;
        }

        return array_map(static fn(PostingLine $line) => !isset($kept[self::lineKey($line->fingerprint())]), $new->lines);
    }

    /** A line's identity for the comparison — the fingerprint keys, position and account name left out. */
    private static function lineKey(array $snapshotOrFingerprint): string
    {
        $keys = ['account', 'debit', 'credit', 'tax_code', 'tax_rate', 'tax_base', 'tax_amount', 'text'];
        $row  = [];
        foreach ($keys as $key) {
            $row[$key] = $snapshotOrFingerprint[$key] ?? null;
        }

        return json_encode($row, JSON_THROW_ON_ERROR);
    }

    /**
     * The entry as it is NOW, inside the unit of work — header row LOCKED
     * (`JournalEntryRepository::lockForUpdate()`, so a parallel writer waits
     * and then sees our commit), lines loaded, so the snapshot and the rules
     * see the stored state, not a stale one. The version check catches what
     * happened BEFORE we locked; the optimistic lock at flush is the belt to
     * this brace.
     *
     * @throws EntryConflictException gone, or not at the version the caller saw
     */
    private function freshEntry(int $entryId, int $expectedVersion): JournalEntry
    {
        /** @var JournalEntryRepository $entries */
        $entries = $this->em->getRepository(JournalEntry::class);
        $entry   = $entries->lockForUpdate($entryId);
        if ($entry === null) {
            throw new EntryConflictException($entryId, $expectedVersion, null);
        }
        if ($entry->getVersion() !== $expectedVersion) {
            throw new EntryConflictException($entryId, $expectedVersion, $entry->getVersion());
        }

        return $entry;
    }

    /**
     * Runs the unit of work and maps Doctrine's optimistic-lock failure at
     * flush — the other writer committed between our re-read and our commit
     * — to the same domain exception the version check raises. The port has
     * rolled back and replaced the EntityManager by then; nothing was written.
     */
    private function runGuarded(TransactionInterface $transaction, int $entryId, int $expectedVersion, callable $unitOfWork): void
    {
        try {
            $transaction->run($unitOfWork);
        } catch (OptimisticLockException $e) {
            throw new EntryConflictException($entryId, $expectedVersion, null);
        }
    }

    /** @throws EntryNotEditableException GENERATED */
    private function assertManual(JournalEntry $entry, string $verb): void
    {
        if (!$entry->isManual()) {
            throw new EntryNotEditableException(
                EntryNotEditableException::GENERATED,
                "Journal entry {$entry->getFiscalYear()->getCode()}/{$entry->getNumber()} is generated — it is never {$verb}; "
                . 'the module that posted it corrects by a reversal (ADR-042 decision 7)'
            );
        }
    }

    /** @throws EntryNotEditableException PERIOD_CLOSED | PERIOD_VAT_SETTLED */
    private function assertPeriodAllows(Period $period, bool $withTaxLine, JournalEntry $entry): void
    {
        $span = $period->getStartDate()->format('d.m.Y') . '–' . $period->getEndDate()->format('d.m.Y');
        $ref  = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
        if ($period->getState() === PeriodState::Closed->value) {
            throw new EntryNotEditableException(EntryNotEditableException::PERIOD_CLOSED, "Journal entry {$ref}: period {$span} is closed — nothing changes after the close");
        }
        if ($period->getState() === PeriodState::VatSettled->value && $withTaxLine) {
            throw new EntryNotEditableException(EntryNotEditableException::PERIOD_VAT_SETTLED, "Journal entry {$ref}: period {$span} is VAT-settled — an entry with a tax line is frozen there");
        }
    }

    /** @throws \LogicException a unit of work is already open */
    private function ownUnitOfWork(string $method): TransactionInterface
    {
        $transaction = $this->em->getTransaction(JournalEntry::class);
        if ($transaction->isOpen()) {
            throw new \LogicException(
                "ManualEntryService::{$method} owns its unit of work — call it outside getTransaction()->run(). "
                . 'To post manual entries inside a larger unit of work use LedgerService::post() with PostingRequest::manual().'
            );
        }

        return $transaction;
    }

    private function actorName(): string
    {
        return $this->actor !== null ? Actor::normalize($this->actor) : Actor::current();
    }
}
