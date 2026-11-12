<?php

namespace Z77\Module\Receipt\Repositories;

/**
 * What the «Belegjournal» list asks the database for: the CAPTURED receipts (the open journal) and
 * the column criteria, AND-ed. Every value is bound; the sort key selects from a fixed map
 * ({@see ReceiptRepository::search()}), never from input. Built by the screen's listing
 * (`Ui\ReceiptJournalListing`, listing.md), plain data.
 */
final class ReceiptSearch
{
    public const SORTS = ['date', 'amount'];

    public function __construct(
        public readonly ?string $dateFrom = null,    // Y-m-d, inclusive
        public readonly ?string $dateTo = null,      // Y-m-d, inclusive
        public readonly ?string $text = null,        // part of the posting text
        public readonly ?string $amount = null,      // the gross amount, a decimal string
        public readonly ?string $capturedBy = null,  // part of who captured it or of the device name
        public readonly string $sort = 'date',
        public readonly bool $descending = false,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown receipt sort '{$sort}'");
        }
    }
}
