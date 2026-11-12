<?php
/**
 * Stammdaten › Finanzen › «Belegvorlagen» (board «Belegvorlagen», owner-approved 2026-11-12) — a
 * STANDARD LIST (listing.md) in tile order (Reihenfolge — so the tiles stand on the phone): the head
 * is the kernel's partial over {@see \Z77\Module\Receipt\Ui\MasterDataListing::templates()}, the rows
 * are `_row` — the same partial the switch answers with in place (FETCH-ROW-001). The list is a FETCH
 * REGION: the sort reloads only this part, a save in the window reloads it. No ⋮: the only row action
 * is «öffnen». No CSS and no JavaScript of its own.
 *
 * @var list<\Z77\Module\Receipt\Entities\ReceiptTemplate> $rows
 * @var array<string, int> $usage  code → receipts carrying it
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::TEMPLATE_BASE;
$listUrl    = $actionBase . '/list';
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Belegvorlagen</h2>
            <span class="be-list__section-badge" title="Vorlagen"><?= count($rows) ?></span>
        </div>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="receiptTemplate">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($rows as $row): ?>
                <?= $this->partial('Backend/ReceiptTemplateController/_row', ['row' => $row, 'usage' => $usage[$row->getCode()] ?? 0, 'choices' => $choices, 'actionBase' => $actionBase], 'Z77\\Module\\Receipt') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty">Noch keine Belegvorlage — «+ Vorlage» legt die erste an. Ohne Vorlage erfasst das Handy nur Foto, Betrag und Text; die Konten werden dann im Belegjournal ergänzt.</p>
        <?php endif; ?>
    </div>
</div>
