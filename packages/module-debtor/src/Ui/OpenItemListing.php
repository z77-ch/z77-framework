<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Repositories\OpenItemSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The open-item list «Debitoren» as a standard list (listing.md; built
 * 2026-10-07 after wdv-630, on the shared block since 2026-10-08): the
 * VIEW (`?view=open|overdue|all`) as an extra, the columns Status · Re-Nr.
 * · KD-Nr. · Name · fakturiert · bezahlt · offen · Re-Datum · Fällig —
 * seven of them searched in the database, strictly parsed — sorted by the
 * due date oldest first, 50 per page.
 */
final class OpenItemListing
{
    public const ID = 'debtor-find';

    public const LIMIT = 50;

    public static function definition(string $currency): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Beleg öffnen'),
            Column::plain('Status', '7.5rem'),
            Column::search('f_nr', 'Re-Nr.', '5.5rem', 'number', Parsers::integer(10), numeric: true, descendingFirst: true),
            Column::search('f_customer', 'KD-Nr.', '4.5rem', 'customer', Parsers::integer(6), numeric: true, priority: '2'),
            Column::search('f_name', 'Name', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
            Column::search('f_gross', 'fakturiert', '7rem', 'gross', Parsers::amount($currency), numeric: true, priority: '2', descendingFirst: true, inputMode: 'decimal'),
            Column::plain('bezahlt', '7rem', numeric: true, priority: '3'),
            Column::search('f_open', 'offen', '7rem', 'open', Parsers::amount($currency), numeric: true, descendingFirst: true, inputMode: 'decimal'),
            Column::search('f_date', 'Re-Datum', '6.5rem', 'date', Parsers::dateRange(), priority: '3', descendingFirst: true),
            Column::search('f_due', 'Fällig', '6.5rem', 'due', Parsers::dateRange()),
            Column::slot('Aktion', '5.5rem'),
        ], 'due', self::LIMIT, ['view' => OpenItemSearch::VIEWS]);
    }

    /** What the repository is asked for, as of $today (the overdue line). */
    public static function search(ListState $state, \DateTimeImmutable $today): OpenItemSearch
    {
        $date = $state->parsed('f_date');
        $due  = $state->parsed('f_due');

        return new OpenItemSearch(
            $today,
            $state->extra('view'),
            $state->parsed('f_nr'),
            $state->parsed('f_customer'),
            $state->parsed('f_name'),
            $state->parsed('f_gross'),
            $state->parsed('f_open'),
            $date[0] ?? null,
            $date[1] ?? null,
            $due[0] ?? null,
            $due[1] ?? null,
            $state->sort,
            $state->descending,
        );
    }
}
