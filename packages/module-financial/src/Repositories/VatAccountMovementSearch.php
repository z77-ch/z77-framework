<?php

namespace Z77\Module\Financial\Repositories;

/**
 * Which journal lines the per-entry check of the VAT accounts reads — the entries of a settlement
 * period that move a VAT account or carry a coded line with tax (ADR-042 addendum 2 of 2026-11-25,
 * point 7: the lock's equation, entry by entry). Built by `VatReturn\VatAccountViolations`; read by
 * {@see JournalLineRepository::vatAccountMovementLines()}.
 *
 * Every criterion is already PARSED and bound, never interpreted:
 *
 *   - `yearIds` / `from` / `to` — the settlement period (`Y-m-d`, inclusive) and the fiscal years it
 *     touches;
 *   - `accounts` — the mandator's VAT account numbers (owed, input material, input other): every
 *     line on them is read, coded or not;
 *   - `excludedSourceTypes` — entries left out (the year opening, the return's own settlement
 *     entries — the same as the reconciliation's movements);
 *   - `openingAccountId` — an entry with a line on the opening contra account is left out too (an
 *     imported or hand-made opening); 0 = none.
 */
final class VatAccountMovementSearch
{
    /**
     * @param list<int>    $yearIds
     * @param list<string> $accounts
     * @param list<string> $excludedSourceTypes
     */
    public function __construct(
        public readonly array $yearIds,
        public readonly string $from,
        public readonly string $to,
        public readonly array $accounts,
        public readonly array $excludedSourceTypes,
        public readonly int $openingAccountId = 0,
    ) {
        foreach ([$from, $to] as $day) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
                throw new \InvalidArgumentException("A VAT account movement search takes days as Y-m-d, got '{$day}'");
            }
        }
    }
}
