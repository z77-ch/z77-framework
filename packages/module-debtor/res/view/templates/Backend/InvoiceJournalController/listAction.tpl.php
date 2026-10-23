<?php
/**
 * The Faktura-Journal (ADR-054, step 3 of the build plan) — one tab at a time (toolbar):
 *
 *   - «Im Journal»: the pending step at the top (backend-screen.md §4 — «a pending step must be
 *     visible»): count, total and the oldest date, or — red — the blockers («Neu fakturieren
 *     nötig», with each document's mask); then a STANDARD LIST (listing.md) of the issued
 *     documents — the state icon opens the document's mask as a window (ADR-047), every
 *     searchable cell a `<label for>` of its magnifier; under the list the totals per VAT code
 *     and in all (credit notes negative — as the posting books them);
 *   - «Verbuchte Journale»: the posting protocols, newest first, each with its journal list (PDF)
 *     as a helper icon in the row.
 *
 * The whole list is a FETCH REGION (sort / page / search reload only it, a save in a window
 * opened from here reloads it). No CSS and no JavaScript of its own.
 *
 * @var string $view
 * @var array<string, mixed> $totals
 * @var list<array{id: int, name: string, reason: string}> $blockers
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents
 * @var list<\Z77\Module\Debtor\Entities\InvoiceJournalPosting> $protocols
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var string $currency
 * @var callable $fmt
 * @var string $actionBase
 * @var string $invoiceBase
 */
use Z77\Module\Debtor\Ui\InvoiceJournalListing;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Shared\Money\Money;

