<?php
/**
 * The queue part of the job screen — «Wartet» (only while something waits) and «Verlauf».
 * Rendered by the list and by JobController's answers that change the queue (`replace-html` of
 * `[data-job-queue]`: queue, retry, remove), ADR-047 addendum 2026-10-10; core.js wires the
 * «Entfernen» / «Nochmals» forms it brings (FETCH-ROW-001).
 *
 * @var list<\Z77\Shared\Entities\JobRun> $open
 * @var list<\Z77\Shared\Entities\JobRun> $history  newest first, already cut to the shown count
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
?>
<div data-job-queue>
    <?php if (!empty($open)): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Wartet</h2>
            <span class="be-list__section-badge"><?= count($open) ?></span>
        </div>
        <div class="be-tree be-tree--hub">
            <?php foreach ($open as $entry): ?>
            <div class="be-tree__node" style="--node-depth:0" data-entity="job-run:<?= e((string) $entry->getId()) ?>">
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
