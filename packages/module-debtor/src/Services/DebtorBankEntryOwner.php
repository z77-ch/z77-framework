<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Repositories\BankTransactionRepository;
use Z77\Module\Financial\Bank\BankEntryOwnerInterface;

/**
 * Debtor's answer to financial's bank check (ADR-061 decisions 23, 25, stage C4;
 * `debtorConfig → bankEntryOwners`): a journal entry posted for a customer payment — origin
 * `payment` / the payment id (`Payments\PaymentPostingBuilder`) — belongs to the bank link of the
 * camt.054 ENTRY the payment came in: `debtor:entry:{Ntry/AcctSvcrRef}`, or for a payment
 * imported without an entry reference the day group `debtor:day:{message}:{Ymd}` — the two keys
 * {@see BankEntryLinkService} links under. A payment entered by hand has no bank transaction and
 * so no key: its posting on the bank's ledger account shows as «Buchung ohne Bewegung».
 *
 * Read-only, one query per call. Nothing about debtor's postings changes here: they keep the date
 * debtor gives them (the payment's date), which may differ from the day the bank books the
 * collective credit — the check then names the difference per period (bank.md BANK-CHECK-002).
 * Loaded only when module-financial is installed and asks.
 */
final class DebtorBankEntryOwner implements BankEntryOwnerInterface
{
    public function ownerKeys(array $entries): array
    {
        $entryOf = [];   // payment id → journal entry ids
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === 'payment' && ctype_digit($entry['sourceRef'])) {
                $entryOf[(int) $entry['sourceRef']][] = $entry['id'];
            }
        }
        if ($entryOf === []) {
            return [];
        }
        /** @var BankTransactionRepository $transactions */
        $transactions = DI::getUnifiedEntityManager()->getRepository(BankTransaction::class);
        $out          = [];
        foreach ($transactions->bankKeysOfPayments(array_keys($entryOf)) as $paymentId => $row) {
            $key = $row['entryRef'] !== null
                ? BankEntryLinkService::entryKey($row['entryRef'])
                : BankEntryLinkService::dayKey($row['message'], $row['day']);
            foreach ($entryOf[$paymentId] ?? [] as $entryId) {
                $out[$entryId] = $key;
            }
        }

        return $out;
    }
}
