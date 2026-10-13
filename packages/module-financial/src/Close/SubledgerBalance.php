<?php

namespace Z77\Module\Financial\Close;

use Z77\Shared\Money\Money;

/**
 * One answer of a {@see SubledgerReconciliationInterface}: the collective account (by NUMBER,
 * as every configuration names an account), what the subledger is called in a sentence
 * («offene Posten Debitoren»), and the balance the ledger should show — debit − credit, so a
 * receivable is positive, a liability negative.
 */
final class SubledgerBalance
{
    public function __construct(
        public readonly string $account,
        public readonly string $label,
        public readonly Money $balance,
    ) {
        if (trim($account) === '' || trim($label) === '') {
            throw new \InvalidArgumentException('A subledger balance names its collective account and what the subledger is.');
        }
    }
}
