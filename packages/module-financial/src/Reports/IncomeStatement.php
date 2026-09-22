<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * The income statement (Erfolgsrechnung) over a range (plan §5.5): Ertrag
 * (type `revenue`) and Aufwand (`expense`), each under the chart's groups;
 * the result is revenue − expense — positive a profit, negative a loss. A
 * KMU group that mixes both types (group 69 with 6950 Finanzertrag) appears
 * in both blocks, each time with the accounts of that block only: the TYPE
 * decides the side, not the number.
 */
final class IncomeStatement
{
    public function __construct(
        public readonly StatementSection $revenue,
        public readonly StatementSection $expense,
    ) {}

    public function result(): Money
    {
        return $this->revenue->total->subtract($this->expense->total);
    }
}
