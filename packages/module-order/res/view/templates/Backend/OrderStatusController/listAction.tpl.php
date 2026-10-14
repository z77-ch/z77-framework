<?php
/**
 * Stammdaten › Aufträge › Auftragsstatus (plan §9.5) — a STANDARD LIST (listing.md) in level
 * order: the head is the kernel's partial over {@see \Z77\Module\Order\Ui\OrderStatusListing},
 * the rows are `_row` — the same partial the switch answers with in place (FETCH-ROW-001). The
 * list is a FETCH REGION (`order-status-list`): the sort reloads only this part, and a save in the
 * status window reloads it (`refresh-region`). No CSS and no JavaScript of its own.
 *
 * @var list<\Z77\Module\Order\Entities\OrderStatus> $rows
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderStatusLayout::BASE;
$listUrl    = $actionBase . '/list';
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Auftragsstatus</h2>
            <span class="be-list__section-badge" title="Status"><?= count($rows) ?></span>
        </div>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="orderStatus">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($rows as $row): ?>
                <?= $this->partial('Backend/OrderStatusController/_row', ['row' => $row, 'actionBase' => $actionBase], 'Z77\\Module\\Order') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty">Kein Auftragsstatus vorhanden — «+ Status» legt den ersten an.</p>
        <?php endif; ?>
    </div>
</div>
