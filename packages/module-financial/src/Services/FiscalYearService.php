<?php

namespace Z77\Module\Financial\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\Period;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\JournalEntryRepository;
use Z77\Module\Financial\Validators\FiscalYearValidator;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Opening a fiscal year (plan §5.1, owner decisions 2026-09-22): validate
 * it, derive its monthly periods, create its journal-entry number range —
 * as ONE unit of work.
 *
 * Why the transaction port (ADR-039 decision 10) and not a plain flush:
 * the range row is SQL on the driver's connection
 * (`NumberRangeRepository::create()`), the year and its periods are ORM
 * writes, and a year without its range — or a range without its year —
 * must not exist. The range is created FIRST (lock order: `NumberRange`
 * first, always) and committed with the year, so no one ever draws the
 * first number of a range that is created under contention
 * (DOCTRINE-NR-003).
 *
 * A year is never edited. It is DELETED only while it is the latest year
 * and nothing was ever posted in it ({@see delete()}, owner decision
 * 2026-09-22, FIN-FY-002) — the correction of a year opened with wrong
 * dates. The periods' state moves in P5; here they are created `open`.
 */
final class FiscalYearService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * The code proposed for a year from its dates: `2026` for a year inside
     * one calendar year, `2026-27` for one that crosses into the next (or,
     * for an extended year, the one after). The caller may choose another —
     * two years can start in the same calendar year.
     */
    public static function proposeCode(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $from = $start->format('Y');
        $to   = $end->format('Y');

        return $from === $to ? $from : $from . '-' . substr($to, -2);
    }

    /**
     * The year the opening form proposes: the day after the latest year ends
     * (or 1 January of the current year for the first one), twelve months
     * long, code proposed from the dates. Not persisted.
     */
    public function proposeNext(): FiscalYear
    {
        $latest = $this->years()->latest();
        $start  = $latest !== null
            ? $latest->getEndDate()->modify('+1 day')
            : new \DateTimeImmutable('first day of january this year');
        $end    = $start->modify('+1 year')->modify('-1 day');

        return new FiscalYear(self::proposeCode($start, $end), $start, $end);
    }

    /**
     * Open a NEW fiscal year: validate, derive one period per calendar month
     * clipped to the year's bounds, then in one unit of work create the range
     * `journal-entry.{code}` at 0 and persist the year with its periods.
     *
     * The range must be NEW: `NumberRangeRepository::create()` answers false
     * for a row that already exists (a leftover of a removed year, an
     * import), and continuing that sequence would start the year's entries
     * at its old `last_number` + 1 without anyone noticing. That is refused as
     * a `code` field error; the unit of work rolls back, nothing is left.
     *
     * After the range, every fiscal-year row is locked and the year is
     * validated AGAIN (`FiscalYearRepository::lockAll()`): {@see delete()}
     * takes the same lock, so a predecessor deleted between the first
     * validation and this commit is seen, and the opening is refused on
     * `start_date` instead of leaving a gap. Two openings of the same next
     * year on a NON-empty table are serialised by the lock: the second one
     * always fails the re-validation. On an EMPTY table both hold only a gap
     * lock and their inserts deadlock; the loser rolls back with a deadlock
     * error (FIN-FY-003 — before this lock both committed and overlapped).
     *
     * Owns its unit of work and REFUSES to run inside an open one: the flush
     * — and with it a unique-index race — would then happen at the caller's
     * commit, where this method can no longer turn it into a field error.
     * The production caller is the backend controller, which holds none.
     *
     * @throws InvalidFiscalYearException carries the validator and the year
     * @throws \LogicException the year exists already or has periods, or a unit of work is open
     */
    public function open(FiscalYear $year): void
    {
        if ($year->getId() !== null || $year->getPeriods() !== []) {
            throw new \LogicException('open() takes a NEW fiscal year without periods — they are derived here');
        }
        $transaction = $this->em->getTransaction(FiscalYear::class);
        if ($transaction->isOpen()) {
            throw new \LogicException('open() owns its unit of work — call it outside getTransaction()->run(), so a unique-index race can be reported as a field error');
        }
        $validator = new FiscalYearValidator($year, $this->years());
        if (!$validator->isValid()) {
            throw new InvalidFiscalYearException($validator, $year);
        }

        foreach (self::monthlyBounds($year->getStartDate(), $year->getEndDate()) as [$start, $end]) {
            $year->addPeriod(new Period($year, $start, $end));
        }

        try {
            $transaction->run(function () use ($year): void {
                /** @var NumberRangeRepository $ranges */
                $ranges = $this->em->getRepository(NumberRange::class);
                if (!$ranges->create($year->journalEntryRange())) {
                    $validator = new FiscalYearValidator($year);
                    $validator->flagFieldError('code', 'Den Nummernkreis «' . $year->journalEntryRange()
                        . '» gibt es bereits — ein anderes Kürzel wählen.');
                    throw new InvalidFiscalYearException($validator, $year);   // rolls the unit of work back
                }
                // Contiguity again, under the lock `delete()` takes as well: a
                // predecessor deleted since the validation above must not get
                // a successor (FIN-FY-002). The lock comes after the range's.
                $this->years()->lockAll();
                $validator = new FiscalYearValidator($year, $this->years());
                if (!$validator->isValid()) {
                    throw new InvalidFiscalYearException($validator, $year);
                }
                $this->em->persist($year);
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->refuseRace($e, $year);
        }
    }

    /**
     * Why a year cannot be deleted, or null when it can — what the screen asks
     * to show the delete button and the confirmation. A READ without locks:
     * {@see delete()} decides again under the lock. Not checked here: the
     * range (a number drawn and committed without an entry or a change row
     * does not happen through the ledger; `delete()` refuses it anyway).
     *
     * @return ?string a {@see FiscalYearNotDeletableException} reason
     */
    public function deletionRefusal(FiscalYear $year): ?string
    {
        return $this->refusalOf($year, $this->years()->latest());
    }

    /**
     * Delete a wrongly opened fiscal year (owner decision 2026-09-22,
     * FIN-FY-002): the year, its periods and its range `journal-entry.{code}`
     * in ONE unit of work. Allowed only for the LATEST year (a middle one
     * would break contiguity) and only while nothing was ever posted in it:
     * no journal entry, no change row of a deleted manual entry (that number
     * was consumed, and its gap history must not be orphaned), and the range
     * still at `last_number = 0`.
     *
     * Everything is decided INSIDE the unit of work, under locks, so a
     * posting cannot slip in between: first the range row
     * (`NumberRangeRepository::dropUnused()` — lock order: `NumberRange`
     * FIRST, DOCTRINE-NR-002; a poster of this year either committed before,
     * and the range is no longer at 0, or waits; after this commit its
     * `next()` re-creates the range at 0 and draws 1, its `journal_entry`
     * insert then fails on the foreign key to the deleted year, and its
     * rollback removes the re-created range row again — nothing is written),
     * then every fiscal-year row (`lockAll()`, against an opening of the next
     * year racing the delete), then the checks. A refusal rolls
     * back the whole unit of work — the range row is back as it was.
     *
     * Takes the id (the confirmation modal carries it), not an entity; owns
     * its unit of work and refuses to run inside an open one, like
     * {@see open()}. The code is read before the unit of work only to name
     * the range — it is immutable.
     *
     * @throws FiscalYearNotDeletableException
     * @throws \LogicException a unit of work is open
     */
    public function delete(int $yearId): void
    {
        $transaction = $this->em->getTransaction(FiscalYear::class);
        if ($transaction->isOpen()) {
            throw new \LogicException('delete() owns its unit of work — call it outside getTransaction()->run(), so the locks it takes hold until its own commit');
        }
        $known = $this->years()->find($yearId);
        if ($known === null) {
            throw new FiscalYearNotDeletableException(FiscalYearNotDeletableException::NOT_FOUND, "Fiscal year #{$yearId} does not exist");
        }
        $rangeName = $known->journalEntryRange();

        $transaction->run(function () use ($yearId, $rangeName): void {
            /** @var NumberRangeRepository $ranges */
            $ranges = $this->em->getRepository(NumberRange::class);
            $unused = $ranges->dropUnused($rangeName);                  // FIRST: the range lock
            $this->years()->lockAll();

            $year = $this->years()->findOneBy(['id' => $yearId]);      // a query, not the identity map
            if ($year === null) {
                throw new FiscalYearNotDeletableException(FiscalYearNotDeletableException::NOT_FOUND, "Fiscal year #{$yearId} was deleted in the meantime");
            }
            $refusal = $this->refusalOf($year, $this->years()->latest());
            if ($refusal === null && !$unused) {
                $refusal = FiscalYearNotDeletableException::RANGE_USED;
            }
            if ($refusal !== null) {
                throw new FiscalYearNotDeletableException($refusal, 'Fiscal year ' . $year->getCode() . ' cannot be deleted: ' . $refusal);
            }

            foreach ($year->getPeriods() as $period) {
                $this->em->remove($period);
            }
            $this->em->remove($year);
        });
    }

    /** The first reason that forbids deleting $year, or null; $latest = the year that ends last. */
    private function refusalOf(FiscalYear $year, ?FiscalYear $latest): ?string
    {
        if ($latest === null || $latest->getId() !== $year->getId()) {
            return FiscalYearNotDeletableException::NOT_LATEST;
        }
        /** @var JournalEntryRepository $entries */
        $entries = $this->em->getRepository(JournalEntry::class);
        if ($entries->countForYear($year) > 0) {
            return FiscalYearNotDeletableException::HAS_ENTRIES;
        }
        if ($this->em->getRepository(EntryChange::class)->findOneBy(['fiscalYearId' => (int) $year->getId()]) !== null) {   // existence only — findOneBy() reads with LIMIT 1, nothing hydrated beyond one row
            return FiscalYearNotDeletableException::HAD_ENTRIES;
        }

        return null;
    }

    /**
     * Calendar months from $start to $end, the first and the last clipped:
     * 15.3.–31.12. → 15.3.–31.3., April, …, December.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private static function monthlyBounds(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $bounds = [];
        for ($from = $start; $from <= $end; $from = $to->modify('+1 day')) {
            $monthEnd = $from->modify('last day of this month');
            $to       = $monthEnd < $end ? $monthEnd : $end;
            $bounds[] = [$from, $to];
        }

        return $bounds;
    }

    /**
     * Two openings of the same next year can both pass the contiguity check;
     * the unique start date (or code) lets only one commit. The loser gets
     * the field error the validator would have given. The year is detached
     * after the rollback (DOCTRINE-TX-004) and serves the form only.
     *
     * @throws InvalidFiscalYearException|UniqueConstraintViolationException
     */
    private function refuseRace(UniqueConstraintViolationException $e, FiscalYear $year): never
    {
        $validator = new FiscalYearValidator($year);
        if (str_contains($e->getMessage(), FiscalYear::UNIQUE_START)) {
            $validator->flagFieldError('start_date', 'Ein Geschäftsjahr mit diesem Beginn wurde soeben eröffnet — Liste neu laden.');
        } elseif (str_contains($e->getMessage(), FiscalYear::UNIQUE_CODE)) {
            $validator->flagFieldError('code', 'Das Kürzel «' . $year->getCode() . '» wurde soeben vergeben — Liste neu laden.');
        } else {
            throw $e;
        }
        throw new InvalidFiscalYearException($validator, $year);
    }

    private function years(): FiscalYearRepository
    {
        return $this->em->getRepository(FiscalYear::class);
    }
}
