<?php

namespace Z77\Module\Financial\Ledger;

/**
 * What a {@see GrossPart} IS — the ONE vocabulary receipt and creditor store for a line
 * (ADR-041 addendum 2026-11-25, point 6):
 *
 *   - `Standard` — a gross amount the builder splits into net and tax (or posts as is «ohne MWST»);
 *   - `TaxOnly` — «nur Steuer»: the amount IS the input tax (import VAT on a customs assessment,
 *     a forwarder's invoice line). Posted with base 0.00 and the code's rate at the rate date on
 *     the input account the code maps to; the part's account is ignored — the VAT account is
 *     derived, never typed. Input codes (VM / VI) only.
 */
enum GrossPartKind: string
{
    case Standard = 'standard';
    case TaxOnly  = 'tax-only';
}
