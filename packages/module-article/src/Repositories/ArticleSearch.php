<?php

namespace Z77\Module\Article\Repositories;

/**
 * What the article list asks the database for (Stammdaten › Artikel): the column criteria,
 * AND-ed — part of the article number, part of the name, the tax code, the revenue account, and
 * the product groups (a group WITH its sub-groups — the ids come from `ProductGroupTree::subtreeIds()`).
 * The article PICKER (`Ui\ArticlePicker`, 2026-10-19) asks with ONE search text instead — every
 * word a part of the number, the name or the description — and only what a new line may name
 * (`activeOnly`: active product, active default variant).
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
        /** @var list<int>|null the groups the product must hang in; null = any */
        public readonly ?array $groupIds = null,
        public readonly ?string $text = null,      // every word: part of the number, the name or the description
        public readonly bool $activeOnly = false,  // only active products with an active default variant
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown article sort '{$sort}'");
        }
    }
}
