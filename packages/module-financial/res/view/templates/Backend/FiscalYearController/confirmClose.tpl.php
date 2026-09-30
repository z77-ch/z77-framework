<?php
/**
 * Confirm closing a fiscal year (P5 part 1, owner decisions 2026-09-30):
 * only the whole year, in order. Lists what the close check found —
 * a BLOCKING finding refuses the close (no submit button), WARNINGS need
 * the checkbox `confirm_warnings`. `FiscalYearCloseService::close()` asks
 * again under lock on POST.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var ?string $refusal  why it cannot be closed (German, order rule), or null
 * @var list<\Z77\Persistence\Doctrine\OpenWork\Finding> $blocking
 * @var list<\Z77\Persistence\Doctrine\OpenWork\Finding> $warnings
 * @var string $warningsHash  the fingerprint of exactly these warnings — the POST confirms them, and a
 *                            changed set under the lock is refused (review 2026-09-30)
 * @var string $entityCsrf
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/fiscal-year';
$title      = 'Geschäftsjahr ' . $year->getCode() . ' abschliessen';

if ($refusal !== null || $blocking !== []): ?>
<div class="be-actions">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Abschliessen nicht möglich</h2>
    </div>
    <div class="be-modal__body">
        <?php if ($refusal !== null): ?>
        <div class="be-modal__alert be-modal__alert--error">Geschäftsjahr <?= e($year->getCode()) ?>: <?= e($refusal) ?></div>
        <?php else: ?>
        <div class="be-modal__alert be-modal__alert--error">Geschäftsjahr <?= e($year->getCode()) ?>: Es ist noch etwas offen, das den Abschluss verhindert.</div>
        <ul data-close-blocking>
            <?php foreach ($blocking as $finding): ?>
            <li><?= e($finding->message) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($actionBase) ?>/close">
    <input type="hidden" name="id"          value="<?= (int) $year->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <input type="hidden" name="warnings_hash" value="<?= e($warningsHash ?? '') ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= e($title) ?></h2>
    </div>
    <div class="be-modal__body">
        <p>Geschäftsjahr <code><?= e($year->getCode()) ?></code> (<?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?>) abschliessen?</p>
        <p class="be-form__hint">Alle <?= count($year->getPeriods()) ?> Perioden werden abgeschlossen: danach wird im Jahr nichts mehr gebucht,
           geändert oder gelöscht. Eine Korrektur ist eine Buchung in einem offenen Jahr. Ein Admin kann das Jahr mit Begründung wieder öffnen;
           Abschluss und Öffnen stehen im Protokoll.</p>
        <?php if ($warnings !== []): ?>
        <p><strong>Warnungen</strong></p>
        <ul data-close-warnings>
            <?php foreach ($warnings as $finding): ?>
            <li><?= e($finding->message) ?></li>
            <?php endforeach; ?>
        </ul>
        <label class="be-choice">
            <input type="checkbox" class="be-choice__input" name="confirm_warnings" value="1" required>
            <span class="be-choice__label">Warnungen gelesen — trotzdem abschliessen</span>
        </label>
        <?php else: ?>
        <p class="be-form__hint">Die Abschlussprüfung hat nichts Offenes gefunden.</p>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Abschliessen</button>
    </div>
</form>
