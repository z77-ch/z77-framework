<?php
/**
 * Add / edit payment terms. The code is immutable after creation — it is
 * the key debtor profiles and issued invoices carry (ADR-043 decision 19);
 * the field is read-only on edit and the write service refuses a change
 * regardless.
 *
 * Two discount tiers, days + percent each. A percent is typed as «2» or
 * «2.5» and stored as an integer in hundredths (plan §3). A document text
 * per language of this installation — the default language first, because
 * every other language falls back to it.
 *
 * @var \Z77\Module\Debtor\Entities\PaymentTerms $entry
 * @var array<int,string> $percents    row → percent as typed
 * @var array<int,string> $tierErrors  row → error message
 * @var list<string> $languages
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/finance/payment-terms';
$maxTiers   = \Z77\Module\Debtor\Entities\PaymentTerms::MAX_TIERS;

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
        <h2 class="be-modal__title"><?= $isNew ? 'Zahlungskonditionen anlegen' : 'Zahlungskonditionen «' . e($entry->getCode()) . '» bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors() || $tierErrors): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            <?php foreach ($tierErrors as $row => $error): ?>
            <div>Skonto-Stufe <?= e((string) $row) ?>: <?= e($error) ?></div>
            <?php endforeach; ?>
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
                <label>Zahlungsfrist in Tagen <small>(0 = zahlbar sofort)</small></label>
                <input type="number" name="due_days" value="<?= e((string) $entry->getDueDays()) ?>" min="0" max="365" required
                       aria-invalid="<?= $validator->hasFieldError('due_days') ? 'true' : 'false' ?>">
                <?= raw($fieldError('due_days')) ?>
            </div>
        </div>

        <div class="be-form__section">Skonto</div>
        <p class="be-form__hint">Je Stufe Tage und Prozent — leer lassen heisst «kein Skonto». Die frühere Stufe gibt mehr Prozent als die spätere.</p>
        <div class="be-form__grid" data-z77-field-wrapper>
            <?php for ($row = 1; $row <= $maxTiers; $row++): ?>
            <div class="be-form__field">
                <label>Stufe <?= $row ?> — Tage</label>
                <input type="number" name="discount_days[<?= $row ?>]" min="1" max="365" autocomplete="off"
                       value="<?= e((string) ($entry->getDiscounts()[$row - 1]['days'] ?? '')) ?>">
            </div>
            <div class="be-form__field">
                <label>Stufe <?= $row ?> — Prozent</label>
                <input type="text" name="discount_percent[<?= $row ?>]" maxlength="6" autocomplete="off" inputmode="decimal"
                       value="<?= e($percents[$row] ?? '') ?>">
                <?php if (isset($tierErrors[$row])): ?>
                <small class="be-form__field-error" data-z77-field-error><?= e($tierErrors[$row]) ?></small>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
        <?= raw($fieldError('discounts')) ?>

        <div class="be-form__section">Text auf dem Beleg</div>
        <p class="be-form__hint">Was auf der Rechnung steht. Die Standardsprache ist der Rückfall für jede andere.</p>
        <div data-z77-field-wrapper>
            <?php foreach ($languages as $i => $language): ?>
            <div class="be-form__field">
                <label><?= e(mb_strtoupper($language)) ?><?= $i === 0 ? ' <small>(Standardsprache)</small>' : '' ?></label>
                <textarea name="document_text[<?= e($language) ?>]" rows="2" maxlength="500"><?= e($entry->getDocumentText()[$language] ?? '') ?></textarea>
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
