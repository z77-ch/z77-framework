<?php
/**
 * One tax-code row of the list (`.be-tree__node`) — the single place that renders it: the list
 * loops over it, and a save answers with it (`FetchResponse::replaceRow()` / `insertRow()`,
 * ADR-047 addendum 2026-10-10), so list and answer cannot drift. The node carries
 * `data-entity="taxCode:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 * @var \Z77\Module\Vat\Entities\TaxCode $code
 * @var list<\Z77\Module\Vat\Entities\TaxRate> $rates  the code's rows, newest validFrom first
 * @var array<string,string> $categoryLabels
 * @var string $today  YYYY-MM-DD
 * @var string $actionBase  URL root of THIS mount
 */
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? '/backend/finance/tax-code';
$id         = (string) $code->getId();
$current    = null;
foreach ($rates as $rate) {
    if ($rate->getValidFrom() <= $today) { $current = $rate; break; }   // newest first → first hit
}
$rateLine = fn(TaxRate $rate): string => $this->partial('Backend/TaxCodeController/_rate', ['rate' => $rate], 'Z77\\Module\\Vat');
?>
            <div class="be-tree__node<?= $code->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-tax-code-id="<?= e($id) ?>" data-entity="taxCode:<?= e($id) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $code->isActive() ? 'Aktiv — wird für neue Belege angeboten' : 'Inaktiv — nur noch für bestehende Belege' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e($id) ?>"<?= $code->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="<?= e($actionBase) ?>/actions?id=<?= e($id) ?>">⋮</button>

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
