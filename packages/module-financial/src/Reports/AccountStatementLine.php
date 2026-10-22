<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * A row of the account statement: the entry (id for the link to its detail page, number,
 * date, text), the line's own text, the counter account, debit, credit and the running
 * balance on the account's natural side.
 *
 * Where the pairing is unambiguous a journal line is shown as ONE ROW PER COUNTER LINE
 * (owner 2026-10-22, `LedgerReports::counterRows()`): the statement's line is the only line
 * on its side of the entry, so its amount splits exactly into the other side's lines — each
 * row then carries one counter account and that line's amount (`isSplit`), the running
 * balance stepping through them. Otherwise one row: the one counter account, «div.» when the
 * pairing is ambiguous and the entry has several other accounts (`counterCount` > 1), «–»
 * when there is none.
 */
final class AccountStatementLine
{
    public function __construct(
        public readonly int $entryId,
        public readonly int $entryNumber,
        public readonly \DateTimeImmutable $date,
        public readonly string $text,
        public readonly ?string $lineText,
        public readonly int $counterCount,
        public readonly ?string $counterNumber,
        public readonly ?string $counterName,
        public readonly Money $debit,
        public readonly Money $credit,
        public readonly Money $balance,
        /** One of several rows of the same journal line — one per counter line. */
        public readonly bool $isSplit = false,
    ) {}

    public function hasSeveralCounterAccounts(): bool
    {
        return $this->counterCount > 1;
    }
}
