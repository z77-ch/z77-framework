<?php

namespace Z77\Module\Vat\Calculation;

/**
 * A line with the rate that applies to it. Deliberately NO tax amount per
 * line: tax is computed on the sum per code and rounded once (ADR-041
 * decision 5) — a per-line tax, summed, produces the Rappen differences wdv
 * smeared onto the last rate. A document prints the rate on the line and the
 * tax in the summary.
 */
final class ResolvedLine
{
    public function __construct(
        public readonly VatLine $line,
        public readonly ResolvedRate $rate,
    ) {}
}
