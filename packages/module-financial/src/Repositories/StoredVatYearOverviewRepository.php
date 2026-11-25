<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\StoredVatYearOverview;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see StoredVatYearOverview} — the storings of the yearly overview of the
 * VAT return (owner B-5 of 2026-11-25: every stored PDF stays, the NEWEST counts). Reads only; the
 * one writer is `VatReturn\YearOverviewStore`. Doctrine-only (DQL).
 */
class StoredVatYearOverviewRepository extends DoctrineRepository
{
    /** The newest storing of $taxYear — what «Seit der Ablage geändert» compares with — or null. */
    public function newest(int $taxYear): ?StoredVatYearOverview
    {
        $rows = $this->em()->createQueryBuilder()
            ->select('o')
            ->from(StoredVatYearOverview::class, 'o')
            ->where('o.taxYear = :year')
            ->setParameter('year', $taxYear)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $rows[0] ?? null;
    }

    /**
     * The storings with the given ids, by id — the protocol «Abschlüsse und MWST» reads a page's rows in
     * one query.
     *
     * @param list<int> $ids
     * @return array<int, StoredVatYearOverview>
     */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()
            ->select('o')
            ->from(StoredVatYearOverview::class, 'o')
            ->where('o.id IN (:ids)')
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->getId()] = $row;
        }

        return $byId;
    }
}
