<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Financial\Bank\BankAccountHintInterface;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Payment\Iban;

/**
 * Tells financial's bank account window when an ACTIVE payment target carries the bank account's
 * IBAN (plain or QR-IBAN) with ANOTHER ledger account (ADR-060 decision 3, owner 2026-11-16: the
 * hint stands in the bank account window only). Declared under `bankAccountHints` in the debtor
 * config; loaded only when module-financial is installed and asks. It falls away once the payment
 * target reads its account from «Bankkonten».
 */
final class PaymentTargetBankAccountHint implements BankAccountHintInterface
{
    public function hintsFor(BankAccount $account, UnifiedEntityManager $em): array
    {
        $hints = [];
        foreach ($em->getRepository(PaymentTarget::class)->allInOrder() as $target) {
            if (!$target->isActive() || $target->getAccountNumber() === '' || $target->getAccountNumber() === $account->getLedgerAccount()) {
                continue;
            }
            if (in_array($account->getIban(), [Iban::normalize($target->getIban()), Iban::normalize($target->getQrIban())], true)) {
                $hints[] = 'Zahlungsziel «' . $target->getLabel() . '» führt diese IBAN mit Konto ' . $target->getAccountNumber() . '.';
            }
        }

        return $hints;
    }
}
