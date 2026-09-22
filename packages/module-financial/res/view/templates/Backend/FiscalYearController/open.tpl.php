<?php
/**
 * «Geschäftsjahr eröffnen». Start and end are proposed (the day after the
 * latest year, twelve months) and free to change — a deviating, shortened or
 * extended year is allowed, at most 24 months, contiguous with the previous
 * one. The code stays empty to take the proposal from the dates (no
 * JavaScript: the server proposes it on submit). Opening derives the monthly
 * periods and the journal-entry number range; a year is not edited or
 * deleted afterwards.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $entry
 * @var string $proposed  the code proposed for the proposed dates
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/fiscal-year';
$posted     = $validator->hasErrors();

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<form data-fetch-post="<?= e($actionBase) ?>/open">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Geschäftsjahr eröffnen</h2>
    </div>
    <div class="be-modal__body">
        <?php if ($posted): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Beginn</label>
                <input type="date" name="start_date" value="<?= e($entry->getStartDate()?->format('Y-m-d') ?? '') ?>" required
                       aria-invalid="<?= $validator->hasFieldError('start_date') ? 'true' : 'false' ?>">
                <?= raw($fieldError('start_date')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Ende <small>(höchstens 24 Monate nach dem Beginn)</small></label>
                <input type="date" name="end_date" value="<?= e($entry->getEndDate()?->format('Y-m-d') ?? '') ?>" required
                       aria-invalid="<?= $validator->hasFieldError('end_date') ? 'true' : 'false' ?>">
                <?= raw($fieldError('end_date')) ?>
            </div>
        </div>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Kürzel <small>(leer = aus den Daten, z.B. 2026 oder 2026-27 — nach dem Eröffnen fix)</small></label>
                <input type="text" name="code" value="<?= $posted ? e($entry->getCode()) : '' ?>" maxlength="16" autocomplete="off"
                       placeholder="<?= e($proposed) ?>"
                       aria-invalid="<?= $validator->hasFieldError('code') ? 'true' : 'false' ?>">
                <?= raw($fieldError('code')) ?>
            </div>
        </div>
        <p class="be-form__hint">
            Beim Eröffnen entstehen die Perioden (je Kalendermonat, am Anfang und Ende auf das Geschäftsjahr gekürzt)
            und der Nummernkreis der Buchungen dieses Jahres.
        </p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Eröffnen</button>
    </div>
</form>
