<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * The reconciliation of one period — the tab «Abstimmung» (ADR-041 addendum 2026-11-24, point 6;
 * review C8): the accounts are the CONTROL of the return, not its source. Plain data, immutable.
 * MOVEMENTS of the period, never balances; the settlement entries of the return (source
 * `vat-return`) and the year-opening entries are left out.
 *
 * (a) Turnover:
 *
 *     revenueTotal = Σ revenueAccounts (credit − debit of every revenue-type account)
 *     declared     = field 200 − field 235
 *     difference   = revenueTotal − declared
 *     explained    = the named differences ({@see ReconciliationLine}: uncoded lines per revenue
 *                    account, output-coded lines on other account types, correction entries)
 *     remainder    = difference − Σ explained — the UNEXPLAINED part: a warning (screen decision 6)
 *
 *     What lands in the remainder: a coded line whose base differs from its amount, a line with an
 *     INPUT code on a revenue account, a coded output line in an opening entry.
 *
 * (b)+(c) The VAT accounts ({@see VatAccountReconciliation}): each input-tax account against its
 *     field, the VAT-owed account against the posted tax of the rate fields — a difference is
 *     BLOCKING.
 */
final class VatReturnReconciliation
{
    /**
     * @param list<ReconciliationLine>       $revenueAccounts kind MOVEMENT, by account number
     * @param list<ReconciliationLine>       $explained       the named differences, by kind then account
     * @param list<VatAccountReconciliation> $vatAccounts     the VAT-owed account first, then the input accounts
     */
    public function __construct(
        public readonly array $revenueAccounts,
        public readonly Money $revenueTotal,
        public readonly Money $field200,
        public readonly Money $field235,
        public readonly array $explained,
        public readonly array $vatAccounts,
    ) {}

    /** Field 200 − field 235: the turnover the return declares before the deductions. */
    public function declared(): Money
    {
        return $this->field200->subtract($this->field235);
    }

    /** Revenue movements − declared turnover. */
    public function difference(): Money
    {
        return $this->revenueTotal->subtract($this->declared());
    }

    public function explainedTotal(): Money
    {
        return array_reduce($this->explained, static fn(Money $sum, ReconciliationLine $line): Money => $sum->add($line->amount), $this->revenueTotal->subtract($this->revenueTotal));
    }

    /** The unexplained remainder of the turnover reconciliation — 0.00 when every difference is named. */
    public function remainder(): Money
    {
        return $this->difference()->subtract($this->explainedTotal());
    }

    /** True when every VAT account's movement equals its coded tax. */
    public function vatAccountsAgree(): bool
    {
        foreach ($this->vatAccounts as $account) {
            if (!$account->agrees()) {
                return false;
            }
        }

        return true;
    }
}
