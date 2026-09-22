<?php
/**
 * Confirm deleting a MANUAL entry (ADR-042 decisions 7 and 9): the number
 * stays consumed and the change log keeps what the entry was. A generated
 * entry, or one in a closed period, is refused here and again on POST.
 *
 * @var \Z77\Module\Financial\Entities\JournalEntry $entry
 * @var bool $editable
 * @var string $notEditableWhy
 * @var string $entityCsrf
 * @var callable $fmt
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$label      = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();

if (!$editable): ?>
<div class="be-actions">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Löschen nicht möglich</h2>
    </div>
    <div class="be-modal__body">
        <div class="be-modal__alert be-modal__alert--error">Buchung <?= e($label) ?>: <?= e($notEditableWhy) ?></div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($actionBase) ?>/delete">
    <input type="hidden" name="id"          value="<?= (int) $entry->getId() ?>">
    <input type="hidden" name="version"     value="<?= (int) $entry->getVersion() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Buchung löschen</h2>
    </div>
    <div class="be-modal__body">
        <p>Buchung <code><?= e($label) ?></code> vom <?= e($entry->getDate()->format('d.m.Y')) ?> — «<?= e($entry->getText()) ?>», <?= e($fmt($entry->total())) ?> — wirklich löschen?</p>
        <p class="be-form__hint">Die Nummer <?= e($label) ?> bleibt als Lücke im Journal; das Änderungsprotokoll hält fest, wer die Buchung wann gelöscht hat und was sie enthielt.
           Eine Buchung, deren Periode MWST-abgerechnet ist, lässt sich nur ohne MWST-Zeile löschen.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
