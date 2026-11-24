<?php

namespace Z77\Module\Vat\CountryPack;

/**
 * A tax code's category and rate have no field on the form (ADR-041 addendum
 * 2026-11-24, point 5: an unmapped category + rate REFUSES the return, never
 * a silent omission) — or a reverse-charge code has no input field. Names
 * the code, the category and the rate, so the message says what to fix.
 */
final class UnmappedTaxCodeException extends FormMappingException
{
    public function __construct(
        public readonly string $taxCode,
        public readonly string $category,
        public readonly int $rate,
        public readonly string $edition,
        string $reason = 'no form field',
    ) {
        parent::__construct("Tax code '{$taxCode}' (category '{$category}', rate {$rate}): {$reason} in the form mapping '{$edition}'");
    }
}
