<?php
/**
 * The fiscal years, newest first: per year a header with the ⋮ BEFORE the title (the year
 * actions, `actionsAction` hub — owner 2026-10-10), the state badge, the protocol lines, and
 * the months in ONE line, marked only where a month differs from the year (FIN-UI-012). A year is opened through the toolbar
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
$monthNames = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
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
    <?php
    // What the ⋮ offers for this year — also on the section as `data-fiscal-year-actions`, the
    // hook the harness reads now that the confirm links live in the hub, not in the list.
    $yearActions = array_keys(array_filter([
        'close'  => in_array($year->getId(), $closableIds, true),
        'reopen' => in_array($year->getId(), $reopenableIds, true),
        'delete' => in_array($year->getId(), $deletableIds, true),
    ]));
    $hasActions = $yearActions !== [];
    ?>
    <div class="be-list__section" data-fiscal-year-id="<?= e((string) $year->getId()) ?>" data-fiscal-year-actions="<?= e(implode(' ', $yearActions)) ?>">
        <div class="be-list__section-header">
            <?php // The year's actions in a ⋮ BEFORE the title, as every backend list row has
                  // them (owner 2026-10-10) — no buttons at the far right any more. ?>
            <?php if ($hasActions): ?>
            <button type="button" class="be-tree__menu" title="Aktionen" data-fetch-get="<?= e($actionBase) ?>/actions?id=<?= e((string) $year->getId()) ?>">⋮</button>
            <?php else: ?>
            <?php // Keeps the titles in one line — the ⋮'s size without its look or hover. ?>
            <span class="be-tree__menu" aria-hidden="true" style="visibility:hidden"></span>
            <?php endif; ?>
            <h2 class="be-list__section-title">
                Geschäftsjahr <code><?= e($year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?> · Nummernkreis <code><?= e($year->journalEntryRange()) ?></code></small>
                <span class="badge <?= $yearClosed ? 'badge--muted' : 'badge--success' ?>" data-fiscal-year-state="<?= $yearClosed ? 'closed' : 'open' ?>"><?= $yearClosed ? 'abgeschlossen' : 'offen' ?></span>
            </h2>
        </div>
        <?php foreach ($logLines[$year->getId()] ?? [] as $line): ?>
        <p class="be-list__cell--muted" data-fiscal-year-log><small><?= e($line) ?></small></p>
        <?php endforeach; ?>
        <?php // The months in ONE line (owner 2026-10-10): only the YEAR is closed (financial.md,
              // «Only the whole YEAR is closed»), so a badge under every month only repeated the
              // year's state. A month is marked only where it differs from the year — later
              // «MWST abgerechnet» (P5 part 2). The full dates stay in the title attribute. ?>
        <p class="be-list__section-hint" data-fiscal-year-months>
            <?php foreach ($periods as $i => $period): ?>
            <?php $differs = $period->getState() !== ($yearClosed ? 'closed' : 'open'); ?>
            <?= $i > 0 ? ' · ' : '' ?><span title="<?= e($period->getStartDate()->format('d.m.Y') . ' – ' . $period->getEndDate()->format('d.m.Y')) ?>"><?= e($monthNames[(int) $period->getStartDate()->format('n') - 1] . ($period->getStartDate()->format('Y') !== $year->getStartDate()->format('Y') ? ' ' . $period->getStartDate()->format('y') : '')) ?></span><?php if ($differs): ?> <span class="badge <?= e($badge[$period->getState()] ?? 'badge--muted') ?>"><?= e($stateLabels[$period->getState()] ?? $period->getState()) ?></span><?php endif; ?>
            <?php endforeach; ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>
