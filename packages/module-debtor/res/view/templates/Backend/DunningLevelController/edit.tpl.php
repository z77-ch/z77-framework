<?php
/**
 * Add / edit a dunning level. The code is immutable after creation — it is
 * the key notices carry (ADR-043 decision 19). The fee is typed as an
 * amount and stored as minor units; it carries no VAT (plan §6.5), so
 * there is no tax field and never will be.
 *
 * @var \Z77\Module\Debtor\Entities\DunningLevel $entry
 * @var string $currency
 * @var string $feeField  the fee as typed / as stored
 * @var string $feeError
 * @var list<string> $languages
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/dunning-level';

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
        <h2 class="be-modal__title"><?= $isNew ? 'Mahnstufe anlegen' : 'Mahnstufe «' . e($entry->getCode()) . '» bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors() || $feeError !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php if ($feeError !== ''): ?><div><?= e($feeError) ?></div><?php endif; ?>
            <?php if ($validator->getFieldErrors()): ?>Bitte überprüfe die markierten Eingaben.<?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Code <small>(2–16 Zeichen a–z, 0–9, Bindestrich<?= $isNew ? '' : ' — nach dem Anlegen fix' ?>)</small></label>
                <input type="text" name="code" value="<?= e($entry->getCode()) ?>" maxlength="16" autocomplete="off"<?= $isNew ? ' required' : ' readonly' ?>
                       aria-invalid="<?= $validator->hasFieldError('code') ? 'true' : 'false' ?>">
                <?= raw($fieldError('code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Bezeichnung</label>
                <input type="text" name="label" value="<?= e($entry->getLabel()) ?>" maxlength="80" required autocomplete="off"
                       aria-invalid="<?= $validator->hasFieldError('label') ? 'true' : 'false' ?>">
                <?= raw($fieldError('label')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Stufe <small>(die Reihenfolge des Mahnlaufs)</small></label>
                <input type="number" name="level" value="<?= e((string) $entry->getLevel()) ?>" min="1" max="9" required
                       aria-invalid="<?= $validator->hasFieldError('level') ? 'true' : 'false' ?>">
                <?= raw($fieldError('level')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Tage nach Verfall</label>
                <input type="number" name="days_after_due" value="<?= e((string) $entry->getDaysAfterDue()) ?>" min="0" max="365" required
                       aria-invalid="<?= $validator->hasFieldError('days_after_due') ? 'true' : 'false' ?>">
                <?= raw($fieldError('days_after_due')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Mahngebühr in <?= e($currency) ?> <small>(0 = keine)</small></label>
                <input type="text" name="fee" value="<?= e($feeField) ?>" maxlength="12" autocomplete="off" inputmode="decimal"
                       aria-invalid="<?= $feeError !== '' || $validator->hasFieldError('fee') ? 'true' : 'false' ?>">
                <?= raw($fieldError('fee')) ?>
                <small class="be-form__hint">Eine Mahngebühr ist keine Leistung — sie trägt keine MWST.</small>
            </div>
        </div>

        <div class="be-form__section">Text auf der Mahnung</div>
        <p class="be-form__hint">Die Standardsprache ist der Rückfall für jede andere.</p>
        <div data-z77-field-wrapper>
            <?php foreach ($languages as $i => $language): ?>
            <div class="be-form__field">
                <label><?= e(mb_strtoupper($language)) ?><?= $i === 0 ? ' <small>(Standardsprache)</small>' : '' ?></label>
                <textarea name="document_text[<?= e($language) ?>]" rows="3" maxlength="800"><?= e($entry->getDocumentText()[$language] ?? '') ?></textarea>
            </div>
            <?php endforeach; ?>
            <?= raw($fieldError('document_text')) ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
