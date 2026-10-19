<?php
/**
 * Artikel — the product-group FILTER in the toolbar (hc2, added by `listAction()`; owner
 * 2026-10-18 — a list filter is not an area choice, so it left the top of the rail,
 * ARTICLE-GROUP-TREE-UI-001 b). The shared tree picker (`Z77\Shared` `partials/treePicker`):
 * a field «WARENGRUPPE» with the chosen group's path, a popover that searches all levels and
 * walks one level at a time; without the script the indented list of links. Every group is a
 * plain link to the list with `?group=<id>` — the list's extra `group`, so the choice IS the
 * address (bookmarkable, validated by the list definition). A group shows its articles
 * INCLUDING its sub-groups, and so does its count.
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
if (!isset($tree, $state) || $tree->isEmpty()) { return; }
$listUrl = ($actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE) . '/list';
$counts  = $counts ?? [];
$nodes   = [];
foreach ($tree->rows() as $row) {
    $node    = $row['group'];
    $id      = (int) $node->getId();
    $nodes[] = [
        'id'     => $id,
        'parent' => $node->getParentId(),
        'name'   => $node->getName(),
        'count'  => $counts[$id] ?? 0,
        'note'   => $node->isActive() ? '' : 'inaktiv',
    ];
}
?>
<?= $this->partial('partials/treePicker', [
    'id'       => 'article-group-picker',
    'label'    => 'Warengruppe',
    'allLabel' => 'Alle Warengruppen',
    'nodes'    => $nodes,
    'value'    => $group ?? null,
    'href'     => static fn(int|string|null $id): string => rtrim($listUrl . '?' . $state->query(['group' => $id]), '?'),
    'allCount' => $counts === [] ? null : array_sum(array_map(static fn(array $r) => $r['depth'] === 0 ? ($counts[(int) $r['group']->getId()] ?? 0) : 0, $tree->rows())),
    'labels'   => [
        'search' => 'Gruppe suchen …',
        'none'   => 'Keine Gruppe gefunden.',
        'level'  => '%d Untergruppen',
        'foot'   => 'inkl. Untergruppen',
        'clear'  => 'Filter aufheben',
    ],
], 'Z77\Shared') ?>
