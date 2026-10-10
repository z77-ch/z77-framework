<?php

namespace Z77\Module\Debtor\Invoicing;

/**
 * The number of a debtor document — invoice, credit note and fee share ONE
 * series (owner 2026-10-10). Pure functions.
 *
 * Format `YYMMnnnn`, stored and printed as a plain integer:
 *
 *     YY    the two-digit year of the INVOICE DATE (the date printed on
 *           the document — not the day it is finalized, not the day it is
 *           typed: a March-dated document issued in April continues March)
 *     MM    the month of the invoice date
 *     nnnn  the running number within that month, from 0001, gapless
 *
 * The 3rd document dated March 2026 is 26030003. At most 9999 documents per
 * month: the 10000th is REFUSED ({@see compose()} throws), never carried
 * into the next month's digits. Independent of any order number.
 *
 * Each month is its own gapless `NumberRange`, named `document.YYMM`
 * ({@see range()}, the `journal-entry.{code}` pattern of the ledger) — the
 * range hands out the bare `nnnn`, the year-month prefix belongs to the
 * document (`persistence-doctrine.md`: prefix and year segment never in
 * `number_range`). `InvoicingService::invoice()` creates the month's range
 * BEFORE its unit of work (DOCTRINE-NR-003) and draws from it as the FIRST
 * write of it.
 *
 * Years 2000–2099 only: `YY` would repeat after 2099 (2126 and 2026 would
 * share `26…`), and the unique index on the number refuses that anyway.
 * Numbers issued before 2026-10-10 (the former per-kind ranges `invoice` /
 * `credit-note`, 1, 2, …) stay as they are — {@see isOfMonth()} tells a
 * number of this format from them.
 */
final class DocumentNumber
{
    /** The prefix of the per-month `NumberRange`: `document.2603`. */
    public const RANGE_PREFIX = 'document.';

    /** The highest running number of one month (`nnnn`). */
    public const PER_MONTH = 9999;

    /** `YYMM * 10000 + nnnn`. */
    private const MONTH_FACTOR = 10000;

    private const FIRST_YEAR = 2000;
    private const LAST_YEAR  = 2099;

    /**
     * The `NumberRange` the documents dated in $invoiceDate's month draw
     * from — `document.2603` for any day of March 2026.
     *
     * @throws \InvalidArgumentException an invoice date outside 2000–2099
     */
    public static function range(\DateTimeImmutable $invoiceDate): string
    {
        return self::RANGE_PREFIX . self::period($invoiceDate);
    }

    /**
     * The document number of running number $sequence in $invoiceDate's
     * month: `YYMM * 10000 + $sequence`.
     *
     * @throws \InvalidArgumentException an invoice date outside 2000–2099, a sequence below 1
     * @throws \OverflowException        the month is full — more than {@see PER_MONTH} documents
     */
    public static function compose(\DateTimeImmutable $invoiceDate, int $sequence): int
    {
        $period = self::period($invoiceDate);
        if ($sequence < 1) {
            throw new \InvalidArgumentException("A running document number starts at 1, got {$sequence}");
        }
        if ($sequence > self::PER_MONTH) {
            throw new \OverflowException(
                "Document number range " . self::RANGE_PREFIX . "{$period} is exhausted: at most " . self::PER_MONTH
                . " documents per month, running number {$sequence} requested"
            );
        }

        return (int) $period * self::MONTH_FACTOR + $sequence;
    }

    /**
     * Whether $number is a number of this format for $date's month — false
     * for a number of the former per-kind ranges (1, 2, …) and for any other
     * month.
     */
    public static function isOfMonth(int $number, \DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            return false;
        }
        $sequence = $number % self::MONTH_FACTOR;

        return intdiv($number, self::MONTH_FACTOR) === (int) $date->format('ym') && $sequence >= 1;
    }

    /** `YYMM` of the date, as four digits. */
    private static function period(\DateTimeImmutable $date): string
    {
        $year = (int) $date->format('Y');
        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
            throw new \InvalidArgumentException(
                "A document number carries the year in two digits: the invoice date must lie in "
                . self::FIRST_YEAR . '–' . self::LAST_YEAR . ", got {$date->format('Y-m-d')}"
            );
        }

        return $date->format('ym');
    }
}
