<?php

namespace Z77\Module\Financial\Ledger;

use Z77\Shared\Money\Money;

/**
 * The VAT split of ONE gross part — what {@see \Z77\Module\Financial\Services\GrossEntryBuilder}
 * computed: the code and its rate on the date, the tax computed in gross mode, the tax posted
 * (the computed one or the voucher's), the net, the side the net stands on, whether that is the
 * code's natural side (then base and tax are positive on the net line, otherwise negated) and the
 * mandator's tax account — null when no tax line is written (zero-rated / exempt code, or a tax
 * of 0.00).
 */
final class GrossSplit
{
    public function __construct(
        public readonly string $code,
        public readonly int $rate,
        public readonly Money $computed,
        public readonly Money $tax,
        public readonly Money $net,
        public readonly bool $netOnDebit,
        public readonly bool $natural,
        public readonly ?string $account,
    ) {}
}
