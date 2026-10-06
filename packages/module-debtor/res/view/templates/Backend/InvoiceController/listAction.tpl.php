<?php
/**
 * The document list (P3 part 3) — one VIEW at a time (toolbar tabs):
 * invoices in `invoicing`, final invoices, credit notes. The journal list's
 * pattern (`financial.md`), no CSS and no JavaScript of its own:
 *
 *   - every searchable header cell is a `.be-list__find`: the title sorts (a
 *     link), the magnifier opens the search field (a `<label for>`); all
 *     fields belong to the GET form `#invoice-find`, Enter searches;
 *   - a state icon starts every row and is the way into the document: it
 *     opens the detail as a WINDOW (ADR-047, `data-window-open`), the href
 *     stays for a ctrl-click and a browser without the script;
 *   - in the invoicing view each row carries a checkbox of the form
 *     `#invoice-finalize` (GET to `confirm-finalize`) with the value
 *     `{id}:{version}` — the version THIS row shows, so a document re-issued
 *     before the batch runs is refused instead of finalized unseen;
 *   - the whole list is a FETCH REGION (`invoice-list`): sort / page / search
 *     reload only this part.
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents
 * @var \Z77\Module\Debtor\Ui\InvoiceFilter $filter
 * @var \Z77\Shared\Paging\Paging $paging
 * @var array<string, string> $views
 * @var array<string, string> $states  state → German title
 * @var callable $fmt
 * @var string $actionBase
 */
use Z77\Module\Debtor\Ui\InvoiceFilter;

$actionBase = $actionBase ?? '/backend/finance/invoice';
$link       = static fn(array $changes = []): string => $actionBase . '/list?' . $filter->query($changes);
$sortLink   = static function (string $sort) use ($filter, $link): string {
    $descending = $filter->sort === $sort ? !$filter->descending : $sort !== 'name';

    return $link(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
};
$selectable = $filter->view === 'invoicing';
$cols       = ($selectable ? '2rem ' : '') . '2rem 5rem 6.5rem minmax(10rem, 2fr) 6.5rem 8rem';
$colsXs     = ($selectable ? '2rem ' : '') . '2rem 4rem minmax(8rem, 2fr) 7rem';
$icons      = ['invoicing' => 'edit', 'final' => 'lock'];
?>
<div class="be-list" data-fetch-region="invoice-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($views[$filter->view] ?? '') ?></h2>
            <?php if ($filter->isActive()): ?>
            <nav class="be-list__toggles" aria-label="Suche">
                <a data-fetch-region-link href="<?= e($link(array_fill_keys(array_keys(InvoiceFilter::FIELDS), null))) ?>">Suche zurücksetzen</a>
            </nav>
            <?php endif; ?>
            <span class="be-list__section-badge" title="Dokumente"><?= $paging->total ?></span>
        </div>

        <form id="invoice-find" method="get" action="<?= e($actionBase) ?>/list" role="search" data-fetch-region-form>
            <?php foreach ($filter->hiddenState() as $name => $value): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endforeach; ?>
            <button type="submit" class="be-list__find-submit" tabindex="-1">Suchen</button>
        </form>
        <?php if ($selectable): ?>
        <form id="invoice-finalize" method="get" action="<?= e($actionBase) ?>/confirm-finalize"></form>
        <?php endif; ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: <?= $cols ?>; --be-list-cols-sm: <?= $cols ?>; --be-list-cols-xs: <?= $colsXs ?>">
                <div class="be-list__head">
                    <?php if ($selectable): ?><span class="be-list__col" aria-label="Auswahl"></span><?php endif; ?>
                    <span class="be-list__col" aria-label="Status"></span>
                    <?php foreach (InvoiceFilter::FIELDS as $key => $label): ?>
                    <?php
                    $sort    = InvoiceFilter::SORT_OF[$key];
                    $numeric = in_array($key, ['f_nr', 'f_amount'], true);
                    $class   = 'be-list__col be-list__find' . ($numeric ? ' be-list__col--num' : '') . ($filter->value($key) !== '' ? ' be-list__find--active' : '');
                    ?>
                    <span class="<?= $class ?>"<?= $key === 'f_date' ? ' data-priority="3"' : '' ?>>
                        <a class="be-list__sort" data-fetch-region-link href="<?= e($sortLink($sort)) ?>"<?= $filter->sort === $sort ? ' data-sort="' . ($filter->descending ? 'desc' : 'asc') . '"' : ' data-sort' ?>><?= e($label) ?></a>
                        <label class="be-list__find-icon" for="invoice-find-<?= e($key) ?>" title="<?= e($label) ?> suchen">
                            <svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-search"/></svg>
                        </label>
                        <input class="be-list__find-input" type="search" id="invoice-find-<?= e($key) ?>" name="<?= e($key) ?>" form="invoice-find"
                               value="<?= e($filter->value($key)) ?>" placeholder=" " autocomplete="off" aria-label="<?= e($label) ?> suchen"
                               aria-invalid="<?= $filter->isInvalid($key) ? 'true' : 'false' ?>">
                    </span>
                    <?php if ($key === 'f_name'): ?><span class="be-list__col" data-priority="2">Fällig</span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($documents as $document): ?>
                <?php $state = $document->isFinal() ? 'final' : 'invoicing'; $url = $actionBase . '/detail?id=' . $document->getId(); ?>
                <div class="be-list__item" data-invoice-id="<?= e((string) $document->getId()) ?>">
                    <div class="be-list__row">
                        <?php if ($selectable): ?>
                        <span class="be-list__cell"><input type="checkbox" name="doc[]" form="invoice-finalize" value="<?= e($document->getId() . ':' . $document->getVersion()) ?>" aria-label="<?= e($document->documentName()) ?> auswählen"></span>
                        <?php endif; ?>
                        <a class="be-list__cell be-list__state be-list__state--<?= $state === 'final' ? 'closed' : 'editable' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" aria-label="<?= e($states[$state]) ?>" title="<?= e($states[$state]) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= e($icons[$state]) ?>"/></svg></a>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="invoice-find-f_nr"><?= $document->getNumber() ?></label>
                        <label class="be-list__cell" for="invoice-find-f_date" data-priority="3"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="invoice-find-f_name"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?><?= $document->isCreditNote() ? ' <small class="be-list__cell--muted">· zu ' . e($document->getCreditNoteOf()->documentName()) . ($document->isFinal() ? '' : ' · in Fakturierung') . '</small>' : '' ?></label>
                        <span class="be-list__cell" data-priority="2"><?= $document->isCreditNote() ? '' : e($document->getDueDate()->format('d.m.Y')) ?></span>
                        <label class="be-list__cell be-list__cell--num" for="invoice-find-f_amount"><?= e($fmt($document->getGrossTotal())) ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($documents === []): ?>
        <p class="be-list__empty"><?= $filter->isActive() ? 'Kein Dokument gefunden.' : 'Keine Dokumente in dieser Ansicht.' ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Dokumente',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
