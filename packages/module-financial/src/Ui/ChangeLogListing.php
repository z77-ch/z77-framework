<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Repositories\EntryChangeSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The change log «Änderungsprotokoll» as a standard list (listing.md, owner
 * 2026-10-08: «das sind die protokollierten Mutationen, nur ein Protokoll …
 * es geht nur darum, etwas nachvollziehen zu können»): one row per
 * {@see \Z77\Module\Financial\Entities\EntryChange} — every edit and every
 * delete of a journal entry. Columns: the state icon (opens the change as a
 * window) · Zeitpunkt · Nr. · Art · Text · Wer — four of them searched in the
 * database, strictly parsed; newest change first, 50 per page. The extra
 * `all` is «Alle Jahre» of the year selection at the top of the rail.
 */
final class ChangeLogListing
{
    public const ID = 'change-log-find';

    public const LIMIT = 50;

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Änderung öffnen'),
            Column::search('f_at', 'Zeitpunkt', '8.5rem', 'at', Parsers::dateRange(), descendingFirst: true),
            Column::search('f_nr', 'Nr.', '5rem', 'number', Parsers::integer(9), numeric: true, descendingFirst: true, inputMode: 'numeric'),
            Column::plain('Art', '6rem', priority: '2'),
            Column::search('f_text', 'Text', 'minmax(10rem, 2fr)', 'text', Parsers::text()),
            Column::search('f_who', 'Wer', '8rem', 'who', Parsers::text(), priority: '3'),
        ], 'at', self::LIMIT, ['all' => ['', '1']]);
    }

    /** What the repository is asked for — scoped to $fiscalYearId unless «Alle Jahre» is on. */
    public static function search(ListState $state, ?int $fiscalYearId): EntryChangeSearch
    {
        $at = $state->parsed('f_at');

        return new EntryChangeSearch(
            $state->flag('all') ? null : $fiscalYearId,
            $at[0] ?? null,
            $at[1] ?? null,
            $state->parsed('f_nr'),
            $state->parsed('f_who'),
            $state->parsed('f_text'),
            $state->sort,
            $state->descending,
        );
    }
}
