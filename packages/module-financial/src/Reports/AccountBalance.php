<?php

namespace Z77\Module\Financial\Reports;

use Z77\Module\Financial\Entities\AccountType;
use Z77\Shared\Money\Money;

/**
 * One account's Σ debit and Σ credit over a report range — a row of the
 * aggregate {@see \Z77\Module\Financial\Repositories\JournalLineRepository::balancesByAccount()}.
 */
final class AccountBalance
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $number,
        public readonly string $name,
        public readonly AccountType $type,
        public readonly Money $debit,
        public readonly Money $credit,
    ) {}

    /** The balance on the account's NATURAL side ({@see AccountType::isDebitNormal()}): positive there, negative on the opposite side. */
    public function balance(): Money
    {
        return $this->type->isDebitNormal() ? $this->debit->subtract($this->credit) : $this->credit->subtract($this->debit);
    }

    /** The trial balance's «Saldo Soll» column: debit − credit when that is positive, else zero. */
    public function debitBalance(): Money
    {
        $net = $this->debit->subtract($this->credit);

        return $net->isPositive() ? $net : Money::zero($net->currency);
    }

    /** The trial balance's «Saldo Haben» column: credit − debit when that is positive, else zero. */
    public function creditBalance(): Money
    {
        $net = $this->credit->subtract($this->debit);

        return $net->isPositive() ? $net : Money::zero($net->currency);
    }
}
