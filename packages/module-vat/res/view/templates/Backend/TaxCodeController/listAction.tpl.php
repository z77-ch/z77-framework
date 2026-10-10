<?php
/**
 * Tax codes with their dated rates (ADR-041). One row per code: the inline
 * switch is `active` (deactivate, never delete — ADR-043 decision 19), the
 * ⋮ hub carries edit / new rate / remove-future-rate. The rates are listed
 * newest first; the one in effect today is marked, a future one is flagged.
 *
 * One row = `_row` (also the in-place answer of a save, ADR-047 addendum 2026-10-10).
 *
 * Styling: the shared backend list/tree classes only (`.be-tree--hub` row
 * anatomy, `.be-tree__url` and `.be-list__cell--muted` for secondary text,
 * `.be-list__empty`, badges) — no inline styles, no CSS of its own.
 *
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $codes
 * @var array<string, list<\Z77\Module\Vat\Entities\TaxRate>> $ratesByCode  code → rows, newest validFrom first
 * @var array<string,string> $categoryLabels
 * @var string $today  YYYY-MM-DD
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/finance/tax-code';
$tplNs      = 'Z77\\Module\\Vat';
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Steuercodes</h2>
            <span class="be-list__section-badge"><?= count($codes) ?></span>
        </div>
        <div class="be-tree be-tree--hub" data-entity-list="taxCode">
            <?php if (empty($codes)): ?>
            <p class="be-list__empty">Keine Steuercodes vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($codes as $code): ?>
            <?= raw($this->partial('Backend/TaxCodeController/_row', [
                'code'           => $code,
                'rates'          => $ratesByCode[$code->getCode()] ?? [],
                'categoryLabels' => $categoryLabels,
                'today'          => $today,
                'actionBase'     => $actionBase,
            ], $tplNs)) ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
