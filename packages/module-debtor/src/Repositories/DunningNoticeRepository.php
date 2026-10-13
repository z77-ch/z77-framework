<?php

namespace Z77\Module\Debtor\Repositories;

use Doctrine\DBAL\ArrayParameterType;
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
     * The highest level number reached and the date of the LAST notice
     * (its run's date) per invoice, for every invoice that was ever dunned
     * — or for $invoiceIds only: invoice id → `level`, `date`. One query
     * for the due list (DEBTOR-OPEN-001: never one call per row). The run
     * calls it after it locked the invoice rows: every writer of a notice
     * holds the invoice row, so a read after the locks sees them all.
     * Doctrine-only (SQL).
     *
     * @param list<int>|null $invoiceIds
     * @return array<int, array{level: int, date: \DateTimeImmutable}>
     */
    public function lastNoticePerInvoice(?array $invoiceIds = null): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $sql    = 'SELECT n.invoice_id, MAX(n.level_number) AS level, MAX(r.run_date) AS last_date FROM dunning_notice n JOIN dunning_run r ON r.id = n.run_id';
        $params = [];
        $types  = [];
        if ($invoiceIds !== null) {
            $sql     .= ' WHERE n.invoice_id IN (?)';
            $params[] = array_values(array_map('intval', $invoiceIds));
            $types[]  = ArrayParameterType::INTEGER;
        }
        $last = [];
        foreach ($this->connection()->fetchAllAssociative($sql . ' GROUP BY n.invoice_id', $params, $types) as $row) {
            $last[(int) $row['invoice_id']] = ['level' => (int) $row['level'], 'date' => new \DateTimeImmutable((string) $row['last_date'])];
        }

        return $last;
    }

    /** The notice date of the latest run, or null before the first — the earliest date a new run may carry (owner 2026-10-13). Doctrine-only (SQL). */
    public function latestRunDate(): ?\DateTimeImmutable
    {
        $date = $this->connection()->fetchOne('SELECT MAX(run_date) FROM dunning_run');

        return $date === false || $date === null ? null : new \DateTimeImmutable((string) $date);
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
