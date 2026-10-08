<?php

namespace Z77\Module\Debtor\Repositories;

/**
 * What the document list asks the database for (P3 part 3): one VIEW —
 * the invoices in `invoicing`, the final invoices, or the credit notes (any
 * state) — and the column criteria, AND-ed. Every value is bound; the sort
 * key selects from a fixed map ({@see InvoiceRepository::search()}), never
 * from input. Built by the screen's listing (`Ui\InvoiceListing`, listing.md), plain data.
 */
final class InvoiceSearch
{
    public const VIEW_INVOICING = 'invoicing';
    public const VIEW_FINAL     = 'final';
    public const VIEW_CREDIT    = 'credit';

    public const VIEWS = [self::VIEW_INVOICING, self::VIEW_FINAL, self::VIEW_CREDIT];
    public const SORTS = ['number', 'date', 'name', 'amount'];

    public function __construct(
        public readonly string $view = self::VIEW_INVOICING,
        public readonly ?int $number = null,
        public readonly ?string $dateFrom = null,   // Y-m-d, inclusive
        public readonly ?string $dateTo = null,     // Y-m-d, inclusive
        public readonly ?string $name = null,       // part of the addressee's name
        public readonly ?string $amount = null,     // the gross total, a decimal string
        public readonly string $sort = 'number',
        public readonly bool $descending = true,
    ) {
        if (!in_array($view, self::VIEWS, true) || !in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown document view '{$view}' or sort '{$sort}'");
        }
    }
}
