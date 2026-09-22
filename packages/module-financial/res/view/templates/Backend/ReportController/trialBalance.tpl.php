<?php
/**
 * Trial balance (Saldobilanz, plan §5.5): every account with lines in the
 * range — Σ Soll, Σ Haben, and the balance in «Saldo Soll» or «Saldo
 * Haben». The totals row, and whether both pairs agree. The account number
 * links to its account statement over the same range.
 *
 * Styling: the shared backend list v2 classes only — no CSS and no
 * JavaScript of its own.
 *
 * @var \Z77\Module\Financial\Reports\TrialBalance $report
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var callable $link
 * @var callable $fmt
 */
$ns   = 'Z77\\Module\\Financial';
$cols = '--be-list-cols: 5rem minmax(12rem, 2fr) 8rem 8rem 8rem 8rem';
$ok   = $report->isBalanced();
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep], $ns) ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Saldobilanz <code><?= e($range->year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($range->from->format('d.m.Y')) ?> – <?= e($range->to->format('d.m.Y')) ?></small>
            </h2>
            <span class="be-list__section-badge" title="Konten mit Buchungen"><?= count($report->rows) ?></span>
        </div>
        <?php if ($report->rows === []): ?>
        <p class="be-list__empty">Keine Buchung in diesem Zeitraum.</p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table" style="<?= $cols ?>">
                <div class="be-list__head">
                    <span class="be-list__col">Konto</span>
                    <span class="be-list__col">Bezeichnung</span>
                    <span class="be-list__col be-list__col--num">Soll</span>
                    <span class="be-list__col be-list__col--num">Haben</span>
                    <span class="be-list__col be-list__col--num">Saldo Soll</span>
                    <span class="be-list__col be-list__col--num">Saldo Haben</span>
                </div>
                <?php foreach ($report->rows as $row): ?>
                <div class="be-list__item" data-account="<?= e($row->number) ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell be-list__cell--mono"><a href="<?= e($link('account-statement', ['account' => $row->number])) ?>"><?= e($row->number) ?></a></span>
                        <span class="be-list__cell"><?= e($row->name) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($row->debit)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($row->credit)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $row->debitBalance()->isZero() ? '' : e($fmt($row->debitBalance())) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $row->creditBalance()->isZero() ? '' : e($fmt($row->creditBalance())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong>Total</strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalDebit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalCredit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalDebitBalance)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalCreditBalance)) ?></strong></span>
                    </div>
                </div>
            </div>
        </div>
        <p class="be-form__hint">
            <span class="badge <?= $ok ? 'badge--success' : 'badge--danger' ?>"><?= $ok ? 'Soll = Haben' : 'Soll ≠ Haben' ?></span>
            <?= $ok ? 'Total Soll und Total Haben stimmen überein, ebenso die Salden.' : 'Die Totale stimmen nicht überein — das Journal ist nicht ausgeglichen. Bitte melden.' ?>
        </p>
        <?php endif; ?>
    </div>
</div>
