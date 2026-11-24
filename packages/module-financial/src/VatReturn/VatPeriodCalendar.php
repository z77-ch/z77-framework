<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Mandator\Entities\VatRhythm;

/**
 * The VAT settlement periods of a mandator — pure date arithmetic (ADR-041
 * addendum 2026-11-24, points 2 and 3):
 *
 *   - The rhythm (quarter | month | year — the mandator's {@see VatRhythm})
 *     runs on the CALENDAR grid; a
 *     change takes effect on a 1 January only — so the rhythms are given per
 *     year (`[2026 => Quarter, 2028 => Month]`: quarters from 2026, months
 *     from 1 January 2028), and a period never spans a change.
 *   - «Abrechnung ab» is a grid start or the day the liability began; the
 *     first period then runs from that day to the end of its grid period
 *     (short). Nothing before it is a period.
 *   - «Abrechnung bis» (optional) ends the liability; the last period ends
 *     on that day (short when within a grid period), nothing after it is a
 *     period.
 *   - Returns follow one another without a gap and in order: the period
 *     after a given last one is the grid period of the next day.
 *
 * The VAT grid knows no fiscal years: a period may span two (a yearly
 * settlement with a business year from 1 July). The year close waits only
 * for the periods that END within the year (ADR-042 addendum, point 6) —
 * {@see periodsEndingBetween()}.
 *
 * Dates are `\DateTimeImmutable` at midnight, compared by day — as the
 * fiscal years and periods of this module do.
 */
final class VatPeriodCalendar
{
    /** @var array<int, VatRhythm> year → rhythm from 1 January of that year, ascending */
    private readonly array $rhythms;

    private readonly \DateTimeImmutable $settlementFrom;

    private readonly ?\DateTimeImmutable $settlementTo;

    /**
     * @param array<int, VatRhythm> $rhythms year → the rhythm from 1 January of that year; the earliest year must not lie after «Abrechnung ab»
     */
    public function __construct(array $rhythms, \DateTimeImmutable $settlementFrom, ?\DateTimeImmutable $settlementTo = null)
    {
        if ($rhythms === []) {
            throw new \InvalidArgumentException('A VAT period calendar needs a settlement rhythm');
        }
        foreach ($rhythms as $year => $rhythm) {
            if (!is_int($year) || !$rhythm instanceof VatRhythm) {
                throw new \InvalidArgumentException('The settlement rhythms are given as year => VatRhythm');
            }
        }
        ksort($rhythms);
        $this->settlementFrom = $settlementFrom->setTime(0, 0);
        $this->settlementTo   = $settlementTo?->setTime(0, 0);
        if (array_key_first($rhythms) > (int) $this->settlementFrom->format('Y')) {
            throw new \InvalidArgumentException('No settlement rhythm for the year of «Abrechnung ab» (' . $this->settlementFrom->format('Y-m-d') . ')');
        }
        if ($this->settlementTo !== null && $this->settlementTo < $this->settlementFrom) {
            throw new \InvalidArgumentException('«Abrechnung bis» lies before «Abrechnung ab»');
        }
        $this->rhythms = $rhythms;
    }

    /** The rhythm in force on $date (the latest change on a 1 January not after it). */
    public function rhythmOn(\DateTimeImmutable $date): VatRhythm
    {
        $year   = (int) $date->format('Y');
        $rhythm = $this->rhythms[array_key_first($this->rhythms)];
        foreach ($this->rhythms as $from => $candidate) {
            if ($from <= $year) {
                $rhythm = $candidate;
            }
        }

        return $rhythm;
    }

    /** The full grid period $date falls into — not cut by «Abrechnung ab» / «bis». */
    public function gridPeriodOn(\DateTimeImmutable $date): VatPeriod
    {
        $rhythm = $this->rhythmOn($date);
        $months = self::monthsOf($rhythm);
        $first  = intdiv((int) $date->format('n') - 1, $months) * $months + 1;
        $from   = $date->setDate((int) $date->format('Y'), $first, 1)->setTime(0, 0);

        return new VatPeriod($from, $from->modify("+{$months} months")->modify('-1 day'), $rhythm);
    }

    /** Calendar months per period on the grid — every rhythm starts on 1 January, so a change on that day never cuts a period. */
    private static function monthsOf(VatRhythm $rhythm): int
    {
        return match ($rhythm) {
            VatRhythm::Quarter => 3,
            VatRhythm::Month   => 1,
            VatRhythm::Year    => 12,
        };
    }

    /** The settlement period $date falls into, cut to «Abrechnung ab» / «bis» — null outside the liability. */
    public function periodOn(\DateTimeImmutable $date): ?VatPeriod
    {
        $day = $date->setTime(0, 0);
        if ($day < $this->settlementFrom || ($this->settlementTo !== null && $day > $this->settlementTo)) {
            return null;
        }

        $grid = $this->gridPeriodOn($day);
        $from = max($grid->from, $this->settlementFrom);
        $to   = $this->settlementTo !== null ? min($grid->to, $this->settlementTo) : $grid->to;

        return new VatPeriod($from, $to, $grid->rhythm, $from != $grid->from || $to != $grid->to);
    }

    /** The first period: the one holding «Abrechnung ab». */
    public function first(): VatPeriod
    {
        return $this->periodOn($this->settlementFrom);
    }

    /** The period after $last — null when $last ends on «Abrechnung bis» (or outside the liability). */
    public function next(VatPeriod $last): ?VatPeriod
    {
        return $this->periodOn($last->to->modify('+1 day'));
    }

    /**
     * True when $candidate is the period that must come next: the first one
     * when nothing was settled yet ($last null), else the one right after
     * $last — no gap, no overlap, no other cut.
     */
    public function follows(?VatPeriod $last, VatPeriod $candidate): bool
    {
        $expected = $last === null ? $this->first() : $this->next($last);

        return $expected !== null && $expected->equals($candidate);
    }

    /**
     * Every period that ENDS between $start and $end (inclusive), in order —
     * what a fiscal year's close waits for; a period ending after $end is
     * left to the next year even when it began within this one.
     *
     * @return list<VatPeriod>
     */
    public function periodsEndingBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $startDay = $start->format('Y-m-d');
        $endDay   = $end->format('Y-m-d');
        $periods  = [];
        for ($period = $this->first(); $period !== null && $period->to->format('Y-m-d') <= $endDay; $period = $this->next($period)) {
            if ($period->to->format('Y-m-d') >= $startDay) {
                $periods[] = $period;
            }
        }

        return $periods;
    }
}
