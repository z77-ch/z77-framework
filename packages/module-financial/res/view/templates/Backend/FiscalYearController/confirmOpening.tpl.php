<?php
/**
 * Confirm the year opening (P5, owner decisions 2026-10-22): the entries «Eröffnung erstellen»
 * writes into the year now — ONE entry per account against the contra account: every
 * balance-sheet account with its closing balance of the year before, the result on the
 * annual-result account, then the contra account's two sums — and what it does with a standing
 * opening (each line marked «neu» / «geändert» / «wird entfernt», changed entries keep their
 * number and are logged; or nothing at all). When the predecessor is still open, the owner's note says the opening is
 * provisional. A refusal shows the sentence and no form. `YearOpeningService::create()`
 * computes again on POST. A confirm without an input field: the actions at the END
 * (ADR-049 rev. 2026-10-10).
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var ?string $refusal  why it cannot be created (German), or null
 * @var ?\Z77\Module\Financial\Services\YearOpeningPlan $plan
 * @var array{lines: list<array<string, mixed>>} $snapshot  the plan's lines in the change log's snapshot shape
 * @var string $existingRefs  the standing opening's entries as a phrase («45 Buchungen 2026/1–2026/45»)
 * @var string $total  Σ Soll = Σ Haben, formatted
 * @var ?string $provisional  the note when the predecessor is still open
 * @var string $entityCsrf
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/fiscal-year';

if ($refusal !== null || $plan === null): ?>
<div class="be-actions">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Eröffnung nicht möglich</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-modal__alert be-modal__alert--error" data-opening-refusal>Geschäftsjahr <?= e($year->getCode()) ?>: <?= e((string) $refusal) ?></div>
    </div>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($actionBase) ?>/opening">
    <input type="hidden" name="id"          value="<?= (int) $year->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= !$plan->hasOpening() ? 'Eröffnung ' . e($year->getCode()) . ' erstellen' : 'Eröffnung ' . e($year->getCode()) . ' neu erstellen' ?></h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => !$plan->hasOpening() ? 'Eröffnung erstellen' : 'Neu erstellen', 'end' => true], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($provisional !== null): ?>
        <div class="be-modal__alert" data-opening-provisional><span class="badge badge--warning">provisorisch</span> <?= e($provisional) ?></div>
        <?php endif; ?>
        <p>Die Schlusssalden des Geschäftsjahrs <code><?= e($plan->predecessorCode) ?></code> werden per <?= e($year->getStartDate()->format('d.m.Y')) ?> eröffnet —
           je Konto eine Buchung gegen das Eröffnungskonto (<?= count($plan->requests) ?> Buchungen), der Erfolg des Vorjahrs auf das Konto für den Jahresgewinn/-verlust.</p>
        <?php if ($plan->isUpToDate()): ?>
        <p class="be-form__hint" data-opening-unchanged>Die bestehende Eröffnung (<?= e($existingRefs) ?>) ist aktuell — es wird nichts geändert.</p>
        <?php elseif ($plan->hasOpening() && $plan->requests === []): ?>
        <p class="be-form__hint" data-opening-remove>Das Vorjahr hat keine Salden mehr — die bestehende Eröffnung (<?= e($existingRefs) ?>) wird entfernt; die Nummern bleiben als dokumentierte Lücken im Änderungsprotokoll.</p>
        <?php elseif ($plan->hasOpening()): ?>
        <p class="be-form__hint" data-opening-replace>Die bestehende Eröffnung (<?= e($existingRefs) ?>) wird nachgeführt: <?= count($plan->toAmend) ?> geändert (gleiche Nummer), <?= count($plan->toPost) ?> neu, <?= count($plan->toRetract) ?> entfernt, <?= $plan->unchangedCount() ?> unverändert — jede Änderung steht im Änderungsprotokoll.</p>
        <?php endif; ?>
        <?php if ($snapshot['lines'] !== []): ?>
        <?= $this->partial('Backend/partials/entrySnapshot', ['snapshot' => $snapshot], 'Z77\\Module\\Financial') ?>
        <p class="be-form__hint">Soll = Haben: <?= e($total) ?></p>
        <?php endif; ?>
    </div>
</form>
