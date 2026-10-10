<?php
/**
 * «Geschäftsjahr eröffnen». Start and end are proposed (the day after the
 * latest year, twelve months — or, «Vorjahr», the twelve months before the
 * earliest year, owner 2026-09-29) and free to change — a deviating, shortened or
 * extended year is allowed, at most 24 months, contiguous with the previous
 * one. The code stays empty to take the proposal from the dates (no
 * JavaScript: the server proposes it on submit). Opening derives the monthly
 * periods and the journal-entry number range; a year is not edited
 * afterwards, and deleted only while it is the latest and still empty
 * (FIN-FY-002).
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $entry
 * @var string $proposed  the code proposed for the proposed dates
 * @var bool   $prior     the prior year is proposed (`?prior=1`)
 * @var bool   $hasYears  a year exists — only then is there a «Vorjahr» to choose
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
    <?= $this->partial('partials/modalActions', ['submit' => 'Eröffnen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if (!empty($hasYears)): ?>
        <?php /* Next or prior: two GETs of this modal — the server proposes the dates (no JavaScript of its own).
                 BUTTONS, not links: core.js does not stop a link's navigation, and /open as a page has no list to show. */ ?>
        <div class="be-lang-switch" role="group" aria-label="Welches Geschäftsjahr">
            <button type="button" class="be-lang-switch__option<?= empty($prior) ? ' be-lang-switch__option--active' : '' ?>" data-fetch-get="<?= e($actionBase) ?>/open"<?= empty($prior) ? ' aria-pressed="true"' : '' ?>>Nächstes Jahr</button>
            <button type="button" class="be-lang-switch__option<?= !empty($prior) ? ' be-lang-switch__option--active' : '' ?>" data-fetch-get="<?= e($actionBase) ?>/open?prior=1"<?= !empty($prior) ? ' aria-pressed="true"' : '' ?>>Vorjahr</button>
        </div>
        <?php if (!empty($prior)): ?>
        <p class="be-form__hint">Ein Vorjahr endet am Tag vor dem ersten Geschäftsjahr. Seine Schlusssalden gehen nicht von selbst ins Folgejahr — bis zum Jahresabschluss (P5) von Hand als Eröffnungsbuchung erfassen.</p>
        <?php endif; ?>
        <?php endif; ?>
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
</form>
