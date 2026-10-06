<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see PaymentAllocation} — the two readings
 * the open amount and the document detail need. Doctrine-only (ADR-039
 * decision 8): DQL aggregates and a fetch-join.
 */
class PaymentAllocationRepository extends DoctrineRepository
{
    /**
     * Σ of every allocation (all kinds) on $invoice, as a decimal string in
     * the invoice's currency — what `InvoicingService::openAmount()`
     * subtracts. '0.00' when there is none.
     */
    public function sumAllocated(Invoice $invoice): string
    {
        if ($invoice->getId() === null) {
            return '0.00';
        }
        $sum = $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0.00) FROM payment_allocation WHERE invoice_id = ?',
            [$invoice->getId()]
        );

        return (string) $sum;
    }

    /** Σ of the allocations of ONE payment — what a correction of that payment gives back to the open amount before it is re-applied. */
    public function sumAllocatedBy(int $paymentId): string
    {
        $sum = $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0.00) FROM payment_allocation WHERE payment_id = ?',
            [$paymentId]
        );

        return (string) $sum;
    }

    /**
     * The allocations on $invoice with their payments, oldest first — the
     * document detail's «Zahlungen» rows.
     *
     * @return list<PaymentAllocation>
     */
    public function forInvoice(Invoice $invoice): array
    {
        return $this->em()->createQueryBuilder()
            ->select('a', 'p')
            ->from(PaymentAllocation::class, 'a')
            ->join('a.payment', 'p')
            ->where('a.invoice = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('p.date', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
