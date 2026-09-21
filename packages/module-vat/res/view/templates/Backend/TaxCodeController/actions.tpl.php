<?php
/**
 * Tax-code row action hub (⋮): edit, new rate, and the code's rate history
 * with «Entfernen» only on a row whose validity has not started. No delete of
 * the code itself — deactivate on the list row instead (ADR-043 decision 19).
 *
 * The history is a small `.be-list__table` (the backend's list grid): one
 * row per rate, phrase + action cell — the same classes every list screen
 * uses, no inline styles.
 *
 * @var \Z77\Module\Vat\Entities\TaxCode $entry
 * @var list<\Z77\Module\Vat\Entities\TaxRate> $rates  newest first
 * @var array<int,bool> $removable  rate id → may be removed (not yet in effect)
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/tax-code';
$tplNs      = 'Z77\\Module\\Vat';
$id         = $entry->getId();
$ic         = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($entry->getCode()) ?>» <?= e($entry->getLabel()) ?></h2></div>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $id) ?>"><?= raw($ic('icon-edit')) ?> Bearbeiten</button>
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="<?= e($actionBase) ?>/add-rate?code=<?= e(rawurlencode($entry->getCode())) ?>"><?= raw($ic('icon-plus')) ?> Neuer Satz gültig ab …</button>
        </div>
        <div class="be-form__section">Sätze <small>(neuster zuerst — Historie bleibt stehen)</small></div>
        <?php if ($rates === []): ?>
        <p class="be-list__empty">Noch kein Satz — ohne Satz rechnet kein Beleg mit diesem Code.</p>
        <?php else: ?>
        <div class="be-list__table be-list__table--actions">
            <?php foreach ($rates as $rate): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= raw($this->partial('Backend/TaxCodeController/_rate', ['rate' => $rate], $tplNs)) ?></span>
                    <span class="be-list__cell be-list__cell--actions">
                        <?php if ($removable[$rate->getId()] ?? false): ?>
                        <button type="button" class="be-btn be-btn--ghost be-btn--sm" title="Noch nicht in Kraft — darf entfernt werden"
                                data-fetch-get="<?= e($actionBase) ?>/confirm-remove-rate?id=<?= e((string) $rate->getId()) ?>">Entfernen</button>
                        <?php else: ?>
                        <span class="badge badge--muted" title="In Kraft — bleibt als Historie stehen">fix</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
