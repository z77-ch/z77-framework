<?php

namespace Z77\Module\Vat\Services;

/**
 * The code is an input-tax correction (`TaxCategory::isManualOnly()` — Einlageentsteuerung,
 * Vorsteuerkorrektur, Vorsteuerkürzung): z77 computes nothing for it, the bookkeeper posts its
 * amount by a manual entry (ADR-041 addendum 2026-11-25, point 3). A document never carries one.
 */
final class ManualOnlyTaxCodeException extends VatException
{
    public function __construct(public readonly string $taxCode, public readonly string $category)
    {
        parent::__construct("Tax code '{$taxCode}' (category '{$category}') is posted by a manual entry only — no document computes it");
    }
}
