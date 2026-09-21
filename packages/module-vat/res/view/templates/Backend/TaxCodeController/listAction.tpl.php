<?php
/**
 * Tax codes with their dated rates (ADR-041). One row per code: the inline
 * switch is `active` (deactivate, never delete — ADR-043 decision 19), the
 * ⋮ hub carries edit / new rate / remove-future-rate. The rates are listed
 * newest first; the one in effect today is marked, a future one is flagged.
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
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? '/backend/finance/tax-code';
$tplNs      = 'Z77\\Module\\Vat';
$rateLine   = fn(TaxRate $rate): string => $this->partial('Backend/TaxCodeController/_rate', ['rate' => $rate], $tplNs);
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Steuercodes</h2>
            <span class="be-list__section-badge"><?= count($codes) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if (empty($codes)): ?>
            <p class="be-list__empty">Keine Steuercodes vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($codes as $code): ?>
            <?php
                $rates   = $ratesByCode[$code->getCode()] ?? [];
                $current = null;
                foreach ($rates as $rate) {
                    if ($rate->getValidFrom() <= $today) { $current = $rate; break; }   // newest first → first hit
                }
            ?>
            <div class="be-tree__node<?= $code->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-tax-code-id="<?= e((string) $code->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $code->isActive() ? 'Aktiv — wird für neue Belege angeboten' : 'Inaktiv — nur noch für bestehende Belege' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $code->getId()) ?>"<?= $code->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="<?= e($actionBase) ?>/actions?id=<?= e((string) $code->getId()) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code">
                        <code><?= e($code->getCode()) ?></code>
                        <?= e($code->getLabel()) ?>
                        <small class="be-list__cell--muted">· <?= e($categoryLabels[$code->getCategory()] ?? $code->getCategory()) ?> · <?= e($code->getCountry()) ?></small>
                    </span>

                    <span class="be-tree__url" data-field="rates">
                        <?php if ($rates === []): ?>
                        <span class="badge badge--danger">kein Satz</span>
                        <?php else: ?>
                        <?php foreach ($rates as $i => $rate): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= $rate === $current ? '<strong>' : '' ?><?= raw($rateLine($rate)) ?><?= $rate === $current ? '</strong>' : '' ?>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php if ($current !== null): ?>
                        <span class="badge badge--success"><?= e(TaxRate::formatPercent($current->getRate())) ?> %</span>
                        <?php elseif ($rates !== []): ?>
                        <span class="badge badge--warning">noch nicht in Kraft</span>
                        <?php endif; ?>
                        <?php if (!$code->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
