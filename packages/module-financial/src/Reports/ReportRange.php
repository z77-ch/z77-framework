<?php

namespace Z77\Module\Financial\Reports;

use Z77\Module\Financial\Entities\FiscalYear;

/**
 * What every report of P2 part 3 is asked for (plan §5.5): ONE fiscal year
 * and a date range inside it, both ends inclusive. A report never reaches
 * across years — the carry-forward from the previous year is the opening
 * entry of P5 (plan §5.7), not something a report adds up (FIN-REPORT-001).
 */
final class ReportRange
{
    /** @throws \InvalidArgumentException when from > to or a date lies outside the year */
    public function __construct(
        public readonly FiscalYear $year,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {
        if ($from->format('Y-m-d') > $to->format('Y-m-d')) {
            throw new \InvalidArgumentException('A report range starts on or before its end');
        }
        if (!$year->covers($from) || !$year->covers($to)) {
            throw new \InvalidArgumentException("A report range lies inside its fiscal year {$year->getCode()}");
        }
    }

    public static function wholeYear(FiscalYear $year): self
    {
        return new self($year, $year->getStartDate(), $year->getEndDate());
    }

    /** The same end, from the first day of the year — the balance sheet is a statement AT a day, not over a range. */
    public function fromYearStart(): self
    {
        return new self($this->year, $this->year->getStartDate(), $this->to);
    }

    /**
     * Whether the range is the WHOLE year — for a statement AT a day ($atDay, the balance
     * sheet, which always reads from the year's first day) when it ends on the year's last day.
     */
    public function isWholeYear(bool $atDay = false): bool
    {
        return $this->toDay() === $this->year->getEndDate()->format('Y-m-d')
            && ($atDay || $this->fromDay() === $this->year->getStartDate()->format('Y-m-d'));
    }

    /**
     * The same range in an OLDER fiscal year — the comparison columns of the balance sheet
     * and the income statement (review 2026-10-13 H6: moving the dates by whole calendar
     * years was wrong for short, long and non-calendar years and for 29 February). The SAME
     * OFFSET within the fiscal year, anchored to its START, never to the calendar — for a
     * choice of the select ({@see ReportPreset}) and for typed dates alike, because the rule
     * reads the dates only (owner 2026-10-22: «Halbjahr 2025 vs Halbjahr 2024 vs 2023»):
     *
     *   - the whole year → the whole older year (whatever its length);
     *   - a range on PERIOD bounds — it starts on the first day of period i and ends on the
     *     last day of period j (a month, a quarter, a half, any run of months; at a day: it
     *     ends on the last day of period j) → periods i … j of the older year (the 2nd half
     *     → periods 7–12, «per 30.6.» of a calendar year → per the end of the 6th period); an
     *     older year without period j (a short year) has no comparable range;
     *   - anything else → both dates shifted by the distance between the two year starts
     *     (months, then days); a date on a month's last day stays on the last day of its
     *     month (`last day of` — 29.2. → 28.2., 30.4. → 31.10. when the years start half a
     *     year apart), and the result is clamped into the older year.
     *
     * $atDay: a statement AT a day (the balance sheet) — the comparable range starts on the
     * older year's first day as well. Null when the older year holds no such range.
     */
    public function comparableIn(FiscalYear $older, bool $atDay = false): ?self
    {
        if ($this->isWholeYear($atDay)) {
            return self::wholeYear($older);
        }
        $first = $atDay ? 0 : null;
        $last  = null;
        foreach ($this->year->getPeriods() as $i => $period) {
            if ($first === null && $period->getStartDate()->format('Y-m-d') === $this->fromDay()) {
                $first = $i;
            }
            if ($period->getEndDate()->format('Y-m-d') === $this->toDay()) {
                $last = $i;
            }
        }
        if ($first !== null && $last !== null && $first <= $last) {
            $periods = $older->getPeriods();
            if (!isset($periods[$first], $periods[$last])) {
                return null;
            }

            return new self($older, $atDay ? $older->getStartDate() : $periods[$first]->getStartDate(), $periods[$last]->getEndDate());
        }

        $start  = $this->year->getStartDate();
        $base   = $older->getStartDate();
        $months = ((int) $start->format('Y') * 12 + (int) $start->format('n')) - ((int) $base->format('Y') * 12 + (int) $base->format('n'));
        $days   = (int) $start->format('j') - (int) $base->format('j');
        $from   = $atDay ? $base : max($base, self::shifted($this->from, $months, $days));
        $to     = min($older->getEndDate(), self::shifted($this->to, $months, $days));

        return $from > $to ? null : new self($older, $from, $to);
    }

    /** $date moved back by $months and $days; a month's last day stays a month's last day (when the starts share the day of the month). */
    private static function shifted(\DateTimeImmutable $date, int $months, int $days): \DateTimeImmutable
    {
        $first = $date->modify('first day of this month')->modify(($months >= 0 ? '-' : '+') . abs($months) . ' months');
        if ($days === 0 && $date->format('j') === $date->format('t')) {
            return $first->modify('last day of this month');
        }
        $moved = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $date->format('j'), (int) $first->format('t')));

        return $days === 0 ? $moved : $moved->modify(($days >= 0 ? '-' : '+') . abs($days) . ' days');
    }

    /**
     * How a comparison column names its range when it is NOT the whole year: «per 31.3.2026»
     * at a day, «1.3.–31.3.2026» over a range; null for the whole year (the code says it).
     */
    public function span(bool $atDay = false): ?string
    {
        if ($this->isWholeYear($atDay)) {
            return null;
        }

        return $atDay ? 'per ' . $this->to->format('j.n.Y') : $this->from->format('j.n.') . '–' . $this->to->format('j.n.Y');
    }

    /** `Y-m-d` — how the dates are bound in the report SQL and carried in the report URLs. */
    public function fromDay(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDay(): string
    {
        return $this->to->format('Y-m-d');
    }
}
