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
