<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Repositories\InvoiceSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The document list as a standard list (listing.md; P3 part 3, on the
 * shared block since 2026-10-08): the VIEW (`?view=invoicing|final|credit`)
 * as an extra, the columns Nr. · Datum · Name · Fällig · Betrag — four of
 * them searched in the database, strictly parsed — sorted by number newest
 * first, 50 per page. The invoicing view leads with the selection checkbox
 * of «Definitiv stellen …».
 */
final class InvoiceListing
{
    public const ID = 'invoice-find';

    public const LIMIT = 50;

    public static function definition(string $currency, bool $selectable): ListDefinition
    {
        $columns = $selectable ? [Column::slot('Auswahl')] : [];
        $columns = array_merge($columns, [
            Column::slot('Status'),
            Column::search('f_nr', 'Nr.', '5rem', 'number', Parsers::integer(9), numeric: true, descendingFirst: true),
            Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange(), priority: '3', descendingFirst: true),
            Column::search('f_name', 'Name', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
            Column::plain('Fällig', '6.5rem', priority: '2'),
            Column::search('f_amount', 'Betrag', '8rem', 'amount', Parsers::amount($currency), numeric: true, descendingFirst: true, inputMode: 'decimal'),
        ]);

        return new ListDefinition(self::ID, $columns, 'number', self::LIMIT, ['view' => InvoiceSearch::VIEWS]);
    }

    /** What the repository is asked for. */
    public static function search(ListState $state): InvoiceSearch
    {
        $date = $state->parsed('f_date');

        return new InvoiceSearch($state->extra('view'), $state->parsed('f_nr'), $date[0] ?? null, $date[1] ?? null, $state->parsed('f_name'), $state->parsed('f_amount'), $state->sort, $state->descending);
    }
}
