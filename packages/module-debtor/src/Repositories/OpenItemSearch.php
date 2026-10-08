<?php

namespace Z77\Module\Debtor\Repositories;

/**
 * What the open-item list asks the database for (2026-10-07, the wdv-630
 * «Debitoren» list): one VIEW over the FINAL payable documents (invoices and
 * fees) — the open ones, the overdue ones (open and past due as of `$today`),
 * or all — and the column criteria, AND-ed: the document number and the
 * customer number exact, the addressee's name as a part, the invoiced and
 * the open amount exact, the invoice date and the due date as ranges. Every
 * value is bound; the sort key selects from a fixed map
 * ({@see InvoiceRepository::openItems()}), never from input. Built by the
 * screen's listing (`Ui\OpenItemListing`), plain data.
 */
final class OpenItemSearch
{
    public const VIEW_OPEN    = 'open';
    public const VIEW_OVERDUE = 'overdue';
    public const VIEW_ALL     = 'all';

    public const VIEWS = [self::VIEW_OPEN, self::VIEW_OVERDUE, self::VIEW_ALL];
    public const SORTS = ['due', 'number', 'date', 'name', 'customer', 'gross', 'open'];

    public function __construct(
        public readonly \DateTimeImmutable $today,
        public readonly string $view = self::VIEW_OPEN,
        public readonly ?int $number = null,
        public readonly ?int $customer = null,
        public readonly ?string $name = null,       // part of the addressee's name
        public readonly ?string $gross = null,      // the gross total, a decimal string
        public readonly ?string $open = null,       // the open amount, a decimal string
        public readonly ?string $dateFrom = null,   // Y-m-d, inclusive
        public readonly ?string $dateTo = null,
        public readonly ?string $dueFrom = null,
        public readonly ?string $dueTo = null,
        public readonly string $sort = 'due',
        public readonly bool $descending = false,
    ) {
        if (!in_array($view, self::VIEWS, true) || !in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown open-item view '{$view}' or sort '{$sort}'");
        }
    }
}
