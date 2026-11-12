<?php
/**
 * Stammdaten › Finanzen › «Zahlungsarten» («Bezahlt mit», owner decision E10) — built like
 * «Belegvorlagen» (the same look): a STANDARD LIST over
 * {@see \Z77\Module\Receipt\Ui\MasterDataListing::paymentMethods()} in choice order, rows from `_row`
 * (the switch answers with it in place, FETCH-ROW-001), a FETCH REGION reloaded by a save in the
 * window. No CSS and no JavaScript of its own.
 *
 * @var list<\Z77\Module\Receipt\Entities\PaymentMethod> $rows
 * @var array<string, int> $usage  code → receipts carrying it
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::PAYMENT_METHOD_BASE;
$listUrl    = $actionBase . '/list';
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Zahlungsarten</h2>
            <span class="be-list__section-badge" title="Zahlungsarten"><?= count($rows) ?></span>
        </div>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="paymentMethod">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($rows as $row): ?>
                <?= $this->partial('Backend/PaymentMethodController/_row', ['row' => $row, 'usage' => $usage[$row->getCode()] ?? 0, 'choices' => $choices, 'actionBase' => $actionBase], 'Z77\\Module\\Receipt') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($rows === []): ?>
        <p class="be-list__empty">Noch keine Zahlungsart — «+ Zahlungsart» legt die erste an (z.B. «Kasse» mit Haben 1000). Ohne Zahlungsart fehlt jedem Beleg vom Handy das Haben-Konto; es wird dann im Belegjournal ergänzt.</p>
        <?php endif; ?>
    </div>
</div>
