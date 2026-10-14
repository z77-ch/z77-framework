<?php
/**
 * One product-group row of the tree (`.be-tree__node`, the chart of accounts' row anatomy with
 * the lead slots switch | ⋮). The node carries `data-entity="product-group:<id>"`.
 *
 *   - the switch: «Aktiv» — an inactive group is not offered when an article is placed; what
 *     hangs there stays;
 *   - ⋮ opens the group WINDOW (`data-window-open`; the href stays for a ctrl-click and a
 *     browser without the script);
 *   - the name — a group with sub-groups in bold;
 *   - the defaults it gives NEW articles (its own; an inherited one is not repeated here);
 *   - trailing tools: the article count — a link to the article list filtered by this group,
 *     sub-groups included — and «+ Untergruppe» (an icon, backend-screen.md §4).
 *
 * @var \Z77\Module\Article\Entities\ProductGroup $group
 * @var int $depth     0 = a root
 * @var int $children  direct sub-groups
 * @var int $products  articles hanging directly here
 * @var int $within    articles here and below
 * @var string $actionBase
 * @var string $articleList
 */
$id       = (int) $group->getId();
$edit     = $actionBase . '/edit?id=' . $id;
$addChild = $actionBase . '/add?parent=' . $id;
$active   = $group->isActive();
$defaults = array_filter([
    $group->getDefaultTaxCode() !== '' ? 'MWST ' . $group->getDefaultTaxCode() : '',
    $group->getDefaultRevenueAccount() !== '' ? 'Konto ' . $group->getDefaultRevenueAccount() : '',
]);
$count    = $within === 1 ? '1 Artikel' : $within . ' Artikel';
?>
            <div class="be-tree__node<?= $active ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:<?= (int) $depth ?>" data-entity="product-group:<?= $id ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $active ? 'Aktiv — wird beim Einordnen eines Artikels angeboten' : 'Inaktiv — Artikel, die darin hängen, bleiben darin' ?>">
                        <input type="checkbox" class="be-switch__input" aria-label="Aktiv"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= $id ?>"<?= $active ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <a class="be-tree__menu" href="<?= e($edit) ?>" data-window-open="<?= e($edit) ?>" title="Bearbeiten" aria-label="Warengruppe <?= e($group->getName()) ?> bearbeiten">⋮</a>

                    <span class="be-tree__name"><?= $children > 0 ? '<strong>' . e($group->getName()) . '</strong>' : e($group->getName()) ?></span>

                    <span class="be-tree__url" title="Vorgaben für neue Artikel"><?= $defaults === [] ? '' : 'Vorgabe ' . e(implode(', ', $defaults)) ?></span>

                    <span class="be-tree__route be-tree__tools">
                        <?php if (!$active): ?><span class="badge badge--muted">inaktiv</span><?php endif; ?>
                        <a class="be-tree__meta" href="<?= e($articleList . '?group=' . $id) ?>" title="<?= $products === $within ? 'Artikel dieser Warengruppe zeigen' : $products . ' direkt hier, ' . $within . ' mit Untergruppen — zeigen' ?>"><?= e($count) ?></a>
                        <a class="be-icon-btn" href="<?= e($addChild) ?>" data-window-open="<?= e($addChild) ?>" title="Untergruppe anlegen" aria-label="Untergruppe in <?= e($group->getName()) ?> anlegen">
                            <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-folder-plus"/></svg>
                        </a>
                    </span>
                </div>
            </div>
