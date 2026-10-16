<?php
/**
 * Journal report (plan §5.5): the entries of the range, each with its lines — the journal on
 * the screen (paper is the PDF: what the list shows — search and sort —, not paged; owner
 * 2026-10-16). «Zurücksetzen» before the title drops the search and the sort. A STANDARD LIST since 2026-10-15
 * (`Ui\JournalReportListing`, listing.md): the column titles sort, the magnifiers search in the
 * database within the range, the list is a fetch region (sort, page and search reload only it;
 * the range form above keeps what is typed). One page of `LedgerReports::JOURNAL_PAGE_SIZE`
 * entries; count, Σ Soll and Σ Haben (each its own SQL sum) cover every entry the search finds
 * in the range. The number links to the entry's detail page. Deleted manual entries are gaps in
 * the numbering; the change log screen (Finanzen › Änderungsprotokoll) lists them.
 *
 * Styling: the shared backend list v2 classes only.
 *
 * @var \Z77\Module\Financial\Reports\JournalReport $report
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var callable $link
 * @var callable $fmt
 * @var string $journalBase
 * @var string $reportBase
 */
$ns       = 'Z77\\Module\\Financial';
$paging   = $report->paging;
$action   = $reportBase . '/journal';
$rangeQuery = ['year' => $range->year->getCode(), 'from' => $range->fromDay(), 'to' => $range->toDay()];
$listLink = static fn(string $query): string => $action . '?' . ltrim(http_build_query($rangeQuery) . '&' . $query, '&');
$inputFor = static fn(string $key): string => $definition->inputId($key);
$pdfForm  = $definition->id . '-pdf';
parse_str($state->query([]), $listQuery);
$pdfQuery = $rangeQuery + $listQuery;
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep, 'pdfTabs' => $pdfTabs ?? [], 'compareTabs' => $compareTabs ?? [], 'compare' => $compare ?? true, 'pdfForm' => $pdfForm], $ns) ?>
    <div class="be-list__section" data-fetch-region="<?= e($definition->region()) ?>">
        <?php // The PDF prints what the list shows (owner 2026-10-16): this form carries the range and the list state; the «PDF» button above submits it (`form=`). Inside the region, so a sort or a search renews it. ?>
        <form id="<?= e($pdfForm) ?>" method="get" action="<?= e($reportBase . '/pdf') ?>" target="_blank" hidden>
            <input type="hidden" name="report" value="journal">
            <?php foreach ($pdfQuery as $name => $value): ?>
            <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
        </form>
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $action, 'keep' => $rangeQuery], 'Z77\Shared') ?>
            <h2 class="be-list__section-title">
                Journal <code><?= e($range->year->getCode()) ?></code>
                <small class="be-list__cell--muted">· <?= e($range->from->format('d.m.Y')) ?> – <?= e($range->to->format('d.m.Y')) ?><?= $paging->pageCount > 1 ? ' · Seite ' . $paging->page . ' von ' . $paging->pageCount : '' ?></small>
            </h2>
            <span class="be-list__section-badge" title="<?= $state->isActive() ? 'Gefundene Buchungen im Zeitraum' : 'Buchungen im Zeitraum' ?>"><?= $paging->total ?></span>
        </div>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $action, 'keep' => $rangeQuery], 'Z77\Shared') ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $action, 'keep' => $rangeQuery], 'Z77\Shared') ?>
                <?php if ($report->entries === []): ?>
                <p class="be-list__empty"><?= $state->isActive() ? 'Keine Buchung gefunden — die Suche gilt im gewählten Zeitraum.' : 'Keine Buchung in diesem Zeitraum.' ?></p>
                <?php endif; ?>
                <?php foreach ($report->entries as $entry): ?>
                <div class="be-list__item" data-entry-id="<?= e((string) $entry->getId()) ?>">
                    <div class="be-list__row">
                        <label class="be-list__cell" for="<?= e($inputFor('f_date')) ?>"><strong><?= e($entry->getDate()->format('d.m.Y')) ?></strong></label>
                        <span class="be-list__cell be-list__cell--num be-list__cell--mono"><a href="<?= e($journalBase) ?>/detail?id=<?= e((string) $entry->getId()) ?>"><?= $entry->getNumber() ?></a></span>
                        <span class="be-list__cell"></span>
                        <label class="be-list__cell be-list__cell--wrap" for="<?= e($inputFor('f_text')) ?>"><strong><?= e($entry->getText()) ?></strong><?= $entry->isReversal() ? ' <span class="badge badge--warning">Storno von ' . e($entry->getReversalOf()->getFiscalYear()->getCode() . '/' . $entry->getReversalOf()->getNumber()) . '</span>' : '' ?></label>
                        <span class="be-list__cell" data-priority="2"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
                <?php foreach ($entry->getLines() as $line): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($inputFor('f_account')) ?>"><?= e($line->getAccount()->getNumber()) ?></label>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($line->getAccount()->getName()) ?><?= $line->getText() !== null && $line->getText() !== '' ? ' <small class="be-list__cell--muted">· ' . e($line->getText()) . '</small>' : '' ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="2"><?= $line->hasTax() ? e($line->getTaxCode() . ' ' . \Z77\Module\Financial\Ui\ManualEntryForm::percent((int) $line->getTaxRate()) . ' · ' . $fmt($line->getTaxAmount())) : '' ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->getDebit()->isZero() ? '' : e($fmt($line->getDebit())) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->getCredit()->isZero() ? '' : e($fmt($line->getCredit())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($report->entries !== [] && $paging->page === $paging->pageCount): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong><?= $state->isActive() ? 'Total gefunden' : 'Total Zeitraum' ?> (<?= $paging->total ?> Buchungen)</strong></span>
                        <span class="be-list__cell" data-priority="2"></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalDebit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalCredit)) ?></strong></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?= $this->partial('partials/pager', [
            'paging'   => $paging,
            'unit'     => 'Buchungen',
            'pageLink' => fn(int $p) => $listLink($state->query(['page' => $p])),
        ], 'Z77\Shared') ?>
    </div>
</div>
