<?php

namespace Z77\Module\Article\Repositories;

/**
 * What the article list asks the database for (Stammdaten › Artikel): the column criteria,
 * AND-ed — part of the article number, part of the name, the tax code, the revenue account.
 * Every value is bound; the sort key selects from a fixed map ({@see ProductRepository::search()}),
 * never from input. Built by the screen's listing (`Ui\ArticleListing`, listing.md), plain data.
 */
final class ArticleSearch
{
    public const SORTS = ['number', 'name'];

    public function __construct(
        public readonly ?string $number = null,    // part of the article number
        public readonly ?string $name = null,      // part of the name
        public readonly ?string $taxCode = null,   // the code, exact
        public readonly ?string $account = null,   // the revenue account, exact
        public readonly string $sort = 'number',
        public readonly bool $descending = false,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown article sort '{$sort}'");
        }
    }
}
