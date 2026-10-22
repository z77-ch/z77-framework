<?php

namespace Z77\Module\Financial\Reports;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Ui\MonthNames;

/**
 * One choice of the report range select (owner 2026-10-22): a named section of the fiscal
 * year that SETS the dates. Built from the year's PERIODS, never from the calendar — a year
 * may start in any month.
 *
 * Over a range («von – bis»: trial balance, income statement, account statement, journal):
 *   - `year`        «Ganzes Jahr»                    — the year's first to last day;
 *   - `h1` / `h2`   «1. / 2. Halbjahr (Jan–Jun)»     — periods 1–6 / 7–12;
 *   - `q1` … `q4`   «Q1 (Jan–Mär)» …                 — periods 1–3, 4–6, 7–9, 10–12;
 *   - `YYYY-MM`     the months                       — one period each (the start's month).
 *
 * At a day («Stichtag»: the balance sheet) the same keys name the END of that section and
 * `from` is the year's first day: `year` «Jahresende», `h1` «Halbjahr» (the end of the 6th
 * period), `q1` … `q4` the quarter ends, the months their ends. No `h2` there — its end is
 * the year end.
 *
 * Halves and quarters exist only for a year of exactly TWELVE periods: a short or long year
 * (a first year from a founding date, a changed year end) has no meaningful half — it offers
 * the whole year and its months. The keys stay stable across years, so a link or a bookmark
 * reads «the first quarter» in whichever year it is applied to.
 */
final class ReportPreset
{
    public const YEAR = 'year';

    /** The period index ranges of halves and quarters (0-based, inclusive) — only for a 12-period year. */
    private const SECTIONS = [
        'h1' => [0, 5], 'h2' => [6, 11],
        'q1' => [0, 2], 'q2' => [3, 5], 'q3' => [6, 8], 'q4' => [9, 11],
    ];

    public function __construct(
        /** What the select sends as `?period=`. */
        public readonly string $key,
        public readonly string $label,
        /** The optgroup it stands in ('' = none). */
        public readonly string $group,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {}

    /**
     * The choices for $year, in the select's order.
     *
     * @param bool $atDay a statement AT a day (the balance sheet): the section ENDS
     * @return list<self>
     */
    public static function of(FiscalYear $year, bool $atDay = false): array
    {
        $periods = $year->getPeriods();
        $start   = $year->getStartDate();
        $date    = static fn(\DateTimeImmutable $d): string => $d->format('d.m.Y');
        $month   = static fn(\DateTimeImmutable $d): string => MonthNames::SHORT[(int) $d->format('n') - 1];

        $presets = [new self(self::YEAR, $atDay ? 'Jahresende · ' . $date($year->getEndDate()) : 'Ganzes Jahr', '', $start, $year->getEndDate())];
        if (count($periods) === 12) {
            foreach (self::SECTIONS as $key => [$first, $last]) {
                if ($atDay && $key === 'h2') {
                    continue;   // the end of the second half is the year end
                }
                $from  = $periods[$first]->getStartDate();
                $to    = $periods[$last]->getEndDate();
                $half  = $key[0] === 'h';
                $label = $atDay
                    ? ($half ? 'Halbjahr' : 'Q' . $key[1]) . ' · ' . $date($to)
                    : ($half ? $key[1] . '. Halbjahr' : 'Q' . $key[1]) . ' (' . $month($from) . '–' . $month($to) . ')';
                $presets[] = new self($key, $label, $half ? '' : ($atDay ? 'Quartalsenden' : 'Quartale'), $atDay ? $start : $from, $to);
            }
        }
        foreach ($periods as $period) {
            $from = $period->getStartDate();
            $to   = $period->getEndDate();
            $presets[] = new self(
                $from->format('Y-m'),
                $atDay ? $month($from) . ' · ' . $date($to) : $month($from) . ' ' . $from->format('Y'),
                $atDay ? 'Monatsenden' : 'Monate',
                $atDay ? $start : $from,
                $to,
            );
        }

        return $presets;
    }

    /** The choice under $key, or null. @param list<self> $presets */
    public static function find(array $presets, string $key): ?self
    {
        foreach ($presets as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * Which choice the select shows for $range: the one asked for ($requested) while it still
     * says what is shown — Q2 and «Halbjahr» end on the same day, the one picked stays picked —,
     * else the FIRST that matches, else null («Eigene Daten» / «Eigenes Datum»). The select
     * therefore never contradicts the dates.
     *
     * @param list<self> $presets
     */
    public static function selected(array $presets, ReportRange $range, bool $atDay, string $requested = ''): ?string
    {
        $asked = $requested === '' ? null : self::find($presets, $requested);
        if ($asked?->matches($range, $atDay)) {
            return $asked->key;
        }
        foreach ($presets as $preset) {
            if ($preset->matches($range, $atDay)) {
                return $preset->key;
            }
        }

        return null;
    }

    /** Whether $range is this section — at a day the end alone decides. */
    public function matches(ReportRange $range, bool $atDay): bool
    {
        return $this->to->format('Y-m-d') === $range->toDay() && ($atDay || $this->from->format('Y-m-d') === $range->fromDay());
    }
}
