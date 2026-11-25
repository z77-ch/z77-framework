<?php

namespace Z77\Module\Financial\Ledger;

use Z77\Shared\Money\Money;

/**
 * One GROSS part of an entry {@see \Z77\Module\Financial\Services\GrossEntryBuilder} builds: the
 * account that receives it (Soll), the gross amount as the voucher states it, the tax code — null
 * = «ohne MWST» — and an optional line text.
 *
 * `voucherTax` is the tax amount PRINTED on the voucher when it differs from the computed one by
 * voucher rounding; the builder takes it only within its correction limit
 * ({@see \Z77\Module\Financial\Services\GrossEntryBuilder::taxCorrectionLimit()}). Null = computed.
 * Not with a tax-only part nor with reverse charge (the tax is computed there).
 *
 * `kind` — {@see GrossPartKind}: a tax-only part's amount IS the tax and its `account` is ignored
 * (may be '').
 *
 * `taxBase` — reverse charge only: the base the tax is computed on when it is not the amount paid
 * (an invoice in a foreign currency: the amount × the ESTV rate, ADR-041 addendum 2026-11-25,
 * point 10). Null = the amount paid is the base.
 */
final class GrossPart
{
    public function __construct(
        public readonly string $account,
        public readonly Money $gross,
        public readonly ?string $taxCode = null,
        public readonly ?string $text = null,
        public readonly ?Money $voucherTax = null,
        public readonly GrossPartKind $kind = GrossPartKind::Standard,
        public readonly ?Money $taxBase = null,
    ) {}
}
