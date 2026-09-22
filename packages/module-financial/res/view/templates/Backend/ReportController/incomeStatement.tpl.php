<?php
/**
 * Income statement (Erfolgsrechnung, plan §5.5) over the range: Ertrag and
 * Aufwand, each under the chart's groups — the account TYPE decides the
 * block, so a mixed KMU group (69 with 6950 Finanzertrag) appears in both
 * with its own accounts —, and the result Ertrag − Aufwand. Amounts
 * positive on their natural side (financial.md, sign convention).
 *
 * Styling: the shared backend list v2 classes only.
 *
 * @var \Z77\Module\Financial\Reports\IncomeStatement $report
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var callable $link
 * @var callable $fmt
 */
$ns     = 'Z77\\Module\\Financial';
$result = $report->result();
$shared = ['link' => $link, 'fmt' => $fmt];
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep], $ns) ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Erfolgsrechnung <code><?= e($range->year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($range->from->format('d.m.Y')) ?> – <?= e($range->to->format('d.m.Y')) ?></small>
            </h2>
        </div>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + ['section' => $report->revenue, 'totalLabel' => 'Total Ertrag', 'total' => $report->revenue->total], $ns) ?>
        </div>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + ['section' => $report->expense, 'totalLabel' => 'Total Aufwand', 'total' => $report->expense->total], $ns) ?>
        </div>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(12rem, 1fr) 9rem">
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell"></span>
                <span class="be-list__cell"><strong><?= $result->isNegative() ? 'Verlust' : 'Gewinn' ?> (Ertrag − Aufwand)</strong></span>
                <span class="be-list__cell be-list__cell--num" data-result><strong><?= e($fmt($result)) ?></strong></span>
            </div></div>
        </div>
    </div>
</div>
