<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Paging\Paging;

use Z77\Module\Financial\Entities\Account;
use Z77\Shared\Money\Money;

/**
 * The account statement (Kontoblatt, plan §5.5) — one account, one range,
 * ONE PAGE of its lines on the screen, or ALL of them as a lazy stream for the PDF
 * (`LedgerReports::accountStatementForPdf()` — read once; `paging->total` says whether
 * there are any). Every balance is on the account's natural side:
 *
 *   - `opening`: the lines of the same fiscal year dated before `from`;
 *   - `carry`: the balance before the first line of THIS page (= `opening`
 *     on page 1) — the «Übertrag» a printed page starts with;
 *   - each line's running balance, computed over the whole range in SQL, so
 *     it is right on any page;
 *   - `totalDebit` / `totalCredit` / `closing`: the whole range, not the page.
 */
final class AccountStatement
{
    /** @param iterable<AccountStatementLine> $lines a list (a screen page) or a generator (the PDF, read once) */
    public function __construct(
        public readonly Account $account,
        public readonly Money $opening,
        public readonly Money $carry,
        public readonly iterable $lines,
        public readonly Money $totalDebit,
        public readonly Money $totalCredit,
        public readonly Money $closing,
        public readonly Paging $paging,
    ) {}
}
