<?php

namespace Z77\Module\Debtor\Repositories;

/**
 * What the document list asks the database for (P3 part 3): one VIEW —
 * the invoices in `invoicing` (drafts, no number yet), the issued ones in
 * the Faktura-Journal, the final invoices, the credit notes (any standing state), the replaced or
 * the deleted documents (every kind — ADR-054 rule 4);
 * or the Faktura-Journal screen's every kind in `issued` — and the column
 * criteria, AND-ed. Every value is bound; the sort
 * key selects from a fixed map ({@see InvoiceRepository::search()}), never
 * from input. Built by the screen's listing (`Ui\InvoiceListing`, listing.md), plain data.
 */
final class InvoiceSearch
{
    public const VIEW_INVOICING = 'invoicing';
    /** Issued, not posted — the payable kinds in the Faktura-Journal (ADR-054, step 3). */
    public const VIEW_ISSUED    = 'issued';
    public const VIEW_FINAL     = 'final';
    public const VIEW_CREDIT    = 'credit';
    /** Void documents (ADR-054 rule 4, step 4): replaced by a successor with a new number / deleted — every kind, hidden from the other tabs. */
    public const VIEW_REPLACED  = 'replaced';
    public const VIEW_DELETED   = 'deleted';
    /** The tabs of the document list. */
    public const VIEWS = [self::VIEW_INVOICING, self::VIEW_ISSUED, self::VIEW_FINAL, self::VIEW_CREDIT, self::VIEW_REPLACED, self::VIEW_DELETED];
    /** The Faktura-Journal screen: EVERY kind in state `issued` (credit notes included) — not a tab of the document list. */
    public const VIEW_JOURNAL   = 'journal';
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
        if ((!in_array($view, self::VIEWS, true) && $view !== self::VIEW_JOURNAL) || !in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown document view '{$view}' or sort '{$sort}'");
        }
    }
}
