<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * The trial balance (Saldobilanz / Probebilanz, plan §5.5): every account
 * with lines in the range — Σ debit, Σ credit and the balance split into a
 * «Saldo Soll» and a «Saldo Haben» column, so no sign has to be read. Both
 * pairs of totals must agree (Σ debit = Σ credit, Σ Saldo Soll = Σ Saldo
 * Haben); the report shows whether they do.
 */
final class TrialBalance
{
    /** @param list<AccountBalance> $rows chart order */
    public function __construct(
        public readonly array $rows,
        public readonly Money $totalDebit,
        public readonly Money $totalCredit,
        public readonly Money $totalDebitBalance,
        public readonly Money $totalCreditBalance,
    ) {}

    public function isBalanced(): bool
    {
        return $this->totalDebit->equals($this->totalCredit) && $this->totalDebitBalance->equals($this->totalCreditBalance);
    }
}
