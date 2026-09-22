<?php
/**
 * Balance sheet (Bilanz, plan §5.5) at the «Stichtag» — from the fiscal
 * year's first day: Aktiven, then Passiven as Fremdkapital and Eigenkapital
 * apart, the current result (Ertrag − Aufwand) as a line in Eigenkapital,
 * and whether Aktiven = Passiven. Groups without a booked account are left
 * out; every amount is positive on its natural side (financial.md, sign
 * convention). Before P5 the year's own entries only — no carried-forward
 * balances (FIN-REPORT-001), said on the page when the year is not the first.
 *
 * Styling: the shared backend list v2 classes only.
 *
 * @var \Z77\Module\Financial\Reports\BalanceSheet $report
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var list<\Z77\Module\Financial\Entities\FiscalYear> $years  newest first
 * @var callable $link
 * @var callable $fmt
 */
$ns     = 'Z77\\Module\\Financial';
$ok     = $report->isBalanced();
$first  = end($years) ?: null;
$later  = $first !== null && $first->getCode() !== $range->year->getCode();
$shared = ['link' => $link, 'fmt' => $fmt];
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['atDay' => true] + ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep], $ns) ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Bilanz <code><?= e($range->year->getCode()) ?></code>
                <small class="be-list__cell--muted">· per <?= e($report->range->to->format('d.m.Y')) ?></small>
            </h2>
        </div>
        <?php if ($later): ?>
        <p class="be-form__hint">Ohne Eröffnungsbuchung: Die Bilanz zeigt nur die Buchungen dieses Geschäftsjahres. Die Salden des Vorjahres werden erst mit dem Jahresabschluss (Eröffnungsbuchung) vorgetragen.</p>
        <?php endif; ?>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + ['section' => $report->assets, 'totalLabel' => 'Total Aktiven', 'total' => $report->assets->total], $ns) ?>
        </div>
    </div>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Passiven</h2>
        </div>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + ['section' => $report->liabilities, 'totalLabel' => 'Total Fremdkapital', 'total' => $report->liabilities->total], $ns) ?>
        </div>
        <div class="be-list__frame">
            <?= $this->partial('Backend/ReportController/statementSection', $shared + [
                'section'    => $report->equity,
                'extra'      => [['label' => $report->result->isNegative() ? 'Verlust laufendes Jahr (Ertrag − Aufwand)' : 'Gewinn laufendes Jahr (Ertrag − Aufwand)', 'amount' => $report->result]],
                'totalLabel' => 'Total Eigenkapital',
                'total'      => $report->totalEquity(),
            ], $ns) ?>
        </div>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(12rem, 1fr) 9rem">
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell"></span>
                <span class="be-list__cell"><strong>Total Passiven</strong></span>
                <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalLiabilitiesAndEquity())) ?></strong></span>
            </div></div>
        </div>
        <p class="be-form__hint">
            <span class="badge <?= $ok ? 'badge--success' : 'badge--danger' ?>"><?= $ok ? 'Aktiven = Passiven' : 'Aktiven ≠ Passiven' ?></span>
            <?= $ok ? 'Die Bilanz ist ausgeglichen.' : 'Differenz ' . e($fmt($report->difference())) . ' — das Journal ist nicht ausgeglichen. Bitte melden.' ?>
        </p>
    </div>
</div>
