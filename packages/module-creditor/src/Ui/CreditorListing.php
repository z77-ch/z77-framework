<?php
namespace Z77\Module\Creditor\Ui;

use Z77\Module\Creditor\Repositories\CreditorSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * «Kreditoren» as a standard list (listing.md, backend-screen.md §4): state icon · Kreditor · UID ·
 * IBAN · Konto · MWST · Bezahlt mit · Aktiv. Name, UID and IBAN searched in the database
 * ({@see CreditorSearch}), sorted by name, 50 per page. On a narrow screen MWST and «Bezahlt mit» go
 * first, then UID and Konto. The invoice count arrives with the creditor invoice (stage 1b).
 */
final class CreditorListing
{
    public const ID    = 'creditor-find';
    public const LIMIT = 50;

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Bearbeiten'),
            Column::search('f_name', 'Kreditor', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
            Column::search('f_uid', 'UID', '9.5rem', null, Parsers::text(), priority: '2'),
            Column::search('f_iban', 'IBAN', 'minmax(12rem, 1.6fr)', null, Parsers::text()),
            Column::plain('Konto', '5rem', priority: '2'),
            Column::plain('MWST', '4.5rem', priority: '3'),
            Column::plain('Bezahlt mit', 'minmax(6rem, 1fr)', priority: '3'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'name', self::LIMIT);
    }

    /** What the repository is asked for. */
    public static function search(ListState $state): CreditorSearch
    {
        return new CreditorSearch($state->parsed('f_name'), $state->parsed('f_uid'), $state->parsed('f_iban'), $state->sort, $state->descending);
    }
}
