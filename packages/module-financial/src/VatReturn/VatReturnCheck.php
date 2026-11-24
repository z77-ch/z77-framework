<?php

namespace Z77\Module\Financial\VatReturn;

/**
 * One plausibility check of a computed return (ADR-041 addendum 2026-11-24,
 * point 7: the checks of the electronic return hold from the first version
 * on; E9: a negative field is shown with a warning, not refused).
 *
 * Domain only — `id` is the key a screen or the PDF turns into German text.
 * `error` checks are always listed, passed or not; `warning`s only when
 * raised. `field` names the form field a check is about (null when it is
 * about the whole form); `expected` / `actual` carry the figures compared,
 * where there are two.
 */
final class VatReturnCheck
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';

    /** Field 289 = the sum of the deduction fields and the reductions. */
    public const DEDUCTIONS_TOTAL = 'deductions-total';
    /** Field 299 = field 200 − field 289. */
    public const TAXABLE_TOTAL = 'taxable-total';
    /** Field 299 = the sum of the bases of the rate fields, to the Rappen (eCH-0217, MWST-0005). */
    public const TAXABLE_TOTAL_RATE_BASES = 'taxable-total-rate-bases';
    /** Field 399 = the sum of the exact taxes of the rate and acquisition fields. */
    public const TAX_TOTAL = 'tax-total';
    /** Field 479 = the input fields less the input-tax corrections. */
    public const INPUT_TOTAL = 'input-total';
    /** Field 500 / 510 = field 399 − field 479, rounded once. */
    public const PAYABLE = 'payable';
    /** A field or a base is negative (warning). */
    public const NEGATIVE_FIELD = 'negative-field';
    /** Exempt turnover declared, but no input-tax correction for mixed / own use (warning — review L5 / 6.1; `actual` = the exempt turnover). */
    public const EXEMPT_WITHOUT_CORRECTION = 'exempt-without-correction';

    public function __construct(
        public readonly string $id,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly ?string $field = null,
        public readonly ?ExactAmount $expected = null,
        public readonly ?ExactAmount $actual = null,
    ) {}
}
