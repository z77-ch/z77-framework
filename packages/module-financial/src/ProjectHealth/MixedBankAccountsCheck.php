<?php

namespace Z77\Module\Financial\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 8b («Ersteinrichtung», owner 2026-11-19): an active bank account is «gemischt
 * geführt» (ADR-061 addendum 2026-11-19) but the mandator lacks a postable account for `private`
 * («Privat») or `bank-transfer` («Übertrag eigene Bankkonten») — «Privat …» and the bank check
 * would be refused. These two accounts have NO KMU default (`MandatorAccounts::DEFAULTS`), so
 * `mandator.accounts` (step 2) does not ask for them; only a mixed account needs them.
 *
 * Read through the ONE reader `LedgerService::bankPostingAccount()` (Rule 2). At most four small
 * queries: one mixed account, the mandator, the two chart rows.
 */
final class MixedBankAccountsCheck extends SetupCheck
{
    public function id(): string
    {
        return 'financial.bank-mixed';
    }

    public function order(): int
    {
        return 81;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'mandator', 'edit'];
    }

    public function label(): string
    {
        return 'Mandant: Konten Privat und Übertrag zuweisen';
    }

    public function isOpen(): bool
    {
        $em = DI::getUnifiedEntityManager();
        if ($em->getRepository(BankAccount::class)->findOneBy(['active' => true, 'mixedUse' => true]) === null) {
            return false;
        }
        $ledger = new LedgerService($em);
        foreach ([LedgerService::BANK_PRIVATE_ACCOUNT, LedgerService::BANK_TRANSFER_ACCOUNT] as $key) {
            $number = $ledger->bankPostingAccount($key);
            if ($number === null || !$ledger->accountExists($number)) {
                return true;
            }
        }

        return false;
    }
}
