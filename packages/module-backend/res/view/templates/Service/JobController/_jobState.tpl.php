<?php
/**
 * The state line of one job row — schedule, open count, last run. Part of `_job.tpl.php`, the
 * row every JobController answer re-renders in place.
 *
 * @var array{schedule: ?\Z77\Shared\Entities\JobSchedule, openCount: int, lastRun: ?\Z77\Shared\Entities\JobRun} $job
 */

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

$schedule = $job['schedule'];
$lastRun  = $job['lastRun'];
?>
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
