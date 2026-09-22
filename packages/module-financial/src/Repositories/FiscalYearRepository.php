<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\FiscalYear;
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
     * @throws \LogicException outside an open transaction
     */
    public function lockAll(): void
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockAll() needs an open unit of work — the row locks hold until commit');
        }
        $this->connection()->fetchFirstColumn('SELECT id FROM fiscal_year FOR UPDATE');
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
}
