<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Module\Receipt\Repositories\ReceiptSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The «Belegjournal» as standard lists (listing.md; the board «Belegjournal», owner-approved
 * 2026-11-12, with E10's «Bezahlt mit»):
 *
 *   - {@see definition()} — tab «Im Journal» (`?view=pending`, the default): every CAPTURED
 *     receipt — state icon · Foto · Datum · Text · Betrag · Soll · Haben · MWST · Vorlage ·
 *     Bezahlt mit · Erfasst von · Zustand; date, text, amount and «Erfasst von» searched in the
 *     database ({@see ReceiptSearch}), sorted by date ascending (the posting order), 50 per page.
 *     On a narrow screen Haben, MWST and Vorlage go first, then Soll, «Bezahlt mit», «Erfasst von»;
 *   - {@see postedDefinition()} — tab «Verbuchte Journale» (`?view=posted`): the posting protocols,
 *     newest first — Verbucht · von · Belege · Total.
 */
final class ReceiptJournalListing
{
    public const ID        = 'receipt-journal-find';
    public const POSTED_ID = 'receipt-journal-posted';
    public const LIMIT     = 50;

    public const VIEW_PENDING = 'pending';
    public const VIEW_POSTED  = 'posted';
    public const VIEWS        = [self::VIEW_PENDING, self::VIEW_POSTED];

    public static function definition(string $currency): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Status'),
            Column::slot('Foto', '2.25rem'),
            Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange()),
            Column::search('f_text', 'Text', 'minmax(9rem, 2fr)', null, Parsers::text()),
            Column::search('f_amount', 'Betrag', '7rem', 'amount', Parsers::amount($currency), numeric: true, inputMode: 'decimal'),
            Column::plain('Soll', '4.5rem', priority: '2'),
            Column::plain('Haben', '4.5rem', priority: '3'),
            Column::plain('MWST', '4rem', priority: '3'),
            Column::plain('Vorlage', 'minmax(6rem, 1fr)', priority: '3'),
            Column::plain('Bezahlt mit', 'minmax(6rem, 1fr)', priority: '2'),
            Column::search('f_by', 'Erfasst von', 'minmax(8rem, 1.2fr)', null, Parsers::text(), priority: '2'),
            Column::plain('Zustand', '8rem'),
        ], 'date', self::LIMIT, ['view' => self::VIEWS]);
    }

    /** What the repository is asked for: the captured receipts, by date ascending unless the user sorts. */
    public static function search(ListState $state): ReceiptSearch
    {
        $date = $state->parsed('f_date');

        return new ReceiptSearch($date[0] ?? null, $date[1] ?? null, $state->parsed('f_text'), $state->parsed('f_amount'), $state->parsed('f_by'), $state->sort, $state->descending);
    }

    public static function postedDefinition(): ListDefinition
    {
        return new ListDefinition(self::POSTED_ID, [
            Column::plain('Verbucht', '9rem', 'posted', descendingFirst: true),
            Column::plain('von', 'minmax(8rem, 1fr)'),
            Column::plain('Belege', '6rem', numeric: true),
            Column::plain('Total', '8rem', numeric: true),
        ], 'posted', self::LIMIT, ['view' => self::VIEWS]);   // the same extra as the journal tab: every link of this list keeps `view=posted`
    }
}
