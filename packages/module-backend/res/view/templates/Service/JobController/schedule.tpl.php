<?php
/**
 * The schedule dialog of one job (R2, forms-actions review 2026-10-10 row 27): the one field
 * that used to sit inline in the row, now a popup with the fixed action row under its title.
 * An empty expression removes the schedule. The save answers in place: the job row is
 * re-rendered (`replaceRow`) and the dialog closes.
 *
 * @var array{key:string,label:string,schedule:?\Z77\Shared\Entities\JobSchedule} $job
 * @var string $scheduleHelp the accepted forms, e.g. «every:15m · daily@03:15 · …»
 */
$expression = $job['schedule']?->getExpression() ?? '';
?>
<form data-fetch-post="/backend/service/job/schedule">
    <input type="hidden" name="job" value="<?= e($job['key']) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Zeitplan — «<?= e($job['label']) ?>»</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Speichern'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="job-schedule-expression">Zeitplan</label>
            <input type="text" id="job-schedule-expression" name="expression" value="<?= e($expression) ?>"
                   placeholder="daily@03:15" autocomplete="off" autofocus>
            <small class="be-form__hint">Formen: <code><?= e($scheduleHelp) ?></code> — leer lassen entfernt den Zeitplan.</small>
        </div>
    </div>
</form>
