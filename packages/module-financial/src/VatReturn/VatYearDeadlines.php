<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\FiscalYear;

/**
 * The deadlines of a tax year — pure date arithmetic, nothing read (ADR-042 addendum 2 of
 * 2026-11-25, point 6; S3-8):
 *
 *   - **day 180 / day 240** after the end of the business year — corrections belong at the latest
 *     into the return of the period day 180 falls into, and after day 240 the ESTV takes the tax
 *     year as fully declared (Art. 72 MWSTG, as the decision notes give it). Stated ONLY when the
 *     fiscal year holding 31 December of the tax year IS the calendar year (01.01.–31.12.); for
 *     any other fiscal year — a business year from 1 July, a short or extended first year — z77
 *     states no deadline it cannot state reliably: {@see UNKNOWN}. Day N = 31 December + N days
 *     (tax year 2026: day 180 = 29.06.2027, day 240 = 28.08.2027).
 *   - **the due date of a return** — the end of its period + 60 days (Art. 71 MWSTG): 3rd quarter
 *     2026 → 29.11.2026, 4th quarter 2026 → 01.03.2027.
 *   - **before / after day 240** of a reported correction block — the date the carrying return was
 *     posted (posted means filed); day 240 itself counts as «vor».
 *
 * Immutable. No interest is computed (small rule 1 of S7 — the help says when the ESTV charges it).
 */
final class VatYearDeadlines
{
    /** What the screen says instead of the two days when the fiscal year is not the calendar year. */
    public const UNKNOWN = 'Frist bei der ESTV klären';

    /** Days from the end of a period to the due date of its return (Art. 71 MWSTG). */
    public const DUE_DAYS = 60;

    public const DAY_180 = 180;
    public const DAY_240 = 240;

    /**
     * @param bool $known true when the fiscal year holding 31.12.{$taxYear} is the calendar year
     */
    public function __construct(
        public readonly int $taxYear,
        public readonly bool $known,
    ) {}

    /**
     * The deadlines of $taxYear given the fiscal year holding its 31 December (null: none opened —
     * nothing is assumed, the deadlines are unknown).
     */
    public static function of(int $taxYear, ?FiscalYear $yearEnd): self
    {
        return new self($taxYear, $yearEnd !== null
            && $yearEnd->getStartDate()?->format('Y-m-d') === "{$taxYear}-01-01"
            && $yearEnd->getEndDate()?->format('Y-m-d') === "{$taxYear}-12-31");
    }

    /** Day 180 after 31.12. of the tax year — null when the fiscal year is not the calendar year. */
    public function day180(): ?\DateTimeImmutable
    {
        return $this->day(self::DAY_180);
    }

    /** Day 240 after 31.12. of the tax year — null when the fiscal year is not the calendar year. */
    public function day240(): ?\DateTimeImmutable
    {
        return $this->day(self::DAY_240);
    }

    /**
     * Was a correction reported (its carrying return posted on $reportedOn) on or before day 240?
     * Null when the deadlines are unknown (no «vor / nach» is shown then — small rule 6).
     */
    public function beforeDay240(\DateTimeImmutable $reportedOn): ?bool
    {
        $day240 = $this->day240();

        return $day240 === null ? null : $reportedOn->format('Y-m-d') <= $day240->format('Y-m-d');
    }

    /** «vor dem 240. Tag» / «nach dem 240. Tag». */
    public static function relationLabel(bool $beforeDay240): string
    {
        return $beforeDay240 ? 'vor dem 240. Tag' : 'nach dem 240. Tag';
    }

    /** The due date of the return of $period: its last day + 60 days. */
    public static function dueDate(VatPeriod $period): \DateTimeImmutable
    {
        return $period->to->setTime(0, 0)->modify('+' . self::DUE_DAYS . ' days');
    }

    private function day(int $days): ?\DateTimeImmutable
    {
        return $this->known ? (new \DateTimeImmutable("{$this->taxYear}-12-31"))->modify("+{$days} days") : null;
    }
}
