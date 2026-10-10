<?php
/**
 * The document list (P3 part 3) — one VIEW at a time (toolbar tabs):
 * invoices in `invoicing`, final invoices, credit notes. A STANDARD LIST
 * (listing.md, since 2026-10-08): the head and the search form are the
 * kernel's partials over {@see \Z77\Module\Debtor\Ui\InvoiceListing}, the
 * rows are this template's; no CSS and no JavaScript of its own:
 *
 *   - a state icon starts every row and is the way into the document: it
 *     opens the detail as a WINDOW (ADR-047, `data-window-open`), the href
 *     stays for a ctrl-click and a browser without the script;
 *   - every searchable cell is a `<label for>` of its column's search field;
 *   - in the invoicing view each row carries a checkbox of the form
 *     `#invoice-finalize` (GET to `confirm-finalize`) with the value
 *     `{id}:{version}` — the version THIS row shows, so a document re-issued
 *     before the batch runs is refused instead of finalized unseen;
 *   - «Definitiv stellen …» is bound to that selection, so it stands in the
 *     SELECTION BAR above the list (ADR-033 exception 2, rev. 2026-10-08),
 *     a green confirm (`.be-btn--confirm`) submitting `#invoice-finalize`
 *     through the `form` attribute. Without script the bar cannot count the
 *     ticked rows («n ausgewählt»), so it always shows in the invoicing view
 *     and names the count on the confirmation page instead;
 *   - the whole list is a FETCH REGION (`invoice-find-list`): sort / page /
 *     search reload only this part, and a save in a window opened from here
 *     (the detail and what it links, «+ Rechnung») reloads it
 *     (`refresh-region`, ADR-047 addendum 2026-10-10). The confirmation of
 *     «Definitiv stellen …» is still a page (debtor.md DEBTOR-WIN-002).
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var array<string, string> $views
 * @var array<string, string> $states  state → German title
 * @var callable $fmt
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$view       = $state->extra('view');
$selectable = $view === 'invoicing';
$icons      = ['invoicing' => 'edit', 'final' => 'lock'];
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($views[$view] ?? '') ?></h2>
            <?php if ($state->isActive()): ?>
            <nav class="be-list__toggles" aria-label="Suche">
                <a data-fetch-region-link href="<?= e($listUrl . '?' . $state->resetQuery()) ?>">Suche zurücksetzen</a>
            </nav>
            <?php endif; ?>
            <span class="be-list__section-badge" title="Dokumente"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
        <?php if ($selectable): ?>
        <form id="invoice-finalize" method="get" action="<?= e($actionBase) ?>/confirm-finalize"></form>
        <div class="z77-form-actions" data-selection-bar="invoice-finalize">
            <button type="submit" form="invoice-finalize" class="be-btn be-btn--confirm be-btn--sm">
                <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
                <span class="be-btn__label">Definitiv stellen …</span>
            </button>
            <small class="be-list__cell--muted">die angekreuzten Rechnungen — die Bestätigung nennt Anzahl und Total</small>
        </div>
        <?php endif; ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($documents as $document): ?>
                <?php $docState = $document->isFinal() ? 'final' : 'invoicing'; $url = $actionBase . '/detail?id=' . $document->getId(); ?>
                <div class="be-list__item" data-invoice-id="<?= e((string) $document->getId()) ?>">
                    <div class="be-list__row">
                        <?php if ($selectable): ?>
                        <span class="be-list__cell"><input type="checkbox" name="doc[]" form="invoice-finalize" value="<?= e($document->getId() . ':' . $document->getVersion()) ?>" aria-label="<?= e($document->documentName()) ?> auswählen"></span>
                        <?php endif; ?>
                        <a class="be-list__cell be-list__state be-list__state--<?= $docState === 'final' ? 'closed' : 'editable' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" aria-label="<?= e($states[$docState]) ?>" title="<?= e($states[$docState]) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= e($icons[$docState]) ?>"/></svg></a>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_nr')) ?>"><?= $document->getNumber() ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_date')) ?>" data-priority="3"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_name')) ?>"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?><?= $document->isCreditNote() ? ' <small class="be-list__cell--muted">· zu ' . e($document->getCreditNoteOf()->documentName()) . ($document->isFinal() ? '' : ' · in Fakturierung') . '</small>' : '' ?></label>
                        <span class="be-list__cell" data-priority="2"><?= $document->isCreditNote() ? '' : e($document->getDueDate()->format('d.m.Y')) ?></span>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= e($fmt($document->getGrossTotal())) ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($documents === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Dokument gefunden.' : 'Keine Dokumente in dieser Ansicht.' ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Dokumente',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
