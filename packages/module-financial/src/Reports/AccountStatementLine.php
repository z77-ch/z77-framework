<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * A row of the account statement: the entry (id for the link to its detail
 * page, number, date, text), the line's own text, the counter account — the
 * one other account of the entry, or «div.» when there are several
 * (`counterCount` > 1) —, debit, credit and the running balance on the
 * account's natural side.
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
    ) {}

    public function hasSeveralCounterAccounts(): bool
    {
        return $this->counterCount > 1;
    }
}
