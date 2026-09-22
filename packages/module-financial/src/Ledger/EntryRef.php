<?php

namespace Z77\Module\Financial\Ledger;

/**
 * How a posting source refers to a journal entry (plan §5.4): the fiscal
 * year's code and the entry's number within it — unique together, stable,
 * and readable on a document («Buchung 2026/12»). No database id: the id
 * is the driver's, the number is the ledger's. Immutable.
 *
 * How a module STORES it (one column, two, a string) is that module's shape
 * and arrives with the first consumer (debtor, P3).
 */
final class EntryRef
{
    public function __construct(
        public readonly string $fiscalYear,
        public readonly int $number,
    ) {
        if ($fiscalYear === '' || $number < 1) {
            throw new \InvalidArgumentException('EntryRef needs a fiscal-year code and a number from 1');
        }
    }
}
