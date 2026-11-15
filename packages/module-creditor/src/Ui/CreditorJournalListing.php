<?php
namespace Z77\Module\Creditor\Ui;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorInvoiceSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The «Kreditorenjournal» as standard lists (listing.md, creditor plan §8):
 *
 *   - {@see definition()} — the tabs «Im Journal» (`?view=open`, the default) and «Gesperrt»
 *     (`?view=blocked`, the suspected duplicates): state icon · PDF · Kreditor · Rechnungs-Nr. ·
 *     Datum · fällig · Betrag · Konto · MWST · bezahlt am · Zustand («Im Journal» leads with the
 *     checkbox of the «Bezahlt am …» selection); creditor, number, date and
 *     amount searched in the database ({@see CreditorInvoiceSearch}), sorted by the invoice date
 *     (an invoice not read yet has none and stands first), 50 per page. On a narrow screen MWST,
 *     «fällig» and «bezahlt am» go first, then Konto;
 *   - {@see postedDefinition()} — tab «Verbuchte Journale» (`?view=posted`): the posting protocols,
 *     newest first — Verbucht · von · Rechnungen · Total (written by «Journal verbuchen»);
 *   - {@see intakeDefinition()} — tab «Nicht eingelesen» (`?view=intake`, stage 2): the folder files the
 *     intake refused, waiting in `failed/` — Eingang · Datei · Grund · «Erneut versuchen» /
 *     «Entfernen».
 *
 * The column «Erkannt» (stage 2) says where the values came from — «Text», «KI», «von Hand» — with
 * the recognition's warnings in its title.
 */
final class CreditorJournalListing
{
    public const ID        = 'creditor-journal-find';
    public const POSTED_ID = 'creditor-journal-posted';
    public const INTAKE_ID = 'creditor-journal-intake';
    public const LIMIT     = 50;

    public const VIEW_OPEN    = 'open';
    public const VIEW_BLOCKED = 'blocked';
    public const VIEW_POSTED  = 'posted';
    public const VIEW_INTAKE  = 'intake';
    public const VIEWS        = [self::VIEW_OPEN, self::VIEW_BLOCKED, self::VIEW_POSTED, self::VIEW_INTAKE];

    /** @param bool $selectable the tab «Im Journal»: a checkbox column first — the selection of «Bezahlt am …» */
    public static function definition(string $currency, bool $selectable = false): ListDefinition
    {
        return new ListDefinition(self::ID, [
            ...($selectable ? [Column::slot('Auswahl')] : []),
            Column::slot('Status'),
            Column::slot('PDF', '2.25rem'),
            Column::search('f_creditor', 'Kreditor', 'minmax(9rem, 2fr)', null, Parsers::text()),
            Column::search('f_number', 'Rechnungs-Nr.', 'minmax(6rem, 1fr)', null, Parsers::text()),
            Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange()),
            Column::plain('fällig', '6.5rem', priority: '3'),
            Column::search('f_amount', 'Betrag', '7rem', 'amount', Parsers::amount($currency), numeric: true, inputMode: 'decimal'),
            Column::plain('Konto', '4.5rem', priority: '2'),
            Column::plain('MWST', '4rem', priority: '3'),
            Column::plain('bezahlt am', '6.5rem', priority: '3'),
            Column::plain('Erkannt', '5.5rem', priority: '3'),
            Column::plain('Zustand', '9rem'),
        ], 'date', self::LIMIT, ['view' => self::VIEWS]);
    }

    /** What the repository is asked for: the invoices of the tab's state. */
    public static function search(ListState $state, string $view): CreditorInvoiceSearch
    {
        $date = $state->parsed('f_date');

        return new CreditorInvoiceSearch(
            $view === self::VIEW_BLOCKED ? CreditorInvoice::STATE_BLOCKED : CreditorInvoice::STATE_OPEN,
            $date[0] ?? null,
            $date[1] ?? null,
            $state->parsed('f_creditor'),
            $state->parsed('f_number'),
            $state->parsed('f_amount'),
            $state->sort,
            $state->descending,
        );
    }

    public static function postedDefinition(): ListDefinition
    {
        return new ListDefinition(self::POSTED_ID, [
            Column::plain('Verbucht', '9rem', 'posted', descendingFirst: true),
            Column::plain('von', 'minmax(8rem, 1fr)'),
            Column::plain('Rechnungen', '7rem', numeric: true),
            Column::plain('Total', '8rem', numeric: true),
        ], 'posted', self::LIMIT, ['view' => self::VIEWS]);
    }

    public static function intakeDefinition(): ListDefinition
    {
        return new ListDefinition(self::INTAKE_ID, [
            Column::plain('Eingang', '9rem', 'received'),
            Column::plain('Datei', 'minmax(10rem, 1.5fr)'),
            Column::plain('Grund', 'minmax(10rem, 2fr)'),
            Column::plain('Aktion', '15rem'),
        ], 'received', self::LIMIT, ['view' => self::VIEWS]);
    }
}
