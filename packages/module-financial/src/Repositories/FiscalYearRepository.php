<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\Period;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see FiscalYear}. Both reads are DQL on `em()`
 * — a Doctrine-only seam (ADR-039 decision 8): `findBy()` has neither
 * ORDER BY nor LIMIT, and the list renders every year's periods, which
 * are fetch-joined instead of loaded per row.
 */
class FiscalYearRepository extends DoctrineRepository
{
    /**
     * Every fiscal year, newest first, with its periods fetch-joined (one
     * query for the list screen). Doctrine-only (DQL).
     *
     * @return list<FiscalYear>
     */
    public function allWithPeriods(): array
    {
        return $this->em()->createQueryBuilder()
            ->select('y', 'p')
            ->from(FiscalYear::class, 'y')
            ->leftJoin('y.periods', 'p')
            ->orderBy('y.startDate', 'DESC')
            ->addOrderBy('p.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The year a date falls into, or null when no year covers it — the
     * ledger's first question for every posting (`PostingRules::yearFor()`).
     * Years are contiguous and never overlap (`FiscalYearValidator`), so at
     * most one row matches. Doctrine-only (DQL).
     */
    public function findByDate(\DateTimeImmutable $date): ?FiscalYear
    {
        return $this->em()->createQueryBuilder()
            ->select('y')
            ->from(FiscalYear::class, 'y')
            ->where('y.startDate <= :day AND y.endDate >= :day')
            ->setParameter('day', $date->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The year a screen opens on when none is asked for: the year containing
     * today, else the latest one; null without any year. The ONE rule the
     * journal screen and the reports share.
     */
    public function currentOrLatest(): ?FiscalYear
    {
        return $this->findByDate(new \DateTimeImmutable('today')) ?? $this->latest();
    }

    /**
     * Locks EVERY fiscal-year row (`SELECT … FOR UPDATE`, a handful of rows)
     * until the caller's commit — what serialises opening and deleting a
     * year: `FiscalYearService::open()` re-checks contiguity and `delete()`
     * re-checks «latest» under this lock, so a year cannot be opened after a
     * year that is being deleted, nor deleted while its successor is being
     * opened. Take it AFTER the `NumberRange` lock (lock order,
     * DOCTRINE-NR-002). Refused outside a unit of work. Doctrine-only (SQL).
     *
     * Answers the locked rows (id, start and end date as `Y-m-d`, in date
     * order) — a LOCKING read, so a caller that needs the years' order can
     * take it from here without a plain read: the year close derives the
     * neighbours from these rows, so its REPEATABLE READ snapshot starts only
     * after every lock is held (its close check then sees everything
     * committed before the locks).
     *
     * @return list<array{id: int, start_date: string, end_date: string}>
     * @throws \LogicException outside an open transaction
     */
    public function lockAll(): array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockAll() needs an open unit of work — the row locks hold until commit');
        }
        $rows = $this->connection()->fetchAllAssociative('SELECT id, start_date, end_date FROM fiscal_year ORDER BY start_date FOR UPDATE');

        return array_map(static fn(array $r) => ['id' => (int) $r['id'], 'start_date' => (string) $r['start_date'], 'end_date' => (string) $r['end_date']], $rows);
    }

    /** The year that ends last — the one a new year must follow — or null for an empty table. Doctrine-only (DQL). */
    public function latest(): ?FiscalYear
    {
        return $this->em()->createQueryBuilder()
            ->select('y')
            ->from(FiscalYear::class, 'y')
            ->orderBy('y.endDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The year right BEFORE $year — the one ending the day before it starts
     * (years are contiguous, `FiscalYearValidator`) — or null for the
     * earliest year. What the close order asks (owner 2026-09-30: a year is
     * closed only after its predecessor). Doctrine-only (DQL).
     */
    public function predecessorOf(FiscalYear $year): ?FiscalYear
    {
        return $this->em()->createQueryBuilder()
            ->select('y')
            ->from(FiscalYear::class, 'y')
            ->where('y.endDate < :start')
            ->setParameter('start', $year->getStartDate()?->format('Y-m-d'))
            ->orderBy('y.endDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The year right AFTER $year, or null for the latest — what the reopen order asks. Doctrine-only (DQL). */
    public function successorOf(FiscalYear $year): ?FiscalYear
    {
        return $this->em()->createQueryBuilder()
            ->select('y')
            ->from(FiscalYear::class, 'y')
            ->where('y.startDate > :end')
            ->setParameter('end', $year->getEndDate()?->format('Y-m-d'))
            ->orderBy('y.startDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The periods of the given years, RE-READ under an exclusive row lock
     * (`SELECT … FOR UPDATE`, held until the caller's commit) and REFRESHED
     * in the identity map — so the states the close service decides on and
     * changes are the committed ones, not what an earlier read of this
     * request left in memory. A posting share-locks the period it posts
     * into (`PostingRules::lockedPeriodState()`), so a close waits for a
     * posting in flight, and a posting after the close sees `closed`.
     * Take it AFTER {@see lockAll()} (lock order: fiscal-year rows, then
     * periods — `delete()` removes periods under the same order). Refused
     * outside a unit of work. Doctrine-only (DQL).
     *
     * @param list<int> $yearIds
     * @return list<Period> by year, in date order
     * @throws \LogicException outside an open transaction
     */
    public function lockPeriodsOf(array $yearIds): array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockPeriodsOf() needs an open unit of work — the row locks hold until commit');
        }
        if ($yearIds === []) {
            return [];
        }

        return $this->em()->createQueryBuilder()
            ->select('p')
            ->from(Period::class, 'p')
            ->where('IDENTITY(p.fiscalYear) IN (:years)')
            ->setParameter('years', array_values($yearIds))
            ->orderBy('p.startDate', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * One period's committed state under a SHARED row lock (`LOCK IN SHARE
     * MODE`, until the caller's commit) — see
     * `PostingRules::lockedPeriodState()`, the one caller. Doctrine-only (SQL).
     *
     * FIRST the period's fiscal-year row, share-locked, THEN the period:
     * the order a close takes them exclusively (`lockAll()` → `lockPeriodsOf()`).
     * Without the year lock a posting held the period (S) and took the year
     * (S) only at its flush — through the foreign key of `journal_entry` —,
     * while a close held the year (X) and waited for the period (X): a
     * deadlock (review 2026-09-30). Year before period on both sides, so the
     * second one waits at the year and no cycle forms.
     *
     * @throws \LogicException outside an open transaction, or the row is gone
     */
    public function lockedPeriodState(int $yearId, int $periodId): string
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockedPeriodState() needs an open unit of work — the row lock holds until commit');
        }
        $this->connection()->fetchOne('SELECT id FROM fiscal_year WHERE id = ? LOCK IN SHARE MODE', [$yearId]);
        $state = $this->connection()->fetchOne('SELECT state FROM fiscal_period WHERE id = ? LOCK IN SHARE MODE', [$periodId]);
        if ($state === false) {
            throw new \LogicException("Period #{$periodId} does not exist any more");
        }

        return (string) $state;
    }

    /** The year that starts first — the one a PRIOR year must precede (owner 2026-09-29) — or null for an empty table. Doctrine-only (DQL). */
    public function earliest(): ?FiscalYear
    {
        return $this->em()->createQueryBuilder()
            ->select('y')
            ->from(FiscalYear::class, 'y')
            ->orderBy('y.startDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
