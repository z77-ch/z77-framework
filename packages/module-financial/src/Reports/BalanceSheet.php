<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * The balance sheet AT the range's end day (plan §5.5): Aktiven (type
 * `asset`) against Passiven — Fremdkapital (`liability`) and Eigenkapital
 * (`equity`) shown apart, with the CURRENT RESULT (revenue − expense from the
 * year's first day) as a line of its own in equity. With it, Aktiven =
 * Passiven holds by construction of double entry; the report shows it.
 *
 * Reads the entries of the range's fiscal year only — before P5 there is no
 * opening entry, so a year after the first shows no carried-forward balances
 * (FIN-REPORT-001).
 */
final class BalanceSheet
{
    public function __construct(
        public readonly ReportRange $range,
        public readonly StatementSection $assets,
        public readonly StatementSection $liabilities,
        public readonly StatementSection $equity,
        public readonly Money $result,
    ) {}

    /** Eigenkapital including the current result. */
    public function totalEquity(): Money
    {
        return $this->equity->total->add($this->result);
    }

    public function totalLiabilitiesAndEquity(): Money
    {
        return $this->liabilities->total->add($this->totalEquity());
    }

    /** Aktiven − Passiven; zero when the books balance. */
    public function difference(): Money
    {
        return $this->assets->total->subtract($this->totalLiabilitiesAndEquity());
    }

    public function isBalanced(): bool
    {
        return $this->difference()->isZero();
    }
}
