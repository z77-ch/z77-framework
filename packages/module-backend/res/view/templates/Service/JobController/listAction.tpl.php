<?php
/**
 * Job list — what a module offers, when it is scheduled, what is waiting, what
 * failed. The screen queues work; the runner (`vendor/bin/z77-run`, once a
 * minute via cron) is the only thing that executes it.
 *
 * The heartbeat banner is the important part: without a cron line every button
 * here queues work that is never picked up, and nothing else on the page would
 * say so.
 *
 * @var list<array{key:string,label:string,module:string,runAs:string,schedule:?\Z77\Shared\Entities\JobSchedule,openCount:int,lastRun:?\Z77\Shared\Entities\JobRun}> $jobs
 * @var list<\Z77\Shared\Entities\JobRun> $open
 * @var list<\Z77\Shared\Entities\JobRun> $history
 * @var array{at:string,summary:array}|null $heartbeat
 * @var bool   $heartbeatOk
 * @var string $scheduleHelp
 */

$muted = 'color:var(--be-muted,#94a3b8)';

$fmt = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);

    return $ts === false ? $iso : date('d.m.Y H:i', $ts);
};

$stateLabel = [
    'queued'  => 'wartet',
    'running' => 'läuft',
    'done'    => 'erledigt',
    'failed'  => 'fehlgeschlagen',
];
?>
<div class="be-list">

    <?php if (!$heartbeatOk): ?>
    <div class="be-modal__alert be-modal__alert--error" style="margin-bottom:1.25rem">
        <strong>Der Job-Runner läuft nicht.</strong>
        <?php if ($heartbeat === null): ?>
        Es hat noch nie ein Durchlauf stattgefunden.
        <?php else: ?>
        Letzter Durchlauf: <?= e($fmt($heartbeat['at'])) ?>.
        <?php endif; ?>
        Ohne Cron-Eintrag wird hier nichts ausgeführt. Auf dem Server eintragen:
        <code>* * * * * cd /pfad/zum/projekt &amp;&amp; php vendor/bin/z77-run</code>
    </div>
    <?php endif; ?>
    <?php /* The healthy case says nothing here — the heartbeat line lives in the shell header
             band (`list.hc2.tpl.php`). Only the FAILURE keeps a body block, because it carries
             the cron command to paste and would never fit the 46px band. */ ?>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Jobs</h2>
            <span class="be-list__section-badge"><?= count($jobs) ?></span>
        </div>
        <p class="be-list__section-hint">
            Zeitplan-Formen: <code><?= e($scheduleHelp) ?></code> — leer lassen entfernt den Zeitplan.
        </p>

        <?php if (empty($jobs)): ?>
        <p class="be-list__empty">Kein Modul bietet Jobs an.</p>
        <?php endif; ?>

        <div class="be-tree be-tree--hub">
            <?php foreach ($jobs as $job):
                $schedule = $job['schedule'];
                $lastRun  = $job['lastRun'];
            ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name">
                        <?= e($job['label']) ?>
                        <code style="font-size:.7rem;<?= $muted ?>"><?= e($job['key']) ?></code>
                    </span>
                    <span class="be-tree__url">
                        <?php if ($schedule === null): ?>
                        kein Zeitplan
                        <?php else: ?>
                        <code><?= e($schedule->getExpression()) ?></code>
                        &nbsp;·&nbsp; <?= $schedule->isEnabled() ? 'aktiv, nächster ' . e($fmt($schedule->getNextRunAt())) : 'ausgeschaltet' ?>
                        <?php endif; ?>
                        <?php if ($job['openCount'] > 0): ?>
                        &nbsp;·&nbsp; <?= e((string) $job['openCount']) ?> offen
                        <?php endif; ?>
                        <?php if ($lastRun !== null): ?>
                        &nbsp;·&nbsp; zuletzt <?= e($fmt($lastRun->getFinishedAt())) ?>
                        (<?= e($stateLabel[$lastRun->getState()] ?? $lastRun->getState()) ?>)
                        <?php endif; ?>
                    </span>
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
                    <form data-fetch-post="/backend/service/job/toggle" style="margin:0">
                        <input type="hidden" name="job" value="<?= e($job['key']) ?>">
                        <button type="submit" class="be-btn"><?= $schedule->isEnabled() ? 'Ausschalten' : 'Einschalten' ?></button>
                    </form>
                    <?php endif; ?>

                    <span style="font-size:.7rem;<?= $muted ?>">
                        Modul <?= e($job['module']) ?> · Rolle <?= e($job['runAs']) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($open)): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Wartet</h2>
            <span class="be-list__section-badge"><?= count($open) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php foreach ($open as $entry): ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name"><?= e($entry->getJobKey()) ?></span>
                    <span class="be-tree__url">
                        <?= e($stateLabel[$entry->getState()] ?? $entry->getState()) ?>
                        &nbsp;·&nbsp; ab <?= e($fmt($entry->getAvailableAt())) ?>
                        <?php if ($entry->getAttempts() > 0): ?>
                        &nbsp;·&nbsp; <?= e((string) $entry->getAttempts()) ?>. Versuch
                        <?php endif; ?>
                        &nbsp;·&nbsp; von <?= e($entry->getCreatedBy()) ?>
                        <?php if ($entry->getNote() !== ''): ?>
                        <br><?= e($entry->getNote()) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($entry->getState() !== 'running'): ?>
                    <?php // grid-column:6 — the hub row is an explicit grid; without it the
                          // form auto-places into the 2.4rem icon column. ?>
                    <form data-fetch-post="/backend/service/job/remove" style="margin:0;grid-column:6">
                        <input type="hidden" name="id" value="<?= e((string) $entry->getId()) ?>">
                        <button type="submit" class="be-btn">Entfernen</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Verlauf</h2>
            <span class="be-list__section-badge"><?= count($history) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if (empty($history)): ?>
            <p class="be-list__empty">Noch keine abgeschlossenen Läufe.</p>
            <?php endif; ?>
            <?php foreach ($history as $entry): ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name"><?= e($entry->getJobKey()) ?></span>
                    <span class="be-tree__url">
                        <?= e($stateLabel[$entry->getState()] ?? $entry->getState()) ?>
                        &nbsp;·&nbsp; <?= e($fmt($entry->getFinishedAt())) ?>
                        &nbsp;·&nbsp; von <?= e($entry->getCreatedBy()) ?>
                        <?php if ($entry->getNote() !== ''): ?>
                        <br><?= e($entry->getNote()) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($entry->getState() === 'failed'): ?>
                    <form data-fetch-post="/backend/service/job/retry" style="margin:0;grid-column:6">
                        <input type="hidden" name="id" value="<?= e((string) $entry->getId()) ?>">
                        <button type="submit" class="be-btn">Nochmals</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
