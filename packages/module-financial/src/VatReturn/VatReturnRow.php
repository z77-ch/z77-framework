<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One aggregate of the coded journal lines of a settlement period: per tax
 * code, category, rate and origin the sum of the signed bases and the sum of
 * the signed posted tax (`journal_line.tax_base` / `tax_amount`, sign
 * convention of `financial.md`: positive on the code's natural side).
 *
 * The input of {@see VatReturnCalculator}. The category is the code's
 * category as the return reads it (a string: the input-tax correction
 * categories are not in `TaxCategory` yet, `FieldKind::CATEGORIES_NOT_IN_MODEL_YET`).
 */
final class VatReturnRow
{
    public readonly int $rate;

    /** @param int $rate hundredths of a percent (810 = 8.1 %) — `mixed` so a float is refused, not coerced (the kernel runs without strict_types) */
    public function __construct(
        public readonly string $taxCode,
        public readonly string $category,
        mixed $rate,
        public readonly RowOrigin $origin,
        public readonly Money $base,
        public readonly Money $tax,
    ) {
        if (!is_int($rate) || $rate < 0) {
            throw new \TypeError('A VAT return row needs the rate as an integer of 0 or more (hundredths of a percent), ' . get_debug_type($rate) . ' given');
        }
        $this->rate = $rate;
        if ($base->currency !== $tax->currency) {
            throw new \InvalidArgumentException("Base and tax of a VAT return row differ in currency: {$base->currency} vs {$tax->currency}");
        }
    }
}
