<?php

namespace Z77\Module\Financial\Bank;

/**
 * The port by which an OWNING module says which of its journal entries belongs to which bank
 * link (ADR-061 decisions 23, 25, stage C4). The check of a bank account compares the postings
 * on the bank's ledger account with the business movements; a posting «has a movement» when the
 * record it was posted for is linked to one. Financial knows the link only by its opaque owner
 * key (`creditor:17`) and the entry only by its opaque origin (`source_type` / `source_ref`) —
 * the module that wrote both is the one that can join them (Rule 9: financial names no consumer).
 *
 * Declared by class name under the module config key `bankEntryOwners` ({@see BankEntryOwners});
 * instantiated without arguments. Financial resolves its own direct postings itself.
 *
 * ```php
 * // module-creditor: creditorConfig.inc.php
 * 'bankEntryOwners' => [CreditorBankEntryOwner::class],
 * ```
 */
interface BankEntryOwnerInterface
{
    /**
     * The owner key (as passed to `BankLinkService::link()`) of each entry this module posted
     * for a record that CAN be linked to a bank movement. Entries of other origins, and entries
     * of records that have no owner key, are left out. Read-only; one call per check, so answer
     * a list in few queries.
     *
     * @param list<array{id: int, sourceType: string, sourceRef: string}> $entries journal entries on a bank's ledger account
     * @return array<int, string> journal entry id → owner key
     */
    public function ownerKeys(array $entries): array;
}
