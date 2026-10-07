<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Module\Debtor\Entities\DunningRun;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see DunningNotice} — the history per invoice
 * and the current level of every dunned invoice in ONE query (the due list
 * walks all open invoices). Doctrine-only (ADR-039 decision 8).
 */
class DunningNoticeRepository extends DoctrineRepository
{
    /**
     * The notices of $invoice with their runs, oldest first — the invoice
     * detail's «Mahnungen» rows.
     *
     * @return list<DunningNotice>
     */
    public function forInvoice(Invoice $invoice): array
    {
        return $this->em()->createQueryBuilder()
            ->select('n', 'r', 'f')
            ->from(DunningNotice::class, 'n')
            ->join('n.run', 'r')
            ->leftJoin('n.feeInvoice', 'f')
            ->where('n.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('r.runDate', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The highest level number reached per invoice, for every invoice that
     * was ever dunned: invoice id → level number. One query for the due
     * list (DEBTOR-OPEN-001: sum per row, never one call per row).
     *
     * @return array<int, int>
     */
    public function highestLevelPerInvoice(): array
    {
        $rows = $this->connection()->fetchAllKeyValue('SELECT invoice_id, MAX(level_number) FROM dunning_notice GROUP BY invoice_id');

        return array_map('intval', $rows);
    }

    /**
     * The runs, newest first, with their notices, invoices and fee documents
     * fetch-joined — the history on the dunning screen.
     *
     * @return list<DunningRun>
     */
    public function runsNewestFirst(int $limit): array
    {
        $runs = $this->em()->createQueryBuilder()
            ->select('r')
            ->from(DunningRun::class, 'r')
            ->orderBy('r.runDate', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        if ($runs !== []) {
            // One query for every notice of the page (the collections are then initialized).
            $this->em()->createQueryBuilder()
                ->select('n', 'i', 'f')
                ->from(DunningNotice::class, 'n')
                ->join('n.invoice', 'i')
                ->leftJoin('n.feeInvoice', 'f')
                ->where('n.run IN (:runs)')
                ->setParameter('runs', $runs)
                ->getQuery()
                ->getResult();
        }

        return $runs;
    }
}
