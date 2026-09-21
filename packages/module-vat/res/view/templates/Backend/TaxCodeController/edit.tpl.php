<?php
/**
 * Add / edit a tax code. The code itself is immutable after creation — it is
 * the key documents and journal lines carry (ADR-043 decision 19); the field
 * is read-only on edit and the controller re-sets it regardless. Rates are
 * not edited here: a rate change is a new row («Neuer Satz» in the ⋮ hub).
 *
 * @var \Z77\Module\Vat\Entities\TaxCode $entry
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var array<string,string> $categoryLabels
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/tax-code';

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase) ?>/<?= $isNew ? 'add' : 'edit?id=' . e((string) $entry->getId()) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Steuercode anlegen' : 'Steuercode «' . e($entry->getCode()) . '» bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors()): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($validator->getFieldErrors()): ?>Bitte überprüfe die markierten Eingaben.<?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Code <small>(2–8 Zeichen A–Z, 0–9<?= $isNew ? '' : ' — nach dem Anlegen fix' ?>)</small></label>
                <input type="text" name="code" value="<?= e($entry->getCode()) ?>" maxlength="8" autocomplete="off"
                       placeholder="z.B. UN"<?= $isNew ? ' required' : ' readonly' ?>
                       aria-invalid="<?= $validator->hasFieldError('code') ? 'true' : 'false' ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Land <small>(ISO, z.B. CH)</small></label>
                <input type="text" name="country" value="<?= e($entry->getCountry()) ?>" maxlength="2" required autocomplete="off"
                       aria-invalid="<?= $validator->hasFieldError('country') ? 'true' : 'false' ?>">
                <?= raw($fieldError('country')) ?>
            </div>
        </div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Bezeichnung</label>
                <input type="text" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required autocomplete="off"
                       placeholder="z.B. Umsatz Normalsatz"
                       aria-invalid="<?= $validator->hasFieldError('label') ? 'true' : 'false' ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Kategorie <small>(was für eine Steuer — das Länderpaket ordnet danach dem Formular zu)</small></label>
                <select name="category" required aria-invalid="<?= $validator->hasFieldError('category') ? 'true' : 'false' ?>">
                    <option value=""<?= $entry->getCategory() === '' ? ' selected' : '' ?>>– wählen –</option>
                    <?php foreach ($categoryLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $entry->getCategory() === $value ? ' selected' : '' ?>><?= e($label) ?> (<?= e($value) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('category')) ?>
            </div>
        </div>
        <?php if ($isNew): ?>
        <p class="be-form__hint">
            Der Satz kommt im nächsten Schritt: nach dem Anlegen «Neuer Satz gültig ab» im ⋮-Menü der Zeile.
        </p>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
