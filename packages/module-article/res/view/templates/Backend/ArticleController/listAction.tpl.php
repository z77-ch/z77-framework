<?php
/**
 * Stammdaten › Aufträge › Artikel (plan §9.5) — a STANDARD LIST (listing.md): the head and the
 * search form are the kernel's partials over {@see \Z77\Module\Article\Ui\ArticleListing}, the
 * rows are `_row` — the same partial the switch answers with in place (FETCH-ROW-001). The whole
 * list is a FETCH REGION (`article-find-list`): sort / page / search reload only this part, and
 * a save in the article window reloads it (`refresh-region`). No CSS and no JavaScript of its own.
 *
 * @var list<\Z77\Module\Article\Entities\Product> $products
 * @var array<int, array{variant: ?\Z77\Module\Article\Entities\Variant, price: ?\Z77\Module\Article\Entities\Price, path: string}> $rows
 * @var \Z77\Module\Article\Services\ProductGroupTree $tree
 * @var int|null $group  the group the list is filtered by (the rail picker), null = all
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Article\Ui\ArticleLayout::BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Artikel<?php if ($group !== null): ?> <small class="be-list__cell--muted">· <?= e($tree->path($group)) ?><?= $tree->childCount($group) > 0 ? " mit Untergruppen" : "" ?></small><?php endif; ?></h2>
            <?php if ($state->isActive()): ?>
            <nav class="be-list__toggles" aria-label="Suche">
                <a data-fetch-region-link href="<?= e($listUrl . '?' . $state->resetQuery()) ?>">Suche zurücksetzen</a>
            </nav>
            <?php endif; ?>
            <span class="be-list__section-badge" title="Artikel"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="article">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($products as $product): ?>
                <?= $this->partial('Backend/ArticleController/_row', [
                    'product'    => $product,
                    'variant'    => $rows[$product->getId()]['variant'] ?? null,
                    'price'      => $rows[$product->getId()]['price'] ?? null,
                    'path'       => $rows[$product->getId()]['path'] ?? '',
                    'definition' => $definition,
                    'actionBase' => $actionBase,
                ], 'Z77\\Module\\Article') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($products === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Artikel gefunden.' : ($group !== null ? 'In dieser Warengruppe hängt noch kein Artikel — «+ Artikel» legt einen darin an.' : 'Noch kein Artikel erfasst — «+ Artikel» legt den ersten an.') ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Artikel',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
