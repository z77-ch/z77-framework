<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Financial\Bank\BankEntryOwnerInterface;

/**
 * Creditor's answer to financial's bank check (ADR-061 decisions 23, 25, stage C4;
 * `creditorConfig → bankEntryOwners`): a journal entry posted for a creditor invoice — origin
 * `creditor` / the invoice id (`Posting\DirectPosting`) — belongs to the bank link of that
 * invoice, `creditor:{id}` ({@see CreditorInvoice::bankOwnerKeyOf()}). No read: the origin IS the
 * key. Whether a movement is linked under that key is financial's to look up; an invoice paid by
 * hand before «Abgleich ab» (or never linked) then shows as «Buchung ohne Bewegung».
 */
final class CreditorBankEntryOwner implements BankEntryOwnerInterface
{
    public function ownerKeys(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === CreditorInvoice::SOURCE_TYPE && ctype_digit($entry['sourceRef'])) {
                $out[$entry['id']] = CreditorInvoice::bankOwnerKeyOf((int) $entry['sourceRef']);
            }
        }

        return $out;
    }
}
