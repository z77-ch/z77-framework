<?php

namespace Z77\Module\Financial\Ledger;

use Z77\Shared\Money\Money;

/**
 * The VAT split of ONE gross part — what {@see \Z77\Module\Financial\Services\GrossEntryBuilder}
 * computed: the code and its rate at the rate date, the tax computed, the tax posted (the computed
 * one or the voucher's), the net (the amount of the coded line), the side the net stands on,
 * whether that is the code's natural side (then base and tax are positive on the coded line,
 * otherwise negated) and the mandator's tax account — null when no tax line is written
 * (zero-rated / exempt code, or a tax of 0.00).
 *
 * Per kind:
 *   - a standard part: `net` = gross − tax, `base` = `net`;
 *   - a tax-only part ({@see GrossPartKind::TaxOnly}): `net` = 0.00, `base` = 0.00, `tax` = the
 *     amount, `account` = the input account the line itself stands on;
 *   - a reverse-charge part (`reverseCharge`): `net` = the amount paid (the coded line's amount,
 *     no Swiss VAT inside), `base` = the tax base (the amount paid or the part's `taxBase`), `tax`
 *     = base × rate on top; `account` = the input account the code's input field names,
 *     `owedAccount` = the account of VAT owed.
 */
final class GrossSplit
{
    /** The base on the coded line (unsigned) — the net for a standard part. */
    public readonly Money $base;

    public function __construct(
        public readonly string $code,
        public readonly int $rate,
        public readonly Money $computed,
        public readonly Money $tax,
        public readonly Money $net,
        public readonly bool $netOnDebit,
        public readonly bool $natural,
        public readonly ?string $account,
        public readonly GrossPartKind $kind = GrossPartKind::Standard,
        public readonly bool $reverseCharge = false,
        public readonly ?string $owedAccount = null,
        ?Money $base = null,
    ) {
        $this->base = $base ?? $net;
    }
}
