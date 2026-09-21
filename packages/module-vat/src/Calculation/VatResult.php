<?php

namespace Z77\Module\Vat\Calculation;

use Z77\Shared\Money\Money;

/**
 * What {@see VatCalculator::calculate()} returns for one document: the lines
 * with their resolved rates (in input order) and the {@see TaxSummary}. The
 * caller stores both as its snapshot; nothing here is recomputed later.
 *
 * Totals are net / tax / gross of the whole document. Rounding the gross total
 * to 0.05 is NOT done here — that is a document concern (debtor adds a
 * rounding line, ADR-041 decision 5).
 */
final class VatResult
{
    /** @param list<ResolvedLine> $lines */
    public function __construct(
        public readonly string $currency,
        public readonly \DateTimeImmutable $serviceDate,
        public readonly PriceMode $priceMode,
        public readonly array $lines,
        public readonly TaxSummary $summary,
    ) {}

    public function net(): Money
    {
        return $this->summary->base();
    }

    public function tax(): Money
    {
        return $this->summary->tax();
    }

    public function gross(): Money
    {
        return $this->summary->gross();
    }
}
