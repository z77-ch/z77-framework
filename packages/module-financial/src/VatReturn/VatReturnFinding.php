<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One finding the preview shows — and «MWST buchen» (stage S2) refuses on (blocking) or has
 * confirmed (warning) (ADR-042 addendum 2026-11-24, point 1). Both sources in one shape: the
 * open-work registry's findings for the scope `vat-return` ({@see OPEN_WORK}, the registry's
 * message and reference passed through) and the return's own checks (every other code). The
 * posting preconditions of «MWST buchen» (`VatSettlementPlan`) are blocking findings too, their
 * code a `VatReturnRefusal` reason (`not-next`, `not-elapsed`, `year-closed`, …).
 *
 *   - `blocking` — true = «MWST buchen» refuses; false = a warning to confirm;
 *   - `code` — the stable key (one of the constants);
 *   - `message` — the German sentence, shown as it is;
 *   - `reference` — opaque, where it is cleared, for the screen to link: `account:{number}` (the
 *     account statement over the period), `field:{field}` (the field in «Formular» / «Herkunft»),
 *     `reconciliation` (the tab «Abstimmung»), `mandator:accounts` (Stammdaten › Firma › Mandant,
 *     Reiter «Konten»), or what an open-work check passed (`invoice-journal`,
 *     `bank-movement:{code}`, …); '' when there is nothing to link;
 *   - `amount` — the figure the finding is about, where there is one.
 */
final class VatReturnFinding
{
    /** A finding of the open-work registry (scope `vat-return`). */
    public const OPEN_WORK = 'open-work';
    /** Lines on a revenue account without a tax code (per account) — warning. */
    public const UNCODED_REVENUE = 'uncoded-revenue';
    /** Credit lines without a tax code on a fixed-asset account (a possible sale) — warning. */
    public const UNCODED_ASSET_CREDIT = 'uncoded-asset-credit';
    /** The turnover reconciliation leaves an unexplained remainder — warning (screen decision 6). */
    public const TURNOVER_REMAINDER = 'turnover-remainder';
    /** A VAT account's movement differs from the coded tax — BLOCKING (ADR-042 addendum point 1). */
    public const VAT_ACCOUNT_REMAINDER = 'vat-account-remainder';
    /**
     * The mandator names ONE account for owed VAT and for input tax — BLOCKING (FIN-VAT-ACCOUNTS-001,
     * owner 2026-11-25: forbidden, the mandator validator refuses it on save; a record stored before
     * meets it here). Replaces that account's `vat-account-remainder`, which would send the user to
     * the account statement for a posting that is not there.
     */
    public const VAT_ACCOUNT_SHARED = 'vat-account-shared';
    /** The mandator names no usable account for a category that carries tax — BLOCKING. */
    public const VAT_ACCOUNT_MISSING = 'vat-account-missing';
    /** A field or a base is negative — warning (E9). */
    public const NEGATIVE_FIELD = 'negative-field';
    /** Exempt turnover without an input-tax correction — warning. */
    public const EXEMPT_WITHOUT_CORRECTION = 'exempt-without-correction';
    /** A plausibility check of the electronic return failed — BLOCKING (a defect, never expected). */
    public const CHECK_FAILED = 'check-failed';
    /** The rounding difference exceeds half a Rappen per coded document — warning (ADR-041 addendum point 13). */
    public const ROUNDING_DIFFERENCE = 'rounding-difference';
    /**
     * «MWST buchen» (stage S2): the posted tax of the coded lines does not equal the tax on the VAT
     * accounts the settlement entry clears — the balancing line would not be the rounding difference
     * of the form. BLOCKING: the rounding account never absorbs a gap (ADR-042 addendum point 1).
     * Arises only for posted tax on a category without a VAT account (zero-rated / exempt).
     */
    public const SETTLEMENT_UNBALANCED = 'settlement-unbalanced';

    public function __construct(
        public readonly bool $blocking,
        public readonly string $code,
        public readonly string $message,
        public readonly string $reference = '',
        public readonly ?Money $amount = null,
    ) {}
}
