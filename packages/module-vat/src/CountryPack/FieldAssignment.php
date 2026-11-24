<?php

namespace Z77\Module\Vat\CountryPack;

/**
 * Where one tax code at one rate lands on the form — the answer of
 * {@see FormEdition::assign()} and what a return snapshots as its mapping
 * (ADR-041 addendum 2026-11-24, point 5).
 *
 * `inputField` is set for a reverse-charge code only: the input field (400 /
 * 405 in CH) that deducts the tax declared in the acquisition field.
 */
final class FieldAssignment
{
    public function __construct(
        public readonly string $taxCode,
        public readonly string $category,
        public readonly int $rate,
        public readonly string $field,
        public readonly FieldKind $kind,
        public readonly ?string $inputField = null,
    ) {}
}
