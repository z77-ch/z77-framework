<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Module\Debtor\Repositories\InvoiceSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The Faktura-Journal as standard lists (listing.md; ADR-054, step 3 of the build plan, owner
 * 2026-10-23):
 *
 *   - {@see definition()} — tab «Im Journal» (`?view=pending`, the default): every document in
 *     state `issued` (credit notes included) — state icon · Nr. · Datum · Kunde · Betreff · Netto ·
 *     MWST · Total · ID · Fakturiert · Hinweis; number, date, customer and amount searched in the
 *     database (the document list's `InvoiceSearch`, view `journal`), sorted by number ascending
 *     (the posting order), 50 per page;
 *   - {@see postedDefinition()} — tab «Verbuchte Journale» (`?view=posted`): the posting
 *     protocols, newest first — Verbucht · von · Dokumente · Nummern · Total · the journal list.
 */
final class InvoiceJournalListing
{
    public const ID        = 'invoice-journal-find';
    public const POSTED_ID = 'invoice-journal-posted';
    public const LIMIT     = 50;

    public const VIEW_PENDING = 'pending';
    public const VIEW_POSTED  = 'posted';
    public const VIEWS        = [self::VIEW_PENDING, self::VIEW_POSTED];

    public static function definition(string $currency): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Status'),
            Column::search('f_nr', 'Nr.', '6rem', 'number', Parsers::integer(9), numeric: true),
            Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange(), descendingFirst: true),
            Column::search('f_name', 'Kunde', 'minmax(9rem, 2fr)', 'name', Parsers::text()),
            Column::plain('Betreff', 'minmax(8rem, 2fr)', priority: '3'),
            Column::plain('Netto', '7rem', numeric: true, priority: '2'),
            Column::plain('MWST', '6rem', numeric: true, priority: '2'),
            Column::search('f_amount', 'Total', '7.5rem', 'amount', Parsers::amount($currency), numeric: true, descendingFirst: true, inputMode: 'decimal'),
            Column::plain('ID', '6.5rem', priority: '3'),
            Column::plain('Fakturiert', '8.5rem', priority: '3'),
            Column::plain('Hinweis', 'minmax(8rem, 1.5fr)'),
        ], 'number', self::LIMIT, ['view' => self::VIEWS]);
    }

    /** What the repository is asked for: the journal view of the document search, ascending by number unless the user sorts. */
    public static function search(ListState $state): InvoiceSearch
    {
        $date = $state->parsed('f_date');

        return new InvoiceSearch(InvoiceSearch::VIEW_JOURNAL, $state->parsed('f_nr'), $date[0] ?? null, $date[1] ?? null, $state->parsed('f_name'), $state->parsed('f_amount'), $state->sort, $state->descending);
    }

    public static function postedDefinition(): ListDefinition
    {
        return new ListDefinition(self::POSTED_ID, [
            Column::plain('Verbucht', '9rem', 'posted', descendingFirst: true),
            Column::plain('von', 'minmax(8rem, 1fr)'),
            Column::plain('Dokumente', '6rem', numeric: true),
            Column::plain('Nummern', 'minmax(9rem, 1fr)', priority: '2'),
            Column::plain('Total', '8rem', numeric: true),
            Column::slot('Journalliste (PDF)'),
        ], 'posted', self::LIMIT, ['view' => self::VIEWS]);   // the same extra as the journal tab: every link of this list keeps `view=posted`
    }
}
