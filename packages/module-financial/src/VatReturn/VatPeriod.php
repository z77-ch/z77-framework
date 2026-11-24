<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Ui\MonthNames;
use Z77\Module\Mandator\Entities\VatRhythm;

/**
 * One VAT settlement period: from / to, both inclusive, at midnight —
 * immutable. Built by {@see VatPeriodCalendar}; `short` when it does not
 * start or end on the rhythm's grid (liability begun or ended within it,
 * «Abrechnung ab» / «bis», ADR-041 addendum 2026-11-24, point 3).
 */
final class VatPeriod
{
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly VatRhythm $rhythm,
        public readonly bool $short = false,
    ) {
        if ($to < $from) {
            throw new \InvalidArgumentException("A VAT period ends before it starts: {$from->format('Y-m-d')} – {$to->format('Y-m-d')}");
        }
    }

    /**
     * A period as a posted return stored it (from, to, rhythm): `short` is derived — true when it
     * does not match the grid period of its rhythm.
     */
    public static function stored(\DateTimeImmutable $from, \DateTimeImmutable $to, VatRhythm $rhythm): self
    {
        $from = $from->setTime(0, 0);
        $to   = $to->setTime(0, 0);
        $grid = (new VatPeriodCalendar([(int) $from->format('Y') => $rhythm], $from))->gridPeriodOn($from);

        return new self($from, $to, $rhythm, !$grid->equals(new self($from, $to, $rhythm)));
    }

    /**
     * «3. Quartal 2026», «Juli 2026», «Jahr 2026» — the name of the period on every screen, in the
     * PDF and in the sentence of a refusal (`LedgerService::periodRefusalText()`). Here and not in
     * `Ui\VatReturnLayout` (which delegates) because the ledger's services name it too. The month
     * name is the screens' one list (`Ui\MonthNames`, constants only — `Reports\ReportPreset`
     * reads it the same way).
     */
    public function label(): string
    {
        $year = $this->from->format('Y');

        return match ($this->rhythm) {
            VatRhythm::Quarter => (intdiv((int) $this->from->format('n') - 1, 3) + 1) . '. Quartal ' . $year,
            VatRhythm::Month   => MonthNames::LONG[(int) $this->from->format('n') - 1] . ' ' . $year,
            VatRhythm::Year    => 'Jahr ' . $year,
        };
    }

    /** True when $date lies inside the period (both bounds inclusive, by day). */
    public function covers(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->from->format('Y-m-d') && $day <= $this->to->format('Y-m-d');
    }

    /** Same days (the rhythm and the short flag follow from them). */
    public function equals(self $other): bool
    {
        return $this->from->format('Y-m-d') === $other->from->format('Y-m-d')
            && $this->to->format('Y-m-d') === $other->to->format('Y-m-d');
    }
}
