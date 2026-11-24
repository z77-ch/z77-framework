<?php

namespace Z77\Module\Mandator\Entities;

/**
 * The settlement rhythm of the VAT return (ADR-041 addendum 2026-11-24, point 2; Art. 35 MWSTG):
 * the three periods the law knows for the effective method — quarter (the rule), month and year
 * (both on request with the tax authority). The semester belongs to the net tax rate method and is
 * not offered. Stored on {@see Mandator} as its string, null = not set. Domain only, no labels.
 *
 * A change takes effect on a 1 January only — NOT enforced on the record: the rule needs the
 * posted returns (stage S2, `mandator.md` pending MANDATOR-VAT-RHYTHM-001).
 */
enum VatRhythm: string
{
    case Quarter = 'quarter';
    case Month   = 'month';
    case Year    = 'year';
}
