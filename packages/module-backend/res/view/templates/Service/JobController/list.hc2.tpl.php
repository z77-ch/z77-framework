<?php
/**
 * Job list — hc2 (middle slot): the runner heartbeat as a one-line state indicator.
 *
 * Why this and not an add action: queueing is per job, so there is no single primary
 * action for hc1. The one thing that belongs at the top of THIS screen is whether the
 * runner is alive — without a cron line every button in the body queues work nothing
 * ever picks up.
 *
 * The full failure explanation (with the cron command to paste) stays in the body: the
 * band is a fixed 46px single line by contract (css-backend.md) and must not grow.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 *
 * @var array{at:string,summary:array}|null $heartbeat
 * @var bool $heartbeatOk
 */

$at = $heartbeat['at'] ?? '';
$ts = $at === '' ? false : strtotime($at);
$at = $ts === false ? $at : date('d.m.Y H:i', $ts);
?>
<span class="be-shell-status be-shell-status--<?= $heartbeatOk ? 'ok' : 'bad' ?>">
    <span class="be-shell-status__dot" aria-hidden="true"></span>
    <span class="be-shell-status__text">
        <?php if ($heartbeatOk): ?>
        Runner läuft — letzter Durchlauf <?= e($at) ?>
        <?php elseif ($heartbeat === null): ?>
        Runner läuft nicht — noch nie ein Durchlauf
        <?php else: ?>
        Runner läuft nicht — letzter Durchlauf <?= e($at) ?>
        <?php endif; ?>
    </span>
</span>
