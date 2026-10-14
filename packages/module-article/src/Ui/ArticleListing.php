<?php
namespace Z77\Module\Article\Ui;

use Z77\Module\Article\Repositories\ArticleSearch;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The article list as a standard list (listing.md, plan §9.5): state icon · Nr. · Name ·
 * Einheit · Preis · MWST · Konto · Bestand · Aktiv (the switch). Nr., Name, MWST and Konto search in the
 * database (number and name as a part, code and account exact); Nr. and Name sort; sorted by
 * number A–Z, 50 per page. The price shown is the one valid today — a column of the price
 * HISTORY, so it neither sorts nor searches.
 */
final class ArticleListing
{
    public const ID = 'article-find';

    public const LIMIT = 50;

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Bearbeiten'),
            Column::search('f_number', 'Nr.', '7rem', 'number', Parsers::text()),
            Column::search('f_name', 'Bezeichnung', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
            Column::plain('Einheit', '4.5rem', priority: '2'),
            Column::plain('Preis', '8rem', numeric: true),
            Column::search('f_tax', 'MWST', '4.5rem', null, Parsers::text(), priority: '3'),
            Column::search('f_account', 'Konto', '5rem', null, Parsers::text(), priority: '3'),
            Column::plain('Bestand', '4.5rem', priority: '2'),
            Column::plain('Aktiv', '3.5rem'),
        ], 'number', self::LIMIT);
    }

    /** What the repository is asked for. */
    public static function search(ListState $state): ArticleSearch
    {
        $text = static fn(?string $v): ?string => $v === null || trim($v) === '' ? null : trim($v);

        return new ArticleSearch(
            $text($state->parsed('f_number')),
            $text($state->parsed('f_name')),
            ($code = $text($state->parsed('f_tax'))) !== null ? mb_strtoupper($code) : null,
            $text($state->parsed('f_account')),
            $state->sort,
            $state->descending,
        );
    }
}
