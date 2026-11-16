<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorInvoiceText;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/** Convention repository for {@see CreditorInvoiceText}: the text layer of one invoice. */
class CreditorInvoiceTextRepository extends DoctrineRepository
{
    public function forInvoice(int $invoiceId): ?CreditorInvoiceText
    {
        return $invoiceId > 0 ? $this->findOneBy(['invoiceId' => $invoiceId]) : null;
    }
}
