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
 * ({@see \Z77\Module\Financial\Services\GrossEntryBuilder::TAX_CORRECTION_MAX}). Null = computed.
 */
final class GrossPart
{
    public function __construct(
        public readonly string $account,
        public readonly Money $gross,
        public readonly ?string $taxCode = null,
        public readonly ?string $text = null,
        public readonly ?Money $voucherTax = null,
    ) {}
}
