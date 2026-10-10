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
 * Answers (ADR-047 addendum 2026-10-10): every action answers IN PLACE — the job row
 * (`_job.tpl.php`, `replaceRow('job', key)`) and, where the queue changes, the queue part
 * (`_queue.tpl.php`, `replace-html` of `[data-job-queue]`). No action reloads the screen.
 *
 * @var list<array{key:string,label:string,module:string,runAs:string,schedule:?\Z77\Shared\Entities\JobSchedule,openCount:int,lastRun:?\Z77\Shared\Entities\JobRun}> $jobs
 * @var list<\Z77\Shared\Entities\JobRun> $open
 * @var list<\Z77\Shared\Entities\JobRun> $history
 * @var array{at:string,summary:array}|null $heartbeat
 * @var bool   $heartbeatOk
 */

$fmt = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);

    return $ts === false ? $iso : date('d.m.Y H:i', $ts);
};
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
        <?php if (empty($jobs)): ?>
        <p class="be-list__empty">Kein Modul bietet Jobs an.</p>
        <?php endif; ?>

        <div class="be-tree be-tree--hub be-tree--lead-switch">
            <?php foreach ($jobs as $job): ?>
            <?= $this->partial('Service/JobController/_job', ['job' => $job], 'Z77\Module\Backend') ?>
            <?php endforeach; ?>
        </div>
    </div>

    <?= $this->partial('Service/JobController/_queue', ['open' => $open, 'history' => $history], 'Z77\Module\Backend') ?>
</div>
