<?php
/**
 * One job row — label, state line, «Jetzt einreihen», the inline «Zeitplan setzen», the
 * schedule switch. Rendered by the list and by JobController as the in-place answer of every
 * action that touches the job (`replaceRow('job', key)`, ADR-047 addendum 2026-10-10); core.js
 * wires the forms and the switch it brings (FETCH-ROW-001).
 *
 * «Zeitplan setzen» stays a one-field edit inline in the row — the owner has not ruled on cell
 * edits yet (forms-actions review 2026-10-10, row 27; docs/topics/jobs.md).
 *
 * @var array{key:string,label:string,module:string,runAs:string,schedule:?\Z77\Shared\Entities\JobSchedule,openCount:int,lastRun:?\Z77\Shared\Entities\JobRun} $job
 */

$muted    = 'color:var(--be-muted,#94a3b8)';
$schedule = $job['schedule'];
?>
<div class="be-tree__node" style="--node-depth:0" data-entity="job:<?= e($job['key']) ?>">
    <div class="be-tree__row">
        <span class="be-tree__toggle" aria-hidden="true"></span>
        <span class="be-tree__name">
            <?= e($job['label']) ?>
            <code style="font-size:.7rem;<?= $muted ?>"><?= e($job['key']) ?></code>
        </span>
        <span class="be-tree__url" data-field="state"><?= $this->partial('Service/JobController/_jobState', ['job' => $job], 'Z77\\Module\\Backend') ?></span>
    </div>

    <?php // NOT a `be-tree__row`: in a `be-tree--hub` container that row is an
          // explicit 6-column grid (1rem/2.4rem/1.6rem/…), so free-form controls
          // auto-place into the narrow icon columns and overlap. ?>
    <div style="padding:0 0 .6rem 2.1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <form data-fetch-post="/backend/service/job/run" style="margin:0">
            <input type="hidden" name="job" value="<?= e($job['key']) ?>">
            <button type="submit" class="be-btn be-btn--primary">Jetzt einreihen</button>
        </form>

        <form data-fetch-post="/backend/service/job/schedule" style="margin:0;display:flex;gap:.35rem;align-items:center">
            <input type="hidden" name="job" value="<?= e($job['key']) ?>">
            <div class="be-form__field" style="margin:0;width:11rem">
                <input type="text" name="expression"
                       value="<?= e($schedule?->getExpression() ?? '') ?>"
                       placeholder="daily@03:15" autocomplete="off">
            </div>
            <button type="submit" class="be-btn">Zeitplan setzen</button>
        </form>

        <?php if ($schedule !== null): ?>
        <?php // On/off is a `.be-switch` (one look per kind, css-backend.md); the core
              // `data-fetch-toggle` contract POSTs on change, `?job=` names the schedule. ?>
        <label class="be-switch be-switch--sm" title="Zeitplan ein- oder ausschalten">
            <input type="checkbox" class="be-switch__input"
                   data-fetch-toggle="/backend/service/job/toggle?job=<?= e(rawurlencode($job['key'])) ?>"<?= $schedule->isEnabled() ? ' checked' : '' ?>>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
            <span class="be-switch__label">Zeitplan aktiv</span>
        </label>
        <?php endif; ?>

        <span style="font-size:.7rem;<?= $muted ?>">
            Modul <?= e($job['module']) ?> · Rolle <?= e($job['runAs']) ?>
        </span>
    </div>
</div>
