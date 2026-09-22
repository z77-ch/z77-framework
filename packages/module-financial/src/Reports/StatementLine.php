<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * A row of the balance sheet or the income statement: a GROUP of the chart
 * with its subtotal, or an account with its balance — both on the natural
 * side of the section they stand in. `depth` is the position in the parent
 * chain (0 = a class), for the indentation.
 */
final class StatementLine
{
    public function __construct(
        public readonly string $number,
        public readonly string $name,
        public readonly int $depth,
        public readonly bool $isGroup,
        public readonly Money $amount,
    ) {}
}
