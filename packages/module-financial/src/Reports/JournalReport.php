<?php

namespace Z77\Module\Financial\Reports;

use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Shared\Money\Money;

/**
 * The journal (plan §5.5) — the entries of a range in date and number order
 * with their lines, ONE PAGE at a time; the count (`paging->total`),
 * Σ debit and Σ credit (each its own SQL sum) cover the whole range.
 */
final class JournalReport
{
    /** @param list<JournalEntry> $entries lines loaded */
    public function __construct(
        public readonly array $entries,
        public readonly Money $totalDebit,
        public readonly Money $totalCredit,
        public readonly Paging $paging,
    ) {}
}
