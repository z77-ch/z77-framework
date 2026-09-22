<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * One block of a statement (Aktiven, Fremdkapital, Eigenkapital, Ertrag,
 * Aufwand): the accounts of its types with lines in the range, under their
 * groups in chart order — a group without such an account is left out — and
 * the block's total. An account the tree does not reach is appended flat and
 * listed in `unplaced`.
 */
final class StatementSection
{
    /**
     * @param list<StatementLine> $lines
     * @param list<string> $unplaced numbers of accounts the chart tree does not reach (a cycle or an
     *                               orphaned group) — appended flat at the end of $lines, never dropped
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines,
        public readonly Money $total,
        public readonly array $unplaced = [],
    ) {}
}
