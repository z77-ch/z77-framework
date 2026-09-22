<?php
/**
 * Journal report (plan §5.5): the entries of the range in date and number
 * order, each with its lines — the printable journal. One page of
 * `LedgerReports::JOURNAL_PAGE_SIZE` entries; count, Σ Soll and Σ Haben (each
 * its own SQL sum) cover the whole range. The number links to the entry's detail page. Deleted manual
 * entries are gaps in the numbering; the journal screen lists them.
 *
 * Styling: the shared backend list v2 classes only.
 *
 * @var \Z77\Module\Financial\Reports\JournalReport $report
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var callable $link
 * @var callable $fmt
 * @var string $journalBase
 */
$ns     = 'Z77\\Module\\Financial';
$paging = $report->paging;
$cols   = '--be-list-cols: 6rem 4rem 5rem minmax(10rem, 2fr) minmax(8rem, 1fr) 8rem 8rem';
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep], $ns) ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Journal <code><?= e($range->year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($range->from->format('d.m.Y')) ?> – <?= e($range->to->format('d.m.Y')) ?><?= $paging->pageCount > 1 ? ' · Seite ' . $paging->page . ' von ' . $paging->pageCount : '' ?></small>
            </h2>
            <span class="be-list__section-badge" title="Buchungen im Zeitraum"><?= $paging->total ?></span>
        </div>
        <?php if ($report->entries === []): ?>
        <p class="be-list__empty">Keine Buchung in diesem Zeitraum.</p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table" style="<?= $cols ?>">
                <div class="be-list__head">
                    <span class="be-list__col">Datum</span>
                    <span class="be-list__col be-list__col--num">Nr.</span>
                    <span class="be-list__col">Konto</span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col">MWST</span>
                    <span class="be-list__col be-list__col--num">Soll</span>
                    <span class="be-list__col be-list__col--num">Haben</span>
                </div>
                <?php foreach ($report->entries as $entry): ?>
                <div class="be-list__item" data-entry-id="<?= e((string) $entry->getId()) ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><strong><?= e($entry->getDate()->format('d.m.Y')) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num be-list__cell--mono"><a href="<?= e($journalBase) ?>/detail?id=<?= e((string) $entry->getId()) ?>"><?= $entry->getNumber() ?></a></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--wrap"><strong><?= e($entry->getText()) ?></strong><?= $entry->isReversal() ? ' <span class="badge badge--warning">Storno von ' . e($entry->getReversalOf()->getFiscalYear()->getCode() . '/' . $entry->getReversalOf()->getNumber()) . '</span>' : '' ?></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
                <?php foreach ($entry->getLines() as $line): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--mono"><?= e($line->getAccount()->getNumber()) ?></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($line->getAccount()->getName()) ?><?= $line->getText() !== null && $line->getText() !== '' ? ' <small class="be-list__cell--muted">· ' . e($line->getText()) . '</small>' : '' ?></span>
                        <span class="be-list__cell be-list__cell--muted"><?= $line->hasTax() ? e($line->getTaxCode() . ' ' . \Z77\Module\Financial\Ui\ManualEntryForm::percent((int) $line->getTaxRate()) . ' · ' . $fmt($line->getTaxAmount())) : '' ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->getDebit()->isZero() ? '' : e($fmt($line->getDebit())) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->getCredit()->isZero() ? '' : e($fmt($line->getCredit())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($paging->page === $paging->pageCount): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong>Total Zeitraum (<?= $paging->total ?> Buchungen)</strong></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalDebit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalCredit)) ?></strong></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?= $this->partial('Backend/ReportController/pager', [
            'paging'   => $paging,
            'unit'     => 'Buchungen',
            'pageLink' => fn(int $p) => $link('journal', ['page' => $p]),
        ], $ns) ?>
        <?php endif; ?>
    </div>
</div>
