<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\FiscalYear;

/**
 * One TAX YEAR of the VAT return — the calendar year (Art. 34 MWSTG; ADR-041 addendum 2026-11-25,
 * point 11: the yearly reconciliation is by calendar year only) — and the settlement periods that
 * END in it. Immutable, pure; the subject of the yearly overview («Jahresübersicht»,
 * {@see VatYearService}).
 *
 * The periods come from the mandator's {@see VatPeriodCalendar} — cut by «Abrechnung ab» /
 * «bis», so a year in which the liability began or ended holds fewer (or shorter) periods, and a
 * year outside the liability holds none. Every rhythm runs on the calendar grid, so no period
 * spans 31 December: the periods ending in the year cover it from its first to its last
 * settled-for day.
 */
final class VatTaxYear
{
    /** @param list<VatPeriod> $periods in order */
    public function __construct(
        public readonly int $year,
        public readonly array $periods,
    ) {
        foreach ($periods as $period) {
            if (!$period instanceof VatPeriod || (int) $period->to->format('Y') !== $year) {
                throw new \InvalidArgumentException("A tax year holds the VAT periods ending in {$year}");
            }
        }
    }

    /** The tax year $year with the periods the calendar has in it (none outside the liability). */
    public static function of(int $year, VatPeriodCalendar $calendar): self
    {
        return new self($year, $calendar->periodsEndingBetween(new \DateTimeImmutable("{$year}-01-01"), new \DateTimeImmutable("{$year}-12-31")));
    }

    /**
     * The tax year a fiscal year opens (ADR-042 addendum 3, point 7): the TAX YEAR WHOSE
     * 31 DECEMBER LIES IN THE FISCAL YEAR — one rule for the tool «Jahresübersicht» and the link in
     * «Jahr abschliessen»; the other year through the rail.
     *
     *   - fiscal year = calendar year (01.01.–31.12.2026) → 2026;
     *   - 01.07.2025 – 30.06.2026 → 2025 (31.12.2025 lies in it);
     *   - a year of up to 24 months holding two 31 Decembers (01.07.2025 – 30.06.2027) → the later,
     *     2026;
     *   - a short year holding none (01.01.–30.06.2026) → the calendar year of its last day, 2026.
     */
    public static function forFiscalYear(FiscalYear $fiscalYear): int
    {
        $start = $fiscalYear->getStartDate();
        $end   = $fiscalYear->getEndDate();
        if ($start === null || $end === null) {
            throw new \InvalidArgumentException('A fiscal year without its dates opens no tax year');
        }
        $year = (int) $end->format('Y');
        if ($end->format('m-d') === '12-31' || ($year - 1) . '-12-31' < $start->format('Y-m-d')) {
            return $year;
        }

        return $year - 1;
    }

    /** 1 January of the year. */
    public function firstDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable("{$this->year}-01-01");
    }

    /** 31 December of the year — the day the deadlines, the balances and the year close are read at. */
    public function lastDay(): \DateTimeImmutable
    {
        return new \DateTimeImmutable("{$this->year}-12-31");
    }

    /** The first day settled for in the year (1 January, or «Abrechnung ab»); null without a period. */
    public function from(): ?\DateTimeImmutable
    {
        return $this->periods === [] ? null : $this->periods[0]->from;
    }

    /** The last day settled for in the year (31 December, or «Abrechnung bis»); null without a period. */
    public function to(): ?\DateTimeImmutable
    {
        return $this->periods === [] ? null : $this->periods[array_key_last($this->periods)]->to;
    }

    public function hasPeriods(): bool
    {
        return $this->periods !== [];
    }

    /** True when $date lies in the calendar year. */
    public function covers(\DateTimeImmutable $date): bool
    {
        return (int) $date->format('Y') === $this->year;
    }
}
