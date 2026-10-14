<?php
/**
 * Stammdaten › Aufträge › Warengruppen (plan §4b A1) — the product-group TREE, in tree order,
 * each level indented one step. Built on the SAME tree rows as the chart of accounts
 * (`.be-tree--hub be-tree--lead-switch`, `--node-depth` — the one tree UI of the backend; a
 * standard list has no depth, so this is the documented exception to «every table is a standard
 * list»: a short tree, read whole, no paging and no column search). One row = `_row`.
 *
 * The tree is a FETCH REGION (`product-group-list`): a save in the group window, a switch and a
 * delete reload it (a new or moved group changes the order and the indent of more than one row).
 * No CSS and no JavaScript of its own.
 *
 * @var \Z77\Module\Article\Services\ProductGroupTree $tree
 * @var array<int, int> $counts  group id → articles hanging DIRECTLY on it
 * @var string $actionBase
 * @var string $articleList  the article list URL (the count links there, `?group=`)
 */
$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ProductGroupLayout::BASE;
$rows       = $tree->rows();
?>
<div class="be-list" data-fetch-region="<?= e(\Z77\Module\Article\Ui\ProductGroupLayout::REGION) ?>" data-fetch-region-src="<?= e($actionBase . '/list') ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Warengruppen</h2>
            <span class="be-list__section-badge" title="Warengruppen"><?= count($rows) ?></span>
        </div>
        <div class="be-tree be-tree--hub be-tree--lead-switch" data-entity-list="product-group">
            <?php if ($rows === []): ?>
            <p class="be-list__empty">Noch keine Warengruppe — «+ Warengruppe» legt die erste an. Jeder Artikel hängt in genau einer.</p>
            <?php endif; ?>
            <?php foreach ($rows as $row): $id = (int) $row['group']->getId(); ?>
            <?= raw($this->partial('Backend/ProductGroupController/_row', [
                'group'       => $row['group'],
                'depth'       => $row['depth'],
                'children'    => $tree->childCount($id),
                'products'    => (int) ($counts[$id] ?? 0),
                'within'      => array_sum(array_map(static fn(int $g) => (int) ($counts[$g] ?? 0), $tree->subtreeIds($id))),
                'actionBase'  => $actionBase,
                'articleList' => $articleList ?? \Z77\Module\Article\Ui\ArticleLayout::BASE . '/list',
            ], 'Z77\\Module\\Article')) ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
