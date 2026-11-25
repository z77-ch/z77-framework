<?php

namespace Z77\Module\Mandator\Services;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;

/**
 * The mandator's VAT-return settings as ONE immutable answer (ADR-041 addendum 2026-11-24, points
 * 1–4) — what the VAT return, its period calculator and its menu entry read, through
 * {@see CurrentMandator::vatSettings()}. Each setting is null while NOT SET: there is no silent
 * default, the consumer refuses («nicht festgelegt»). The accounts are not here — an account is
 * read through its module's access point (`mandator.md` rules).
 *
 * The rhythm (ADR-041 addendum 2026-11-25, point 1): `rhythm` is the CURRENT one, in force from
 * `rhythmFrom` («Rhythmus gilt ab», a 1 January) or — null — from «Abrechnung ab»; the years before
 * keep the rhythms of `earlierRhythms`. The period calendar takes them as ONE map,
 * {@see rhythmsByYear()} — never `rhythm` alone, which would re-cut the periods of the earlier years.
 *
 * The decisions stay with the consumers: only effective + agreed is built (point 1), the period grid
 * and the short first period (points 2 and 3), the screen kept while a period up to `until` is not
 * settled (point 3). This object only reports what is stored.
 */
final readonly class VatSettings
{
    /**
     * @param array<int, VatRhythm> $earlierRhythms year → the rhythm from 1 January of that year, for the years before `rhythmFrom`
     */
    public function __construct(
        /** Liable to VAT — the default of {@see CurrentMandator::liableToVat()} applies (no record = liable). */
        public bool $liable,
        public ?VatMethod $method,
        public ?VatConsideration $consideration,
        /** The CURRENT rhythm — in force from {@see $rhythmFrom}, else from «Abrechnung ab». */
        public ?VatRhythm $rhythm,
        /** «Abrechnung ab» — nothing before it is recomputed. */
        public ?\DateTimeImmutable $from,
        /** «Abrechnung bis» — the end of the liability in z77; null = open-ended. */
        public ?\DateTimeImmutable $until,
        /** «Rhythmus gilt ab» — the 1 January the current rhythm applies from; null = since «Abrechnung ab». */
        public ?\DateTimeImmutable $rhythmFrom = null,
        public array $earlierRhythms = [],
    ) {}

    public static function of(Mandator $mandator): self
    {
        return new self(
            $mandator->isLiableToVat(),
            $mandator->getVatMethod(),
            $mandator->getVatConsideration(),
            $mandator->getVatSettlementRhythm(),
            $mandator->getVatSettlementFrom(),
            $mandator->getVatSettlementUntil(),
            $mandator->getVatRhythmFrom(),
            $mandator->earlierVatRhythms(),
        );
    }

    /** No record saved yet: liable (the documented default), nothing set. */
    public static function none(): self
    {
        return new self(true, null, null, null, null, null);
    }

    /**
     * The rhythms as the period calendar takes them (`VatPeriodCalendar`): year → the rhythm from
     * 1 January of that year, ascending, the FIRST key the year of «Abrechnung ab» (the rhythm in
     * force on that day), a key only where the rhythm changes. [] while the rhythm or «Abrechnung ab»
     * is not set — the consumer refuses then.
     *
     * @return array<int, VatRhythm>
     */
    public function rhythmsByYear(): array
    {
        if ($this->rhythm === null || $this->from === null) {
            return [];
        }
        $fromYear = (int) $this->from->format('Y');
        $start    = $this->rhythmFrom !== null ? (int) $this->rhythmFrom->format('Y') : $fromYear;
        $all      = array_filter($this->earlierRhythms, static fn(int $year): bool => $year < $start, ARRAY_FILTER_USE_KEY);
        $all[$start] = $this->rhythm;
        ksort($all);

        // The rhythm in force on «Abrechnung ab» is keyed to its year; an earlier key means nothing
        // to the calendar. Without one, the earliest rhythm reaches back to that year.
        $inForce = null;
        $later   = [];
        foreach ($all as $year => $rhythm) {
            if ($year <= $fromYear) {
                $inForce = $rhythm;
            } else {
                $later[$year] = $rhythm;
            }
        }
        $map  = [$fromYear => $inForce ?? reset($later)];
        $last = $map[$fromYear];
        foreach ($later as $year => $rhythm) {
            if ($rhythm !== $last) {
                $map[$year] = $rhythm;
                $last       = $rhythm;
            }
        }

        return $map;
    }

    /** The rhythm in force in $year by {@see rhythmsByYear()} — before the first key the first rhythm (the calendar's rule); null when none is set. */
    public function rhythmIn(int $year): ?VatRhythm
    {
        $map = $this->rhythmsByYear();
        if ($map === []) {
            return null;
        }
        $rhythm = reset($map);
        foreach ($map as $from => $candidate) {
            if ($from <= $year) {
                $rhythm = $candidate;
            }
        }

        return $rhythm;
    }

    /**
     * The first year whose rhythm differs between $before and $after — what a save changes in the
     * period calendar from that 1 January on; null when the calendars agree year by year. For the
     * guard of the posted returns (`VatSettingsGuard`): a period of an earlier year is untouched.
     */
    public static function firstRhythmDifference(self $before, self $after): ?int
    {
        $a = $before->rhythmsByYear();
        $b = $after->rhythmsByYear();
        if ($a === $b) {
            return null;
        }
        $years = array_merge(array_keys($a), array_keys($b));
        for ($year = min($years), $last = max($years); $year <= $last; $year++) {
            if ($before->rhythmIn($year) !== $after->rhythmIn($year)) {
                return $year;
            }
        }

        return null;
    }
}
