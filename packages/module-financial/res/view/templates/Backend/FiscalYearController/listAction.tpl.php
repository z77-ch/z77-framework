<?php
/**
 * The fiscal years, newest first, each with its monthly periods and their
 * close state (ADR-042 decision 10). A year is opened through the toolbar
 * button and never edited; the LATEST and the EARLIEST year carry «Löschen …»
 * while nothing was ever posted in them (`$deletableIds`, FIN-FY-002 — the
 * modal and the service decide again). Closing (P5 part 1, owner decisions 2026-09-30): each year
 * carries its state (offen / abgeschlossen — the whole year, all periods), «Jahr abschliessen …»
 * on the year that may be closed next (`$closableIds`, in order), «Wieder öffnen …» on the latest
 * closed year for who may reach the action (`$reopenableIds`, ADMIN), and the last protocol rows
 * (`$logLines`, small and muted).
 *
 * Styling: the shared backend list classes only (`.be-list__section`,
 * `.be-list__table`, `.be-list__cell--muted`, `.be-list__empty`, badges) —
 * no CSS of its own.
 *
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years  newest first, periods loaded
 * @var array<string,string> $stateLabels
 * @var list<int> $deletableIds  the years that may be deleted (at most the latest and the earliest)
 * @var list<int> $closedIds     the years whose periods are all closed
 * @var list<int> $closableIds   the years that may be closed now (order rule, lock-free)
 * @var list<int> $reopenableIds the years the current user may reopen now
 * @var array<int, list<string>> $logLines  year id → the latest protocol lines, newest first
 * @var string $actionBase
 */
$actionBase    = $actionBase ?? '/backend/finance/fiscal-year';
$deletableIds  = $deletableIds ?? [];
$closedIds     = $closedIds ?? [];
$closableIds   = $closableIds ?? [];
$reopenableIds = $reopenableIds ?? [];
$logLines      = $logLines ?? [];
$badge = [
    'open'        => 'badge--success',
    'vat-settled' => 'badge--warning',
    'closed'      => 'badge--muted',
];
?>
<div class="be-list">
    <?php if ($years === []): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Geschäftsjahre</h2>
            <span class="be-list__section-badge">0</span>
        </div>
        <p class="be-list__empty">Noch kein Geschäftsjahr eröffnet — ohne Geschäftsjahr kann nichts gebucht werden.</p>
    </div>
    <?php endif; ?>
    <?php foreach ($years as $year): ?>
    <?php $periods = $year->getPeriods(); $yearClosed = in_array($year->getId(), $closedIds, true); ?>
    <div class="be-list__section" data-fiscal-year-id="<?= e((string) $year->getId()) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Geschäftsjahr <code><?= e($year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?> · Nummernkreis <code><?= e($year->journalEntryRange()) ?></code></small>
                <span class="badge <?= $yearClosed ? 'badge--muted' : 'badge--success' ?>" data-fiscal-year-state="<?= $yearClosed ? 'closed' : 'open' ?>"><?= $yearClosed ? 'abgeschlossen' : 'offen' ?></span>
            </h2>
            <?php if (in_array($year->getId(), $closableIds, true)): ?>
            <button type="button" class="be-btn be-btn--primary be-btn--sm" data-fetch-get="<?= e($actionBase) ?>/confirm-close?id=<?= e((string) $year->getId()) ?>">Jahr abschliessen …</button>
            <?php endif; ?>
            <?php if (in_array($year->getId(), $reopenableIds, true)): ?>
            <button type="button" class="be-btn be-btn--ghost be-btn--sm" data-fetch-get="<?= e($actionBase) ?>/confirm-reopen?id=<?= e((string) $year->getId()) ?>">Wieder öffnen …</button>
            <?php endif; ?>
            <?php if (in_array($year->getId(), $deletableIds, true)): ?>
            <button type="button" class="be-btn be-btn--danger be-btn--sm" data-fetch-get="<?= e($actionBase) ?>/confirm-delete?id=<?= e((string) $year->getId()) ?>">Löschen …</button>
            <?php endif; ?>
            <span class="be-list__section-badge" title="Perioden"><?= count($periods) ?></span>
        </div>
        <?php foreach ($logLines[$year->getId()] ?? [] as $line): ?>
        <p class="be-list__cell--muted" data-fiscal-year-log><small><?= e($line) ?></small></p>
        <?php endforeach; ?>
        <div class="be-list__table">
            <?php foreach ($periods as $period): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($period->getStartDate()->format('d.m.Y')) ?> – <?= e($period->getEndDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell">
                        <span class="badge <?= e($badge[$period->getState()] ?? 'badge--muted') ?>"><?= e($stateLabels[$period->getState()] ?? $period->getState()) ?></span>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
