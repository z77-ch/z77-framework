<?php
namespace Z77\Module\Article\Ui;

use Z77\Module\Article\Entities\Variant;
use Z77\Module\Article\Repositories\ArticleSearch;
use Z77\Module\Article\Services\ProductGroupTree;

/**
 * «Artikel wählen …» — the article of a document LINE chosen in a searching window, a building
 * block (Rule 9) a source mounts with {@see ArticlePickerTrait} (owner 2026-10-19; the first user
 * is module-order's order window — article knows no order: the source hands in the price of each
 * row and decides what a choice does). The model is debtor's customer picker: a window over the
 * editor, the list a fetch region inside it.
 *
 *   - the toolbar: the product-group filter (the module's shared `partials/productGroupPicker` —
 *     the article list's own) and ONE search field (every word a part of the number, the name or
 *     the description, {@see ArticleSearch::$text});
 *   - a COMPACT list: Nr. · Bezeichnung · Einheit · Preis — only what a new line may name (active
 *     product, active default variant: {@see isChoosable()}), by number, at most {@see LIMIT}
 *     rows («Suche verfeinern» beyond); the price is the SOURCE's (an order: valid at the order
 *     date, in the order's price mode);
 *   - the keys (core.js «pick list»): typing searches, ↑/↓ move, Enter picks, Esc closes.
 *
 * The state is the address: `q` (the search), `group` (a group of the tree, with its sub-groups),
 * `target` (the DOM id of the field the choice goes to — '' = a new row) and the source's
 * context keys (an order: `order_date`, `service_from`, `price_mode`). Pure helpers here, no
 * database.
 */
final class ArticlePicker
{
    public const NS = 'Z77\\Module\\Article';

    /** The fetch region of the list inside the window. */
    public const REGION = 'article-pick-list';

    /** At most this many rows — the picker searches, it does not page. */
    public const LIMIT = 50;

    /** The longest search text taken. */
    public const QUERY_LENGTH = 100;

    /** The search text of the request: trimmed, at most {@see QUERY_LENGTH} characters. */
    public static function query(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, self::QUERY_LENGTH) : '';
    }

    /** The chosen group when it is one of the tree's, else null (all). */
    public static function group(mixed $value, ProductGroupTree $tree): ?int
    {
        return is_string($value) && ctype_digit($value) && $tree->find((int) $value) !== null ? (int) $value : null;
    }

    /**
     * The DOM id a choice goes to — a plain id or '' (a new row). Never a selector: the answer
     * builds `#<id>` from it, so anything else is dropped.
     */
    public static function target(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) === 1 ? $value : '';
    }

    /** A context value of the source (a date, a mode) — short and plain, else ''. The source judges it. */
    public static function contextValue(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{0,32}$/', trim($value)) === 1 ? trim($value) : '';
    }

    /** What the list asks the database for: the search text, the group WITH its sub-groups, choosable only. */
    public static function search(string $query, ?int $group, ProductGroupTree $tree): ArticleSearch
    {
        return new ArticleSearch(
            groupIds: $group !== null ? $tree->subtreeIds($group) : null,
            text: $query === '' ? null : $query,
            activeOnly: true,
        );
    }

    /**
     * Whether $variant may be named on a NEW line — the rule of the list's WHERE, asked again for
     * the number a form sends back (never trust the row that was shown).
     */
    public static function isChoosable(?Variant $variant): bool
    {
        return $variant !== null && $variant->isActive() && $variant->getProduct()->isActive();
    }

    /**
     * The picker's address with $state (q, group, target, the context — empty values left out).
     *
     * @param array<string, string|int|null> $state
     */
    public static function url(string $pickUrl, array $state): string
    {
        $query = http_build_query(array_filter($state, static fn($v) => $v !== null && $v !== ''));

        return $query === '' ? $pickUrl : $pickUrl . '?' . $query;
    }
}
