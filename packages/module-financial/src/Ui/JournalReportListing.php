<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Reports\JournalSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;
use Z77\Module\Financial\Services\LedgerReports;

/**
 * The journal REPORT (Auswertungen › Journal) as a standard list (listing.md, owner 2026-10-15:
 * «erlaube hier das Sortieren und Suchen — serverseitig, bei x tausend Einträgen»): the columns
 * of the report — Datum · Nr. · Konto · Text · MWST · Soll · Haben —, five of them searched in
 * the database, four sorted; date ascending is the default (the journal's order). The search is
 * held to the report's range by {@see JournalSearch::within()} — the range form decides the
 * period, a searched date only narrows it. «Konto» finds an entry with a line of that account on
 * either side; «Soll» searches and sorts the entry total (Σ Soll = Σ Haben).
 */
final class JournalReportListing
{
    public const ID = 'journal-report-find';

    public static function definition(string $currency): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::search('f_date', 'Datum', '6rem', 'date', Parsers::dateRange()),
            Column::search('f_nr', 'Nr.', '4rem', 'number', Parsers::integer(9), numeric: true, inputMode: 'numeric'),
            Column::search('f_account', 'Konto', '5rem', null, self::accountParser(), inputMode: 'numeric'),
            Column::search('f_text', 'Text', 'minmax(10rem, 2fr)', 'text', Parsers::text()),
            Column::plain('MWST', 'minmax(8rem, 1fr)', priority: '2'),
            Column::search('f_amount', 'Soll', '8rem', 'amount', Parsers::amount($currency), numeric: true, descendingFirst: true),
            Column::plain('Haben', '8rem', numeric: true),
        ], 'date', LedgerReports::JOURNAL_PAGE_SIZE);
    }

    /** The column criteria for the repository — {@see LedgerReports::journal()} holds them to the range. */
    public static function search(ListState $state): JournalSearch
    {
        $date = $state->parsed('f_date');

        return new JournalSearch(
            number: $state->parsed('f_nr'),
            dateFrom: $date[0] ?? null,
            dateTo: $date[1] ?? null,
            text: $state->parsed('f_text'),
            amount: $state->parsed('f_amount'),
            sort: $state->sort,
            descending: $state->descending,
            account: $state->parsed('f_account'),
        );
    }

    /** «6500» or «6500 Büromaterial» → «6500», like the journal list's Soll / Haben. */
    private static function accountParser(): \Closure
    {
        return static fn(string $value): string => preg_split('/\s+/', $value)[0];
    }
}
