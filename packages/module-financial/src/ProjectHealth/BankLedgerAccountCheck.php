<?php

namespace Z77\Module\Financial\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 8a («Ersteinrichtung», owner 2026-11-19): an ACTIVE bank account whose ledger account
 * is not a postable, active account of the chart (any more) — the validator requires one on save,
 * so this fires after the chart changed (account deleted, made a group, deactivated) or after a
 * chart import that renumbered it. Postings and the bank check of that account are refused.
 *
 * Two queries: the active bank accounts (a handful), and the chart rows of their numbers (`IN`).
 */
final class BankLedgerAccountCheck extends SetupCheck
{
    /** @var list<string> labels of the bank accounts found without a valid ledger account */
    private array $found = [];

    public function id(): string
    {
        return 'financial.bank-ledger';
    }

    public function order(): int
    {
        return 80;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'bank-account', 'list'];
    }

    public function label(): string
    {
        return count($this->found) === 1
            ? 'Bankkonto «' . $this->found[0] . '»: Konto im Kontenplan fehlt'
            : count($this->found) . ' Bankkonten: Konto im Kontenplan fehlt';
    }

    public function isOpen(): bool
    {
        $em   = DI::getUnifiedEntityManager();
        $bank = $em->getRepository(BankAccount::class)->findBy(['active' => true]);
        if ($bank === []) {
            return false;
        }
        $numbers  = array_values(array_unique(array_filter(array_map(static fn(BankAccount $b) => trim($b->getLedgerAccount()), $bank))));
        $postable = [];
        foreach ($numbers === [] ? [] : $em->getRepository(Account::class)->findBy(['number' => $numbers]) as $account) {
            if ($account->isPostable() && $account->isActive()) {
                $postable[$account->getNumber()] = true;
            }
        }
        $this->found = [];
        foreach ($bank as $account) {
            if (!isset($postable[trim($account->getLedgerAccount())])) {
                $this->found[] = $account->getLabel() !== '' ? $account->getLabel() : $account->getCode();
            }
        }

        return $this->found !== [];
    }
}
