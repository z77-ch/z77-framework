<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * The coded journal lines of a settlement period summed per tax code, rate, ORIGIN and ACCOUNT —
 * the finest grain the read path needs: summed per code + category + rate + {@see RowOrigin} it
 * is the calculator's {@see VatReturnRow}; walked by field › display origin › account it is the
 * tree of the tab «Herkunft» ({@see VatReturnBreakdown}). Built by {@see VatReturnService} from
 * ONE aggregate query (`JournalLineRepository::vatReturnAggregate()`).
 *
 * `origin` the calculator's, `sourceKey` the display origin ({@see VatReturnSources::key()}: the
 * entry's source type, `manual` for a manual entry), `reversal` whether the lines belong to a
 * reversal pair, `lines` how many coded lines, `base` / `tax` the signed sums
 * (`journal_line.tax_base` / `tax_amount`).
 */
final class VatReturnLineGroup
{
    public function __construct(
        public readonly string $taxCode,
        public readonly string $category,
        public readonly int $rate,
        public readonly RowOrigin $origin,
        public readonly string $sourceKey,
        public readonly bool $reversal,
        public readonly int $accountId,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly string $accountType,
        public readonly int $lines,
        public readonly Money $base,
        public readonly Money $tax,
    ) {}
}
