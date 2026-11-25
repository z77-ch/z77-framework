<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * The balance of one mandator account at 31 December of a tax year, for the information of the
 * yearly overview (ADR-042 addendum 3, point 4; S7): the settlement account 2201 (what is still
 * owed to or by the ESTV), the transit account 1179 «Vorsteuer, noch nicht abgerechnet» (stands at
 * 0.00 — input tax is not caught up across the year, S56-8) and the transit account 2209
 * «MWST-Korrektur, noch nicht abgerechnet» (B-1). Immutable.
 *
 *   - `balance` — the balance at the END of 31.12. in the fiscal year holding that day, its year
 *     opening included; the account statement's sign, debit − credit (a liability is negative);
 *   - `open` — for 2209 only: what of that balance is still open TODAY — the balance plus every
 *     later movement on the account, the year openings left out (`VatCorrectionTransit::openAfter()`,
 *     the reader the year-close warning uses); null for the other accounts.
 */
final class VatYearBalance
{
    public function __construct(
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly Money $balance,
        public readonly ?Money $open = null,
    ) {}
}
