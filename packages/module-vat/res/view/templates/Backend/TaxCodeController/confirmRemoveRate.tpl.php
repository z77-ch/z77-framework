<?php
/**
 * Confirm removing a rate row that is NOT yet in effect (a typo caught in
 * time). A row in effect is refused here and again on POST — history stays.
 *
 * @var \Z77\Module\Vat\Entities\TaxRate $entry
 * @var bool $removable
 * @var string $entityCsrf
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/tax-code';
$rateLine   = $this->partial('Backend/TaxCodeController/_rate', ['rate' => $entry], 'Z77\\Module\\Vat');

if (!$removable): ?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Entfernen nicht möglich</h2>
</div>
<div class="be-modal__body">
    <div class="be-modal__alert be-modal__alert--error">
        Der Satz <?= raw($rateLine) ?> für «<?= e($entry->getCode()) ?>» ist in Kraft und bleibt als Historie
        stehen. Eine Korrektur ist ein neuer Satz.
    </div>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($actionBase) ?>/remove-rate">
    <input type="hidden" name="id"          value="<?= (int) $entry->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Satz entfernen</h2>
    </div>
    <div class="be-modal__body">
        <p>Den Satz <?= raw($rateLine) ?> für «<?= e($entry->getCode()) ?>» wirklich entfernen?
           Er ist noch nicht in Kraft; kein Beleg rechnet damit.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Entfernen</button>
    </div>
</form>
