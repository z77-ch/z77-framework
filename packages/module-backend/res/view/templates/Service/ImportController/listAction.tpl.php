<?php
/**
 * Data import (ADR-032) — pick a source, review the plan, decide, apply.
 *
 * Pure renderer: every row is a controller-built array (no service calls in
 * the template). The plan is grouped by outcome, important first: unclear
 * (needs a human), changed (per-record opt-in, default No), new (bulk-
 * acceptable), blocked, invalid, skipped.
 *
 * @var array|null $planView   {sourceLabel, createdAt, summary, groups, acceptedCount}
 * @var array|null $state      raw plan-store state (null = no current plan)
 * @var string|null $staleError source/target moved — plan must be discarded
 * @var array|null $lastResult {at, via, applied, failed, lines}
 * @var list<string> $vendorClasses entity short names the vendor defaults cover
 * @var list<array{name:string,size:int,mtime:int}> $inbox
 * @var array<class-string, string> $entityOptions
 * @var int $jobThreshold
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

    <?php if ($staleError !== null): ?>
    <div class="be-modal__alert be-modal__alert--error">
        <strong>Der Plan ist nicht mehr gültig.</strong> <?= e($staleError) ?>
        Verwerfen in der Werkzeugzeile oben, dann neu berechnen.
        <?php /* «Plan verwerfen» stands in the toolbar (`list.hc2.tpl.php`) — the one fixed row
                 of a screen action (ADR-049 rev. 2026-10-10), not inside this alert. */ ?>
    </div>
    <?php endif; ?>

    <?php if ($lastResult !== null): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Letzte Übernahme</h2>
        </div>
        <p class="be-list__section-hint">
            <?= e($fmt($lastResult['at'])) ?> ·
            <?= (int) $lastResult['applied'] ?> übernommen,
            <?= (int) $lastResult['failed'] ?> fehlgeschlagen
            (<?= $lastResult['via'] === 'job' ? 'als Job' : 'direkt' ?>)
        </p>
        <?php if ((int) $lastResult['failed'] > 0): ?>
        <div class="be-tree be-tree--hub be-tree--lead-none">
            <?php foreach ($lastResult['lines'] as $line): if ($line['status'] !== 'failed') continue; ?>
            <div class="be-tree__node">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name"><?= e($line['entry']) ?></span>
                    <span class="be-tree__url"><?= e($line['message']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($planView === null && $staleError === null): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Quelle wählen</h2>
        </div>
        <p class="be-list__section-hint">
            Der Import ÜBERNIMMT Datensätze — er ersetzt nie, löscht nie, und schreibt
            erst nach deiner Bestätigung pro Eintrag.
        </p>

        <div class="be-tree be-tree--hub be-tree--lead-none">
            <div class="be-tree__node">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name">Framework-Standarddaten</span>
                    <span class="be-tree__url be-tree__url--text">
                        <?= e(implode(', ', $vendorClasses)) ?> — was das installierte Framework
                        mitliefert, verglichen mit deiner Installation
                    </span>
                    <?php // Its «Plan berechnen» is the action cell (`list.act.tpl.php`, ADR-033
                          // rev. 2026-10-08) — the most frequent source, so no second button here. ?>
                </div>
            </div>

            <?php foreach ($inbox as $file): ?>
            <div class="be-tree__node">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name"><?= e($file['name']) ?></span>
                    <span class="be-tree__url be-tree__url--text">
                        Inbox · <?= e(number_format($file['size'] / 1024, 1, '.', "'")) ?> KB ·
                        <?= e(date('d.m.Y H:i', $file['mtime'])) ?>
                    </span>
                    <form class="be-tree__tools" data-fetch-post="/backend/service/import/start-inbox">
                        <input type="hidden" name="file" value="<?= e($file['name']) ?>">
                        <select name="entity" class="be-input be-input--sm" aria-label="Ziel">
                            <?php foreach ($entityOptions as $class => $label): ?>
                            <option value="<?= e($class) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="be-btn">Plan berechnen</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="be-list__note">
            Eigene Datei importieren: als JSON per FTP/Explorer nach
            <code>data/framework/import/inbox/</code> legen — sie erscheint dann hier.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($planView !== null): ?>
    <?= $this->partial('Service/ImportController/_plan', ['planView' => $planView], 'Z77\\Module\\Backend') ?>
    <?php endif; ?>
</div>
