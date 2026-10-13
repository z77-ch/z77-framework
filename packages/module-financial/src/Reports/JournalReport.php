<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Paging\Paging;

use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Shared\Money\Money;

/**
 * The journal (plan §5.5) — the entries of a range in date and number order
 * with their lines, ONE PAGE at a time on the screen, or all of them as a lazy
 * chunked read for the PDF (`LedgerReports::journalForPdf()` — read once); the count (`paging->total`),
 * Σ debit and Σ credit (each its own SQL sum) cover the whole range.
 */
final class JournalReport
{
    /** @param iterable<JournalEntry> $entries lines loaded — a list (a screen page) or a generator (the PDF, read once) */
    public function __construct(
        public readonly iterable $entries,
        public readonly Money $totalDebit,
        public readonly Money $totalCredit,
        public readonly Paging $paging,
    ) {}
}
