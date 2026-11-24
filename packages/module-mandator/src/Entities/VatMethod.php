<?php

namespace Z77\Module\Mandator\Entities;

/**
 * How the mandator computes its VAT — the first axis of the settlement method (ADR-041 addendum
 * 2026-11-24, point 1; «Abrechnungsmethode», Art. 36 / 37 MWSTG). Only `effective` is built for
 * the VAT return; a return of a mandator set to `net-tax-rate` or `flat-tax-rate` is REFUSED
 * («nicht unterstützt»).
 * Stored on {@see Mandator} as its string, null = not set (no silent default). Domain only, no
 * labels — German display text lives in the UI layer (`Ui\MandatorLayout`).
 */
enum VatMethod: string
{
    case Effective  = 'effective';
    case NetTaxRate = 'net-tax-rate';
    case FlatTaxRate = 'flat-tax-rate';
}
