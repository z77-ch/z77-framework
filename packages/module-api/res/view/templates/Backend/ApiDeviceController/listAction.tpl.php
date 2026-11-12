<?php
/**
 * Stammdaten › System › Geräte (board «Geräte», 2026-11-12). A STANDARD LIST (listing.md): head
 * and search form are the kernel's partials over {@see \Z77\Module\Api\Ui\ApiDeviceListing},
 * the rows are `_row`; no CSS and no JavaScript of its own:
 *
 *   - every row starts with its state icon (phone = live, lock = blocked), which opens the
 *     device as a WINDOW (ADR-047), then the ⋮ that opens its CONTEXT MENU (MENU-001);
 *   - Name and Besitzer are `<label for>` of their column's search field;
 *   - the whole list is a FETCH REGION: tabs, sort, page, search and a window's save reload only
 *     this part.
 *
 * @var list<array<string, mixed>> $rows  one `_row` context per device
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var bool $governs   the actor sees every device (not only his own)
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$view       = $state->extra('view');
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
            <h2 class="be-list__section-title"><?= e($view === 'all' ? 'Alle Geräte' : ($governs ? 'Aktive Geräte' : 'Deine aktiven Geräte')) ?></h2>
            <span class="be-list__section-badge" title="Geräte"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($rows as $row): ?>
                <?= $this->partial('Backend/ApiDeviceController/_row', $row + ['definition' => $definition, 'actionBase' => $actionBase], 'Z77\\Module\\Api') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Gerät gefunden.' : ($view === 'all'
            ? 'Noch kein Gerät gekoppelt.'
            : 'Kein aktives Gerät — «+ Gerät koppeln» zeigt den Code, den das Handy einliest.') ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Geräte',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
