<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Shared\Money\Money;

/**
 * The yearly overview of one tax year («MWST-Jahresübersicht {Jahr}») — what the page and its PDF
 * show, built by {@see VatYearService::overview()} (ADR-042 addendum 2 of 2026-11-25, point 8;
 * addendum 3, points 4 to 9). It POSTS NOTHING, locks nothing, blocks nothing: a read of stored
 * figures (the live returns, their figures, the stored blocks) and of the books. Immutable.
 *
 *   - `taxYear` — the calendar year and its settlement periods; `methodLabel` — «Effektive Methode ·
 *     Vereinbartes Entgelt»;
 *   - `fiscalYears` — the fiscal years the calendar year touches (two when the fiscal year is not
 *     the calendar year — «Überleitung zur Jahresrechnung von Hand», S7-2); `yearEndFiscalYear` —
 *     the one holding 31 December (null: not opened); `deadlines` — day 180 / 240 or
 *     «Frist bei der ESTV klären» ({@see VatYearDeadlines});
 *   - `final` — every period of the year posted AND the fiscal year holding 31 December closed
 *     (B-4); «vorläufig» otherwise — the page and the PDF say so, «Ablegen …» waits for it;
 *   - `periods` — «Abrechnungen {Jahr}»; `fields` — «Summe pro Ziffer» (declared · corrections ·
 *     new, exact); `corrections` — the reported blocks for the year, in order of the corrected
 *     period, then of the carrying return; `pending` — «Erfasst, noch nicht abgerechnet»;
 *   - `turnover` — the tab «Umsatz»; `accounts` — the VAT accounts of the tab «Vorsteuer» (owed
 *     first, then the input accounts by number);
 *   - `rounding` — Σ of the year's returns' rounding lines (6945, signed as stored); `payables` —
 *     Σ of their payable amounts as posted (a credit negative);
 *   - `settlement` (2201), `inputPending` (1179), `correctionPending` (2209, with what is still open
 *     today) — the balance per 31.12.; null when the mandator names no account (or the number is
 *     no account of the chart);
 *   - `findings` — «Offene Punkte», information only: every one has `blocking` false.
 */
final class VatYearOverview
{
    /**
     * @param list<FiscalYear>        $fiscalYears
     * @param list<VatYearPeriod>     $periods
     * @param list<VatYearField>      $fields
     * @param list<VatYearCorrection> $corrections
     * @param list<VatYearPending>    $pending
     * @param list<VatYearAccount>    $accounts
     * @param list<VatReturnFinding>  $findings
     */
    public function __construct(
        public readonly VatTaxYear $taxYear,
        public readonly string $methodLabel,
        public readonly array $fiscalYears,
        public readonly ?FiscalYear $yearEndFiscalYear,
        public readonly VatYearDeadlines $deadlines,
        public readonly bool $final,
        public readonly array $periods,
        public readonly array $fields,
        public readonly array $corrections,
        public readonly array $pending,
        public readonly VatYearTurnover $turnover,
        public readonly array $accounts,
        public readonly Money $rounding,
        public readonly Money $payables,
        public readonly ?VatYearBalance $settlement,
        public readonly ?VatYearBalance $inputPending,
        public readonly ?VatYearBalance $correctionPending,
        public readonly array $findings,
    ) {}

    /** The periods of the year not posted yet — one reason for «vorläufig». */
    public function unposted(): array
    {
        return array_values(array_map(static fn(VatYearPeriod $p): VatPeriod => $p->period, array_filter($this->periods, static fn(VatYearPeriod $p): bool => !$p->isPosted())));
    }

    /** True when a correction for the year has been reported — else «Keine Korrekturen — keine Jahresabstimmung einzureichen». */
    public function hasCorrections(): bool
    {
        return $this->corrections !== [];
    }

    /** The field row of the year sum, null when the year has none. */
    public function field(string $field): ?VatYearField
    {
        foreach ($this->fields as $row) {
            if ($row->field === $field) {
                return $row;
            }
        }

        return null;
    }
}
