<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see FiscalYearCloseLog} — the protocol of
 * closing and reopening fiscal years (owner 2026-09-30). One read: the
 * fiscal-year list shows the latest rows per year.
 */
class FiscalYearCloseLogRepository extends DoctrineRepository
{
    /**
     * The protocol of the given years, newest first, grouped by year id. A
     * year collects a handful of rows over its life (each close and reopen
     * is one), so every row of the listed years is read — no per-year
     * limit in SQL. Doctrine-only (DQL: `findBy()` has no ORDER BY).
     *
     * @param list<int> $yearIds
     * @return array<int, list<FiscalYearCloseLog>> year id → rows, newest first
     */
    public function byYear(array $yearIds): array
    {
        if ($yearIds === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()
            ->select('l')
            ->from(FiscalYearCloseLog::class, 'l')
            ->where('l.fiscalYearId IN (:years)')
            ->setParameter('years', array_values($yearIds))
            ->orderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
        $byYear = [];
        foreach ($rows as $row) {
            $byYear[$row->getFiscalYearId()][] = $row;
        }

        return $byYear;
    }
}
