<?php

namespace Z77\Module\Financial\Repositories;

/**
 * What the change log screen searches for (Finanzen › Änderungsprotokoll,
 * owner 2026-10-08) — one value per column magnifier, each optional, all of
 * them combined (AND). Built from the list state by
 * {@see \Z77\Module\Financial\Ui\ChangeLogListing::search()}; read by
 * {@see EntryChangeRepository::search()} / {@see EntryChangeRepository::countSearch()}.
 *
 * Every criterion is already PARSED — a day range instead of «10.2032» — so
 * the repository binds values and never interprets input.
 */
final class EntryChangeSearch
{
    /** Columns the list can sort by — the key is the URL value, the ORDER BY is the repository's. */
    public const SORTS = ['at', 'number', 'text', 'who'];

    /**
     * @param int|null    $fiscalYearId  the entry's fiscal year; null = every year
     * @param string|null $from          `Y-m-d`, inclusive — the day of the change
     * @param string|null $to            `Y-m-d`, inclusive
     * @param int|null    $number        the entry number, exact
     * @param string|null $who           contained in the name of who changed it
     * @param string|null $text          contained in the entry text before OR after the change
     * @param string      $sort          one of {@see SORTS}
     */
    public function __construct(
        public readonly ?int $fiscalYearId = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $number = null,
        public readonly ?string $who = null,
        public readonly ?string $text = null,
        public readonly string $sort = 'at',
        public readonly bool $descending = true,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown change log sort '{$sort}'");
        }
    }
}