$actionBase  = $actionBase ?? '/backend/finance/invoice-journal';
$invoiceBase = $invoiceBase ?? '/backend/finance/invoice';
$listUrl     = $actionBase . '/list';
$link        = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$money       = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);
$posted      = $view === InvoiceJournalListing::VIEW_POSTED;
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>" data-invoice-journal>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
            <h2 class="be-list__section-title"><?= $posted ? 'Verbuchte Journale' : 'Im Faktura-Journal' ?></h2>
            <span class="be-list__section-badge" title="<?= $posted ? 'Verbuchte Journale' : 'Dokumente' ?>"><?= $paging->total ?></span>
        </div>

        <?php if (!$posted): ?>
        <?php if ($blockers !== []): ?>
        <div class="be-modal__alert be-modal__alert--error" role="status" data-journal-blocked="<?= count($blockers) ?>">
            <strong>Verbuchen gesperrt — <?= count($blockers) === 1 ? '1 Dokument muss' : count($blockers) . ' Dokumente müssen' ?> neu fakturiert werden:</strong>
            <?php foreach ($blockers as $i => $blocker): ?><?= $i > 0 ? ' · ' : ' ' ?><a href="<?= e($invoiceBase . '/edit?id=' . $blocker['id']) ?>" data-window-open="<?= e($invoiceBase . '/edit?id=' . $blocker['id']) ?>"><?= e($blocker['name']) ?></a> <small>(<?= e($blocker['reason']) ?>)</small><?php endforeach; ?>
        </div>
        <?php elseif ($totals['count'] > 0): ?>
        <div class="be-list__bar" role="status" data-journal-pending="<?= (int) $totals['count'] ?>">
            <span><strong><?= (int) $totals['count'] ?> <?= $totals['count'] === 1 ? 'Dokument' : 'Dokumente' ?> im Journal · noch nicht verbucht</strong> · Total <?= e($fmt($money($totals['gross']))) ?><?= $totals['oldest'] !== null ? ' · ältestes vom ' . e((new \DateTimeImmutable($totals['oldest']))->format('d.m.Y')) : '' ?></span>
            <small class="be-list__cell--muted">«Journal verbuchen …» übernimmt alle in die Buchhaltung</small>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (!$posted): ?>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
        <?php endif; ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php if (!$posted): ?>
                <?php foreach ($documents as $document): ?>
                <?php
                $id    = (int) $document->getId();
                $url   = $invoiceBase . '/edit?id=' . $id;
                $sign  = $document->kind()->sign();
                $title = 'fakturiert — im Faktura-Journal, noch nicht verbucht';
                ?>
                <div class="be-list__item" data-invoice-id="<?= $id ?>">
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state be-list__state--closed" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" aria-label="<?= e($document->documentName() . ' — ' . $title) ?>" title="<?= e($title) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-lock"/></svg></a>
                        <label class="be-list__cell be-list__cell--num be-list__cell--mono" for="<?= e($definition->inputId('f_nr')) ?>"><?= (int) $document->getNumber() ?><?= $document->isCreditNote() ? ' <small class="be-list__cell--muted">GS</small>' : '' ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_date')) ?>"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_name')) ?>"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?></label>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3"><?= e($document->subjectLine() ?? '') ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($document->getNetTotal()->multiply($sign))) ?></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2"><?= e($fmt($document->getTaxTotal()->multiply($sign))) ?></span>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= e($fmt($document->getGrossTotal()->multiply($sign))) ?></label>
                        <span class="be-list__cell be-list__cell--mono be-list__cell--muted" data-priority="3"><?= e((string) $document->getDocumentStamp()) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3"><?= $document->getIssuedAt() !== null ? e($document->getIssuedAt()->format('d.m.Y H:i')) . '<br><small>' . e((string) $document->getIssuedBy()) . '</small>' : '' ?></span>
                        <span class="be-list__cell be-list__cell--wrap"><?php if ($document->getReissueNeeded() !== null): ?><span class="badge badge--danger" title="<?= e($document->getReissueNeeded()) ?>">Neu fakturieren nötig</span> <small><?= e($document->getReissueNeeded()) ?></small><?php endif; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <?php foreach ($protocols as $protocol): ?>
                <?php $pdf = $actionBase . '/pdf?posting=' . (int) $protocol->getId(); ?>
                <div class="be-list__item" data-journal-posting="<?= (int) $protocol->getId() ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?= e($protocol->getPostedAt()->format('d.m.Y H:i')) ?></span>
                        <span class="be-list__cell"><?= e($protocol->getPostedBy()) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= (int) $protocol->getDocumentCount() ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= (int) $protocol->getFirstNumber() ?> – <?= (int) $protocol->getLastNumber() ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($protocol->getGrossTotal())) ?></span>
                        <span class="be-list__cell be-tree__tools"><a class="be-icon-btn" href="<?= e($pdf) ?>" target="_blank" rel="noopener" title="Journalliste (PDF)" aria-label="Journalliste (PDF) vom <?= e($protocol->getPostedAt()->format('d.m.Y H:i')) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-print"/></svg></a></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$posted && $documents === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Dokument gefunden.' : 'Das Faktura-Journal ist leer — fakturierte Rechnungen erscheinen hier, bis das Journal verbucht ist.' ?></p>
        <?php elseif ($posted && $protocols === []): ?>
        <p class="be-list__empty">Noch kein Journal verbucht.</p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => $posted ? 'Journale' : 'Dokumente',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>

    <?php if (!$posted && $totals['count'] > 0): ?>
    <div class="be-list__section" data-journal-totals>
        <div class="be-list__section-header">
            <h3 class="be-list__section-title">Total des Journals <small class="be-list__cell--muted">· <?= (int) $totals['count'] ?> <?= $totals['count'] === 1 ? 'Dokument' : 'Dokumente' ?>, Gutschriften negativ</small></h3>
        </div>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(10rem, 2fr) 6rem 9rem 9rem">
            <div class="be-list__head">
                <span class="be-list__col">MWST-Code</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Satz</span>
                <span class="be-list__col be-list__col--num">Basis</span>
                <span class="be-list__col be-list__col--num">MWST</span>
            </div>
            <?php foreach ($totals['vat'] as $vat): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e($vat['code']) ?></span>
                <span class="be-list__cell"><?= e($vat['label']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(TaxRate::formatPercent($vat['rate'])) ?> %</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($money($vat['base']))) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($money($vat['tax']))) ?></span>
            </div></div>
            <?php endforeach; ?>
            <div class="be-list__item" data-journal-grand-total><div class="be-list__row">
                <strong class="be-list__cell">Total</strong>
                <span class="be-list__cell be-list__cell--muted">Netto <?= e($fmt($money($totals['net']))) ?></span>
                <span class="be-list__cell"></span>
                <span class="be-list__cell be-list__cell--num">MWST <?= e($fmt($money($totals['tax']))) ?></span>
                <strong class="be-list__cell be-list__cell--num"><?= e($fmt($money($totals['gross']))) ?></strong>
            </div></div>
        </div>
    </div>
    <?php endif; ?>
</div>
