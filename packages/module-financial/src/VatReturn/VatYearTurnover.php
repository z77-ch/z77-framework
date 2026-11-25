<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * The turnover reconciliation of a tax year — the tab «Umsatz» of the yearly overview (Art. 128
 * para. 2 MWSTV; ADR-042 addendum 2 of 2026-11-25, point 8). MOVEMENTS of the settled-for days of
 * the calendar year (`VatTaxYear::from()` – `to()`, over every fiscal year they touch), never
 * balances; the year opening and the return's own settlement entries left out — the same rule as
 * the return's tab «Abstimmung», applied to the year. Immutable.
 *
 *     revenueTotal = Σ revenueAccounts (credit − debit of every revenue-type account)      — a
 *     declared     = Σ (field 200 − field 235) as the year's LIVE returns stored it
 *     remainder    = revenueTotal − declared − Σ counted items — the UNEXPLAINED part (red when ≠ 0.00,
 *                    blocks nothing — small rule 3 of S7)
 *
 * Beside it, never in the equation (z77 ties no uncoded line to a correction entry — both sums
 * stand side by side): `laterReported` — the corrections FOR the year carried by returns of a LATER
 * year (their entries are in that year's books), field 200 − 235; `correctedDeclared` — declared +
 * every reported correction for the year («Neu deklariert»).
 */
final class VatYearTurnover
{
    /**
     * @param list<ReconciliationLine>  $revenueAccounts kind MOVEMENT, by account number
     * @param list<VatYearTurnoverItem> $items           the named items, in the order of the board (letters, then z77's own)
     */
    public function __construct(
        public readonly array $revenueAccounts,
        public readonly Money $revenueTotal,
        public readonly Money $declared,
        public readonly array $items,
        public readonly Money $laterReported,
        public readonly Money $correctedDeclared,
    ) {}

    /** Σ of the items that explain the difference. */
    public function counted(): Money
    {
        $sum = $this->revenueTotal->subtract($this->revenueTotal);
        foreach ($this->items as $item) {
            if ($item->counted && $item->amount !== null) {
                $sum = $sum->add($item->amount);
            }
        }

        return $sum;
    }

    /** The unexplained remainder — 0.00 when every difference is named. */
    public function remainder(): Money
    {
        return $this->revenueTotal->subtract($this->declared)->subtract($this->counted());
    }
}
