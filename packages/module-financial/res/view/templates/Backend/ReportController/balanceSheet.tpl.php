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
    <?= $this->partial('Backend/ReportController/rangeForm', ['atDay' => true] + ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep, 'pdfTabs' => $pdfTabs ?? []], $ns) ?>
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
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + ['section' => $report->assets, 'totalLabel' => 'Total Aktiven', 'total' => $report->assets->total], $ns) ?>
        </div>
    </div>
    <?php // No «Passiven» title of its own: the class row «2 Passiven» carries the name
          // (owner 2026-10-10), the two blocks below are its Fremd- and Eigenkapital. ?>
    <div class="be-list__section">
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + ['section' => $report->liabilities, 'totalLabel' => 'Total Fremdkapital', 'total' => $report->liabilities->total], $ns) ?>
        </div>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + [
                'section'    => $report->equity,
                'extra'      => [['label' => ($report->result->isNegative() ? 'Jahresverlust ' : 'Jahresgewinn ') . $range->year->getCode(), 'amount' => $report->result]],
                'totalLabel' => 'Total Eigenkapital',
                'total'      => $report->totalEquity(),
            ], $ns) ?>
        </div>
        <?php // Total Passiven closes the sheet; no repeat of Total Aktiven (owner 2026-10-10:
              // «überflüssig» — it stands under the Aktiven already). ?>
        <div class="be-list__frame">
            <div class="be-list__table" style="--be-list-cols: 6rem minmax(12rem, 1fr) 9rem">
                <div class="be-list__item"><div class="be-list__row be-list__row--total">
                    <span class="be-list__cell"></span>
                    <span class="be-list__cell">Total Passiven</span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($report->totalLiabilitiesAndEquity())) ?></span>
                </div></div>
            </div>
        </div>
    </div>
</div>
