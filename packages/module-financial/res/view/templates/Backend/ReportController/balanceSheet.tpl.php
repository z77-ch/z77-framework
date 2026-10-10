<?php
/**
 * Balance sheet (Bilanz, plan §5.5) at the «Stichtag» — from the fiscal
 * year's first day: Aktiven, then Passiven as Fremdkapital and Eigenkapital
 * apart, the current result (Ertrag − Aufwand) as a line in Eigenkapital.
 * Balanced says nothing; Aktiven ≠ Passiven is an error block at the top
 * (FIN-UI-008). Groups without a booked account are left out; every amount
 * is positive on its natural side (financial.md, sign convention). Before P5
 * the year's own entries only — no carried-forward balances (FIN-REPORT-001).
 *
 * Styling: the shared backend list v2 classes only.
 *
 * @var \Z77\Module\Financial\Reports\BalanceSheet $report
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var callable $link
 * @var callable $fmt
 */
$ns     = 'Z77\\Module\\Financial';
$ok     = $report->isBalanced();
$shared = ['link' => $link, 'fmt' => $fmt];
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['atDay' => true] + ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep, 'pdfTabs' => $pdfTabs ?? [], 'compareTabs' => $compareTabs ?? [], 'compare' => $compare ?? true], $ns) ?>
    <?php // A balanced sheet says nothing — that is the normal state. Only the FAULT is shown,
          // as an error block at the top (owner 2026-10-10); the carried-forward note of
          // FIN-REPORT-001 is gone with it, the figures speak for themselves. ?>
    <?php if (!$ok): ?>
    <div class="be-modal__alert be-modal__alert--error">
        <strong>Aktiven ≠ Passiven.</strong> Differenz <?= e($fmt($report->difference())) ?> — das Journal ist nicht ausgeglichen. Bitte melden.
    </div>
    <?php endif; ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Bilanz <code><?= e($range->year->getCode()) ?></code>
                <small class="be-list__cell--muted">· per <?= e($report->range->to->format('d.m.Y')) ?></small>
            </h2>
        </div>
        <?php // One block per section, one amount column per period (StatementComparison,
              // owner 2026-10-10: the last three years, «Vorjahre» off = the one period). ?>
        <?php foreach ($comparison->blocks as $block): ?>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementCompare', ['block' => $block, 'labels' => $comparison->labels, 'link' => $link, 'fmt' => $fmt], $ns) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
