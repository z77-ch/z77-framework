<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Bank\BankEntryOwnerInterface;
use Z77\Module\Receipt\Entities\Receipt;

/**
 * Receipt's answer to financial's bank check (ADR-061 decisions 23, 25, stage C4;
 * `receiptConfig → bankEntryOwners`): a journal entry posted for a receipt — origin `receipt` /
 * the receipt id (`ReceiptPostingBuilder`) — belongs to the bank link of that receipt,
 * `receipt:{id}` ({@see Receipt::bankOwnerKey()}, stage C5). No read: the origin IS the key.
 * Whether a movement is linked under that key is financial's to look up; a receipt posted onto a
 * bank-driven account's ledger account without a movement then shows as «Buchung ohne Bewegung».
 *
 * Nothing about the posting changes here: a receipt is posted on its RECEIPT date, which may
 * differ from the day the bank books the card payment — the check then names the difference per
 * period (bank.md BANK-CHECK-002).
 */
final class ReceiptBankEntryOwner implements BankEntryOwnerInterface
{
    public function ownerKeys(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === Receipt::SOURCE_TYPE && ctype_digit($entry['sourceRef'])) {
                $out[$entry['id']] = Receipt::SOURCE_TYPE . ':' . (int) $entry['sourceRef'];
            }
        }

        return $out;
    }
}
