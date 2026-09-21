<?php
/**
 * «Neuer Satz gültig ab» — a rate change is a NEW row (ADR-041 decision 2).
 * The rate is entered in percent ("8.1"); the controller converts it to
 * hundredths of a percent in integer arithmetic.
 *
 * @var \Z77\Module\Vat\Entities\TaxCode $code
 * @var \Z77\Module\Vat\Entities\TaxRate $entry
 * @var string $ratePercent  what was posted, re-shown after a failed save
 * @var string $rateError    '' or the message for the percent field
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $today
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/tax-code';

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase) ?>/add-rate?code=<?= e(rawurlencode($code->getCode())) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Neuer Satz für «<?= e($code->getCode()) ?>» — <?= e($code->getLabel()) ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors() || $rateError !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error">Bitte überprüfe die markierten Eingaben.</div>
        <?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Satz in % <small>(z.B. 8.1 — höchstens zwei Dezimalen)</small></label>
                <input type="text" name="rate_percent" value="<?= e($ratePercent) ?>" required autocomplete="off" inputmode="decimal"
                       placeholder="8.1"
                       aria-invalid="<?= $rateError !== '' || $validator->hasFieldError('rate') ? 'true' : 'false' ?>">
                <?php if ($rateError !== ''): ?>
                <small class="be-form__field-error" data-z77-field-error><?= e($rateError) ?></small>
                <?php else: ?>
                <?= raw($fieldError('rate')) ?>
                <?php endif; ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Gültig ab</label>
                <input type="date" name="valid_from" value="<?= e($entry->getValidFrom() !== '' ? $entry->getValidFrom() : $today) ?>" required
                       aria-invalid="<?= $validator->hasFieldError('valid_from') ? 'true' : 'false' ?>">
                <?= raw($fieldError('valid_from')) ?>
            </div>
        </div>
        <p class="be-form__hint">
            Gültig ab heute oder einem späteren Tag; ein früheres Datum nur vor dem ältesten bestehenden Satz
            (Historie nachtragen). Der bisherige Satz bleibt bis zum Vortag gültig. Ein Satz in Kraft wird nie
            geändert oder gelöscht — nur ein heute für heute erfasster Satz lässt sich heute noch entfernen.
        </p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Satz anlegen</button>
    </div>
</form>
