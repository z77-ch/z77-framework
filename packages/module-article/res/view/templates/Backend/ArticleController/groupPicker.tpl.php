<?php
/**
 * Artikel — the product-group FILTER in the toolbar (hc2, added by `listAction()`; owner
 * 2026-10-18 — a list filter is not an area choice, so it left the top of the rail,
 * ARTICLE-GROUP-TREE-UI-001 b). The module's shared group picker (`partials/productGroupPicker`
 * over the kernel's `partials/treePicker` — the article picker's window uses the same): a field
 * «WARENGRUPPE» with the chosen group's path, a popover that searches all levels and walks one
 * level at a time; without the script the indented list of links. Every group is a plain link to
 * the list with `?group=<id>` — the list's extra `group`, so the choice IS the address
 * (bookmarkable, validated by the list definition). A group shows its articles INCLUDING its
 * sub-groups, and so does its count.
 *
 * The other state of the list (search, sort) travels along (`ListState::query()`); the page
 * starts at 1 again.
 *
 * @var \Z77\Module\Article\Services\ProductGroupTree $tree
 * @var \Z77\Shared\Listing\ListState $state
 * @var int|null $group               the chosen group, null = all
 * @var array<int, int> $counts       group id → articles incl. sub-groups
 * @var string $actionBase
 */
if (!isset($tree, $state)) { return; }
$listUrl = ($actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE) . '/list';
?>
<?= $this->partial('partials/productGroupPicker', [
    'tree'   => $tree,
    'id'     => 'article-group-picker',
    'group'  => $group ?? null,
    'counts' => $counts ?? [],
    'href'   => static fn(int|string|null $id): string => rtrim($listUrl . '?' . $state->query(['group' => $id]), '?'),
], 'Z77\Module\Article') ?>
