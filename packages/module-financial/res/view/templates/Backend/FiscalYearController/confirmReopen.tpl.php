<?php
/**
 * Confirm reopening a closed fiscal year (P5 part 1, owner decisions
 * 2026-09-30): ADMIN only (the host's access config), only the latest
 * closed year (reverse order), with a MANDATORY reason that goes into the
 * protocol. `FiscalYearCloseService::reopen()` decides again under lock.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var ?string $refusal       why it cannot be reopened (German), or null
 * @var int     $reasonLength  the longest reason the protocol keeps
 * @var string  $entityCsrf
 * @var string  $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/fiscal-year';

if ($refusal !== null): ?>
<div class="be-actions">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Öffnen nicht möglich</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-modal__alert be-modal__alert--error">Geschäftsjahr <?= e($year->getCode()) ?>: <?= e($refusal) ?></div>
    </div>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($actionBase) ?>/reopen">
    <input type="hidden" name="id"          value="<?= (int) $year->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Geschäftsjahr <?= e($year->getCode()) ?> wieder öffnen</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Wieder öffnen', 'kind' => 'danger'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <p>Geschäftsjahr <code><?= e($year->getCode()) ?></code> (<?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?>) wieder öffnen?</p>
        <p class="be-form__hint">Danach lässt sich im Jahr wieder buchen, ändern und löschen. Wer, wann und warum steht im Protokoll.</p>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="fiscal-year-reopen-reason">Grund</label>
            <textarea id="fiscal-year-reopen-reason" name="reason" class="be-form__control" rows="3" maxlength="<?= (int) $reasonLength ?>" required></textarea>
        </div>
    </div>
</form>
