<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorInvoice;

/**
 * What the «Kreditorenjournal» list asks the database for: the invoices in ONE state (the tab —
 * open, blocked) and the column criteria, AND-ed. Every value is bound; the sort key selects from a
 * fixed map ({@see CreditorInvoiceRepository::search()}), never from input. Built by the screen's
 * listing (`Ui\CreditorJournalListing`, listing.md), plain data.
 */
final class CreditorInvoiceSearch
{
    public const SORTS = ['date', 'amount', 'received'];

    public function __construct(
        public readonly string $state = CreditorInvoice::STATE_OPEN,
        public readonly ?string $dateFrom = null,    // invoice date, Y-m-d, inclusive
        public readonly ?string $dateTo = null,      // invoice date, Y-m-d, inclusive
        public readonly ?string $creditor = null,    // part of the creditor's name (or of the proposed name)
        public readonly ?string $number = null,      // part of the invoice number
        public readonly ?string $amount = null,      // the gross amount, a decimal string
        public readonly string $sort = 'date',
        public readonly bool $descending = false,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown creditor invoice sort '{$sort}'");
        }
        if (!in_array($state, [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED, CreditorInvoice::STATE_POSTED], true)) {
            throw new \InvalidArgumentException("Unknown creditor invoice state '{$state}'");
        }
    }
}
