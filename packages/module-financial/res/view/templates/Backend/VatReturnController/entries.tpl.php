<?php
/**
 * One VAT return — «Buchungen einer Ziffer» (screen decision 9 of 2026-11-24): the coded journal
 * lines that make up one figure of the form, opened from «Herkunft». A STANDARD LIST (listing.md,
 * {@see \Z77\Module\Financial\Ui\VatReturnEntriesListing}): Datum · Nr. · Text searched in the
 * database, Konto · Umsatz · Steuer sorted, 50 per page, a fetch region.
 *
 *   - the origin and the account «Herkunft» narrowed by are the list's extras, shown as chips
 *     beside the title (the outbox's batch chip — badge + ×); the × drops one, the list then holds
 *     every line of the field from that origin / on that account;
 *   - a row: the state icon first (opens the entry in the journal as a window, ADR-047), the
 *     document behind the entry (invoice PDF, receipt photo, supplier PDF — `EntryDocuments`, none
 *     for a manual entry), then the data; every searchable cell a `<label for>` of its magnifier;
 *   - the total line under the last page: count, Σ Umsatz, Σ Steuer of every line the search
 *     selects — without a column search exactly the figure of the node in «Herkunft».
 *
 * @var \Z77\Module\Financial\VatReturn\VatReturnPreview $preview
 * @var \Z77\Module\Financial\VatReturn\VatReturnField $field
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnLine> $lines
 * @var array{lines: int, base: \Z77\Shared\Money\Money, tax: \Z77\Shared\Money\Money} $totals
 * @var array<int, \Z77\Module\Financial\Ledger\EntryDocument> $documents
 * @var string $originLabel
 * @var string $accountLabel
 * @var string $listAction
 * @var array<string, string> $keep
 * @var string $journalBase
 * @var callable $fmt
 */
use Z77\Module\Financial\Ui\ManualEntryForm;
use Z77\Module\Financial\VatReturn\RowOrigin;

$link     = static fn(array $changes = []): string => $listAction . '?' . ltrim(http_build_query($keep) . '&' . $state->query($changes), '&');
$inputFor = static fn(string $key): string => $definition->inputId($key);
$origins  = [RowOrigin::Ordinary->value => '', RowOrigin::Reduction->value => 'Minderung', RowOrigin::Reversal->value => 'Storno-Paar', RowOrigin::Correction->value => 'Korrektur'];
$sourceLabels = [];
foreach ($preview->breakdownOf($field->field)?->children ?? [] as $originNode) {
    $sourceLabels[$originNode->key] = $originNode->label;
}
?>
<div class="be-list" data-vat-return-view="entries" data-vat-entries-field="<?= e($field->field) ?>">
    <div class="be-list__section" data-fetch-region="<?= e($definition->region()) ?>">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listAction, 'keep' => $keep], 'Z77\\Shared') ?>
            <h2 class="be-list__section-title">Ziffer <?= e($field->field) ?> · <?= e($field->label) ?> <small>· <?= e(\Z77\Module\Financial\Ui\VatReturnLayout::periodSpan($preview->period)) ?></small></h2>
            <span class="be-list__section-badge" title="<?= $state->isActive() ? 'Gefundene Buchungszeilen' : 'Buchungszeilen mit MWST-Code' ?>"><?= $paging->total ?></span>
            <?php if ($originLabel !== ''): ?>
            <span class="badge badge--info" data-vat-chip="origin">Herkunft: <?= e($originLabel) ?>&nbsp;<a href="<?= e($link(['origin' => null])) ?>" aria-label="Filter «Herkunft: <?= e($originLabel) ?>» entfernen" title="Alle Herkünfte zeigen">×</a></span>
            <?php endif; ?>
            <?php if ($accountLabel !== ''): ?>
            <span class="badge badge--info" data-vat-chip="account">Konto: <?= e($accountLabel) ?>&nbsp;<a href="<?= e($link(['account' => null])) ?>" aria-label="Filter «Konto: <?= e($accountLabel) ?>» entfernen" title="Alle Konten zeigen">×</a></span>
            <?php endif; ?>
        </div>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listAction, 'keep' => $keep], 'Z77\\Shared') ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listAction, 'keep' => $keep], 'Z77\\Shared') ?>
                <?php foreach ($lines as $line): ?>
                <?php $detail = $journalBase . '/detail?id=' . $line->entryId; $document = $documents[$line->entryId] ?? null; ?>
                <div class="be-list__item" data-vat-line="<?= e((string) $line->lineId) ?>">
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>" aria-label="Buchung <?= e($line->entryRef()) ?> öffnen" title="Buchung <?= e($line->entryRef()) ?> öffnen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-eye"/></svg></a>
                        <?php if ($document !== null): ?>
                        <span class="be-list__cell"><a class="be-icon-btn" href="<?= e($document->url) ?>" target="_blank" rel="noopener" title="<?= e($document->label) ?> öffnen" aria-label="<?= e($document->label) ?> von Buchung <?= e($line->entryRef()) ?> öffnen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-file-text"/></svg></a></span>
                        <?php else: ?>
                        <span class="be-list__cell"></span>
                        <?php endif; ?>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($inputFor('f_date')) ?>"><?= e($line->date->format('d.m.Y')) ?></label>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($inputFor('f_nr')) ?>" data-priority="2"><?= e($line->entryRef()) ?></label>
                        <label class="be-list__cell" for="<?= e($inputFor('f_text')) ?>" title="<?= e($line->text . ($line->lineText !== null && $line->lineText !== '' ? ' · ' . $line->lineText : '')) ?>"><?= e($line->text) ?><?= $line->lineText !== null && $line->lineText !== '' ? ' <small class="be-list__cell--muted">· ' . e($line->lineText) . '</small>' : '' ?></label>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2" title="<?= e($line->accountName) ?>"><?= e($line->accountNumber) ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= e($line->taxCode) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="3"><?= e(ManualEntryForm::percent($line->rate)) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($line->base)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->tax)) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3"><?= e($sourceLabels[$line->sourceType ?? \Z77\Module\Financial\VatReturn\VatReturnSources::MANUAL] ?? (string) $line->sourceType) ?><?= ($origins[$line->origin->value] ?? '') !== '' ? ' · ' . e($origins[$line->origin->value]) : '' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($lines !== [] && $paging->page === $paging->pageCount): ?>
                <div class="be-list__item">
                    <div class="be-list__row be-list__row--total" data-vat-entries-total>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell" data-priority="2"></span>
                        <span class="be-list__cell"><?= $state->isActive() ? 'Total gefunden' : 'Total' ?> (<?= $totals['lines'] === 1 ? '1 Buchung' : $totals['lines'] . ' Buchungen' ?>)</span>
                        <span class="be-list__cell" data-priority="2"></span>
                        <span class="be-list__cell" data-priority="3"></span>
                        <span class="be-list__cell" data-priority="3"></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2" data-vat-total-base><?= e($fmt($totals['base'])) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-vat-total-tax><?= e($fmt($totals['tax'])) ?></span>
                        <span class="be-list__cell" data-priority="3"></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($lines === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Keine Buchung gefunden — die Suche gilt in der Periode.' : 'Keine Buchung mit MWST-Code für diese Auswahl.' ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Buchungen',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
