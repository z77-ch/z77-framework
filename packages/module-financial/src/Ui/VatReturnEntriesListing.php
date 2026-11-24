<?php
namespace Z77\Module\Financial\Ui;

use Z77\Module\Financial\Repositories\VatReturnLineSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * «Buchungen einer Ziffer» — the coded journal lines that make up one figure of the VAT return, as
 * a standard list (listing.md; screen decision 9 of the review, 2026-11-24): state icon (opens the
 * entry), document icon, Datum · Nr. · Text searched in the database, Konto · Umsatz · Steuer
 * sorted, Code · Satz · Herkunft shown. 50 per page.
 *
 * The origin and the account the tab «Herkunft» opened the list with are PATTERN extras (listing.md,
 * the outbox's `batch`): `origin` a display origin key (`invoice`, `manual`, …), `account` an account
 * id. They narrow the service's search ({@see \Z77\Module\Financial\VatReturn\VatReturnService::lineSearch()}),
 * so the total equals the node of «Herkunft»; the screen shows each as a chip whose × drops it.
 * The field and the period are the PAGE's (`field`, `from`), carried as `keep`.
 */
final class VatReturnEntriesListing
{
    public const ID = 'vat-return-entries';

    public const PAGE_SIZE = 50;

    /** A display origin key ({@see \Z77\Module\Financial\VatReturn\VatReturnSources::key()}) — a source type or `manual`. */
    public const ORIGIN_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /** An account id. */
    public const ACCOUNT_PATTERN = '/^[1-9][0-9]{0,9}$/';

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Buchung öffnen'),
            Column::slot('Dokument'),
            Column::search('f_date', 'Datum', '7.5rem', 'date', Parsers::dateRange()),
            Column::search('f_nr', 'Nr.', '6.5rem', 'number', Parsers::integer(9), numeric: true, priority: '2', inputMode: 'numeric'),
            Column::search('f_text', 'Text', 'minmax(8rem, 1fr)', 'text', Parsers::text()),
            Column::plain('Konto', '5rem', 'account', priority: '2'),
            Column::plain('Code', '3.5rem', priority: '3'),
            Column::plain('Satz', '4rem', numeric: true, priority: '3'),
            Column::plain('Umsatz', '7rem', 'base', numeric: true, priority: '2', descendingFirst: true),
            Column::plain('Steuer', '6.5rem', 'tax', numeric: true, descendingFirst: true),
            Column::plain('Herkunft', '7rem', priority: '3'),
        ], 'date', self::PAGE_SIZE, ['origin' => self::ORIGIN_PATTERN, 'account' => self::ACCOUNT_PATTERN]);
    }

    /** The service's search of the node, narrowed by the list's column searches and sort. */
    public static function search(ListState $state, VatReturnLineSearch $node): VatReturnLineSearch
    {
        $date = $state->parsed('f_date');

        return $node->narrowed($date[0] ?? null, $date[1] ?? null, $state->parsed('f_nr'), $state->parsed('f_text'), $state->sort, $state->descending);
    }

    /** The origin extra, null = every origin of the field. */
    public static function origin(ListState $state): ?string
    {
        return $state->extra('origin') === '' ? null : $state->extra('origin');
    }

    /** The account extra, null = every account. */
    public static function accountId(ListState $state): ?int
    {
        return $state->extra('account') === '' ? null : (int) $state->extra('account');
    }
}
