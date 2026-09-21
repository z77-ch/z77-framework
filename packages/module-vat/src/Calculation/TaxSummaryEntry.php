<?php

namespace Z77\Module\Vat\Calculation;

use Z77\Shared\Money\Money;

/**
 * One row of the tax summary: per tax code the base (net sum of its lines),
 * the rate that applied and the tax on that base (ADR-041 decision 5). This is
 * the shape the invoice snapshots, the ledger posts per code (net method,
 * decision 7) and a discount corrects proportionally (decision 8).
 */
final class TaxSummaryEntry
{
    public function __construct(
        public readonly string $code,
        public readonly int $rate,
        public readonly Money $base,
        public readonly Money $tax,
    ) {}
}
