<?php
/**
 * The fiscal years, newest first, each with its monthly periods and their
 * close state (ADR-042 decision 10). Read-only: a year is opened through
 * the hc1 button, never edited or deleted; the period states move with the
 * VAT return and the close (P5).
 *
 * Styling: the shared backend list classes only (`.be-list__section`,
 * `.be-list__table`, `.be-list__cell--muted`, `.be-list__empty`, badges) —
 * no CSS of its own.
 *
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years  newest first, periods loaded
 * @var array<string,string> $stateLabels
 * @var string $actionBase
 */
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
    <?php $periods = $year->getPeriods(); ?>
    <div class="be-list__section" data-fiscal-year-id="<?= e((string) $year->getId()) ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Geschäftsjahr <code><?= e($year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?> · Nummernkreis <code><?= e($year->journalEntryRange()) ?></code></small>
            </h2>
            <span class="be-list__section-badge" title="Perioden"><?= count($periods) ?></span>
        </div>
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
