<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see CreditorInvoiceLine}: the lines of one invoice, or of a list page
 * in ONE query. Doctrine-only (DQL).
 */
class CreditorInvoiceLineRepository extends DoctrineRepository
{
    /** @return list<CreditorInvoiceLine> the lines of one invoice in position order */
    public function forInvoice(CreditorInvoice $invoice): array
    {
        return $this->forInvoices([(int) $invoice->getId()])[(int) $invoice->getId()] ?? [];
    }

    /**
     * @param list<int> $invoiceIds
     * @return array<int, list<CreditorInvoiceLine>> invoice id → its lines in position order
     */
    public function forInvoices(array $invoiceIds): array
    {
        $invoiceIds = array_values(array_filter(array_map('intval', $invoiceIds), static fn(int $id) => $id > 0));
        if ($invoiceIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->em()->createQueryBuilder()->select('l')->from(CreditorInvoiceLine::class, 'l')
                     ->where('IDENTITY(l.invoice) IN (:ids)')->setParameter('ids', $invoiceIds)
                     ->orderBy('l.position', 'ASC')->addOrderBy('l.id', 'ASC')->getQuery()->getResult() as $line) {
            $out[(int) $line->getInvoice()->getId()][] = $line;
        }

        return $out;
    }
}
