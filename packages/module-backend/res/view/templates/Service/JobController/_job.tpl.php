<?php
/**
 * One job row, ONE line (owner 2026-10-10, «dieser Zickzack muss besser werden»):
 *
 *   [schedule on/off] [⋮] Label `key`   state …          Modul · Rolle  [▶]
 *
 * - the switch turns an existing schedule on or off (empty slot while there is none);
 * - the ⋮ opens the row's action hub («Zeitplan …» opens the schedule DIALOG, R2 — the
 *   one-field edit no longer sits inline in the row);
 * - ▶ queues the job now. It is a HELPER action in a row, so it is an icon alone — the word
 *   («Jetzt starten») is its accessible name and title, not a label beside it.
 *
 * Rendered by the list and by JobController as the in-place answer of every action that
 * touches the job (`replaceRow('job', key)`, ADR-047 addendum 2026-10-10); core.js wires the
 * form, the switch and the ⋮ it brings (FETCH-ROW-001).
 *
 * @var array{key:string,label:string,module:string,runAs:string,schedule:?\Z77\Shared\Entities\JobSchedule,openCount:int,lastRun:?\Z77\Shared\Entities\JobRun} $job
 */

$schedule = $job['schedule'];
$jobQs    = 'job=' . e(rawurlencode($job['key']));
?>
<div class="be-tree__node" style="--node-depth:0" data-entity="job:<?= e($job['key']) ?>">
    <div class="be-tree__row">
        <span class="be-tree__toggle" aria-hidden="true"></span>
        <?php if ($schedule !== null): ?>
        <?php // On/off is a `.be-switch` (one look per kind, css-backend.md); the core
              // `data-fetch-toggle` contract POSTs on change, `?job=` names the schedule. ?>
        <label class="be-switch be-switch--sm be-tree__switch" title="Zeitplan ein- oder ausschalten">
            <input type="checkbox" class="be-switch__input"
                   data-fetch-toggle="/backend/service/job/toggle?<?= $jobQs ?>"<?= $schedule->isEnabled() ? ' checked' : '' ?>>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        </label>
        <?php else: ?>
        <span class="be-tree__switch" aria-hidden="true"></span>
        <?php endif; ?>
        <button type="button" class="be-tree__menu" title="Aktionen"
                data-fetch-get="/backend/service/job/actions?<?= $jobQs ?>">⋮</button>
        <span class="be-tree__name">
            <?= e($job['label']) ?>
            <code class="be-tree__key"><?= e($job['key']) ?></code>
        </span>
        <span class="be-tree__url" data-field="state"><?= $this->partial('Service/JobController/_jobState', ['job' => $job], 'Z77\\Module\\Backend') ?></span>
        <span class="be-tree__route be-tree__tools">
            <span class="be-tree__meta">Modul <?= e($job['module']) ?> · Rolle <?= e($job['runAs']) ?></span>
            <form data-fetch-post="/backend/service/job/run">
                <input type="hidden" name="job" value="<?= e($job['key']) ?>">
                <button type="submit" class="be-icon-btn" title="Jetzt starten" aria-label="Jetzt starten">
                    <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-play"/></svg>
                </button>
            </form>
        </span>
    </div>
</div>
