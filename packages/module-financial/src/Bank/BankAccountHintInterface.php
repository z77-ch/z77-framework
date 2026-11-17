<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * A module that holds a ledger account for an IBAN of its own (debtor: the payment targets) says
 * here when it CONTRADICTS a bank account — the same IBAN, another ledger account (ADR-060
 * decision 3, owner 2026-11-16). Financial names no such module: the classes are declared under
 * the config key `bankAccountHints` and collected by {@see BankAccountHints}.
 */
interface BankAccountHintInterface
{
    /** @return list<string> one short German line per contradiction; [] when there is none */
    public function hintsFor(BankAccount $account, UnifiedEntityManager $em): array;
}
