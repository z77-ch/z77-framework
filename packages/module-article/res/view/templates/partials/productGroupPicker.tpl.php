<?php
/**
 * The product-group FILTER as a field — THE one way to choose a group to filter by, shared by
 * the article list's toolbar (`Backend/ArticleController/groupPicker`, owner 2026-10-18) and the
 * article picker's window (`Backend/ArticlePicker/pick`, owner 2026-10-19; Rule 9). It builds
 * the nodes of the shared tree picker (`Z77\Shared` `partials/treePicker`) from the product-group
 * tree: every group in tree order with its parent, its count (when given) and «inaktiv»; the
 * caller decides only where a choice leads (`$href`) — the list's `?group=<id>`, the picker's
 * region link. A group stands for its articles INCLUDING its sub-groups, and so does its count.
 *
 * @var \Z77\Module\Article\Services\ProductGroupTree $tree
 * @var string $id                    the picker's DOM id
 * @var int|null $group               the chosen group, null = all
 * @var callable(int|string|null): string $href  the link of a group (null = all)
 * @var array<int, int> $counts       group id → articles incl. sub-groups — optional ([] = no counts)
 */
if (!isset($tree, $id, $href) || $tree->isEmpty()) { return; }
$counts = $counts ?? [];
$nodes  = [];
foreach ($tree->rows() as $row) {
    $node    = $row['group'];
    $key     = (int) $node->getId();
    $nodes[] = array_filter([
        'id'     => $key,
        'parent' => $node->getParentId(),
        'name'   => $node->getName(),
        'count'  => $counts === [] ? null : ($counts[$key] ?? 0),
        'note'   => $node->isActive() ? '' : 'inaktiv',
    ], static fn($v, $k) => $k === 'parent' || $v !== null, ARRAY_FILTER_USE_BOTH);
}
?>
<?= $this->partial('partials/treePicker', [
    'id'       => $id,
    'label'    => 'Warengruppe',
    'allLabel' => 'Alle Warengruppen',
    'nodes'    => $nodes,
    'value'    => $group ?? null,
    'href'     => $href,
    'allCount' => $counts === [] ? null : array_sum(array_map(static fn(array $r) => $r['depth'] === 0 ? ($counts[(int) $r['group']->getId()] ?? 0) : 0, $tree->rows())),
    'labels'   => [
        'search' => 'Gruppe suchen …',
        'none'   => 'Keine Gruppe gefunden.',
        'level'  => '%d Untergruppen',
        'foot'   => $counts === [] ? '' : 'inkl. Untergruppen',
        'clear'  => 'Filter aufheben',
    ],
], 'Z77\Shared') ?>
