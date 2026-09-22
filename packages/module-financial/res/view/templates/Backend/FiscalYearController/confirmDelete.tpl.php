<?php
/**
 * Confirm deleting a wrongly opened fiscal year (owner decision 2026-09-22,
 * FIN-FY-002): the year, its periods and its number range go together. Only
 * the latest year, only while nothing was ever posted in it — refused here
 * with the reason, and decided again on POST under lock.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var ?string $refusal  why it cannot be deleted (German), or null
 * @var string $entityCsrf
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/fiscal-year';

if ($refusal !== null): ?>
<div class="be-actions">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Löschen nicht möglich</h2>
    </div>
    <div class="be-modal__body">
        <div class="be-modal__alert be-modal__alert--error">Geschäftsjahr <?= e($year->getCode()) ?>: <?= e($refusal) ?></div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($actionBase) ?>/delete">
    <input type="hidden" name="id"          value="<?= (int) $year->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Geschäftsjahr löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>Geschäftsjahr <code><?= e($year->getCode()) ?></code> (<?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?>) wirklich löschen?</p>
        <p class="be-form__hint">Gelöscht werden das Geschäftsjahr, seine <?= count($year->getPeriods()) ?> Perioden und der Nummernkreis
           <code><?= e($year->journalEntryRange()) ?></code>. Das geht nur, solange im Jahr nie gebucht wurde; danach lässt es sich mit
           korrigierten Daten neu eröffnen — auch mit demselben Kürzel.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
