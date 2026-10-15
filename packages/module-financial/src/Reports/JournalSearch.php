<?php

namespace Z77\Module\Financial\Reports;

/**
 * What the journal list searches for (FIN-JOURNAL-CAPTURE-001) — one value per
 * column magnifier, each optional, all of them combined (AND). Built from the
 * request by {@see \Z77\Module\Financial\Ui\JournalFilter} (the list) and
 * {@see \Z77\Module\Financial\Ui\JournalReportListing} (the journal report,
 * held to its range by {@see within()}); read by
 * {@see \Z77\Module\Financial\Repositories\JournalEntryRepository::search()}.
 *
 * Every criterion is already PARSED here — a date range instead of «09.2032»,
 * an amount as a decimal string — so the repository binds values and never
 * interprets input.
 */
final class JournalSearch
{
    /** Columns the list can sort by — the key is the URL value, the rest is the repository's business. */
    public const SORTS = ['number', 'date', 'text', 'amount'];

    /**
     * @param int|null    $fiscalYearId  null = every year
     * @param int|null    $number        exact journal number
     * @param string|null $dateFrom      `Y-m-d`, inclusive (a day, a month or a year entered)
     * @param string|null $dateTo        `Y-m-d`, inclusive
     * @param string|null $text          contained in the entry text
     * @param string|null $debitAccount  an account number that has a DEBIT line in the entry
     * @param string|null $creditAccount an account number that has a CREDIT line in the entry
     * @param string|null $amount        the entry total (Σ debit), decimal `1250.50`
     * @param string      $sort          one of {@see SORTS}
     * @param bool        $descending
     * @param string|null $account       an account number with a line on EITHER side (the journal report's «Konto»)
     */
    public function __construct(
        public readonly ?int $fiscalYearId = null,
        public readonly ?int $number = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?string $text = null,
        public readonly ?string $debitAccount = null,
        public readonly ?string $creditAccount = null,
        public readonly ?string $amount = null,
        public readonly string $sort = 'number',
        public readonly bool $descending = true,
        public readonly ?string $account = null,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown journal sort '{$sort}'");
        }
    }

    /**
     * This search held inside one fiscal year and the dates from–to (`Y-m-d`, inclusive) — the
     * journal report's range: a searched date narrows within it, never widens it (a date outside
     * finds nothing).
     */
    public function within(int $fiscalYearId, string $from, string $to): self
    {
        return new self(
            $fiscalYearId,
            $this->number,
            $this->dateFrom !== null && $this->dateFrom > $from ? $this->dateFrom : $from,
            $this->dateTo !== null && $this->dateTo < $to ? $this->dateTo : $to,
            $this->text,
            $this->debitAccount,
            $this->creditAccount,
            $this->amount,
            $this->sort,
            $this->descending,
            $this->account,
        );
    }
}
