<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Vat\CountryPack\FieldAssignment;
use Z77\Shared\Money\Money;

/**
 * A computed VAT return: every field of the form edition, immutable — the
 * answer of {@see VatReturnCalculator}.
 *
 * - `value($field)` — the figure of any field: a turnover field (whole
 *   minor units), the EXACT tax of a rate or acquisition field, the input
 *   fields as posted, the totals, and 500 / 510 (rounded once). An input
 *   field holding a reverse-charge share carries that share exact, as
 *   declared in the acquisition field (ADR-041 addendum 2026-11-24, point 8).
 * - `base($field)` — the base of a rate or acquisition field.
 * - `payable` — field 500 − field 510, signed: the amount the settlement
 *   entry posts as declared.
 * - `postedTax` — the same figure from the posted tax of the coded lines;
 *   `roundingDifference()` is what the VAT-return rounding account takes
 *   (point 7 / 13).
 * - `assignments` — where each code + rate went (the mapping a return
 *   snapshots, point 5); `checks` — the plausibility checks.
 */
final class VatReturnForm
{
    /**
     * @param array<string, ExactAmount>  $values      every field of the edition, in form order
     * @param array<string, Money>        $bases       the rate and acquisition fields' bases
     * @param list<FieldAssignment>       $assignments by code, then rate
     * @param list<VatReturnCheck>        $checks
     */
    public function __construct(
        public readonly string $edition,
        public readonly string $currency,
        private readonly array $values,
        private readonly array $bases,
        public readonly Money $payable,
        public readonly Money $postedTax,
        public readonly array $assignments,
        public readonly array $checks,
    ) {}

    public function value(string $field): ExactAmount
    {
        return $this->values[$field] ?? throw new \InvalidArgumentException("Field {$field} is not a field of '{$this->edition}'");
    }

    public function base(string $field): Money
    {
        return $this->bases[$field] ?? throw new \InvalidArgumentException("Field {$field} of '{$this->edition}' has no base");
    }

    /** @return array<string, ExactAmount> every field, in form order */
    public function values(): array
    {
        return $this->values;
    }

    /** Posted − declared: positive = the posted tax exceeds the payable amount (a gain on the rounding account). */
    public function roundingDifference(): Money
    {
        return $this->postedTax->subtract($this->payable);
    }

    /** True when every error check passed — warnings do not count. */
    public function isPlausible(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->severity === VatReturnCheck::ERROR && !$check->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<VatReturnCheck> the raised warnings */
    public function warnings(): array
    {
        return array_values(array_filter($this->checks, static fn(VatReturnCheck $c): bool => $c->severity === VatReturnCheck::WARNING));
    }
}
