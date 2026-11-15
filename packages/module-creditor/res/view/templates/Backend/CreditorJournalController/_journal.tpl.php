<?php
/**
 * The list region of the «Kreditorenjournal» — one tab at a time (toolbar):
 *
 *   - «Im Journal» / «Gesperrt»: the pending step at the top (backend-screen.md §4) — count and
 *     total, red while invoices are blocked as suspected duplicates; then a STANDARD LIST
 *     (listing.md): the state icon opens the invoice WINDOW (ADR-047), the PDF as a thumbnail,
 *     every searchable cell a `<label for>` of its magnifier. Konto / MWST show the first line, or
 *     «n Zeilen»; the state says «vollständig», what is missing, or «Doppel?»;
 *   - «Verbuchte Journale»: the posting protocols, newest first (written from stage 1c).
 *
 * A FETCH REGION (sort / page / search reload only it; a save in a window opened from here
 * reloads it; the upload answer replaces it — `[data-creditor-journal]`). No JavaScript of its own.
 *
 * @var string $view
 * @var array{open: int, gross: \Z77\Shared\Money\Money, blocked: int} $totals
 * @var list<\Z77\Module\Creditor\Entities\CreditorInvoice> $invoices
 * @var array<int, list<\Z77\Module\Creditor\Entities\CreditorInvoiceLine>> $lines  by invoice id
 * @var array<int, array<string, mixed>> $pdfs  by document id (ReceiptPhoto)
 * @var list<\Z77\Module\Creditor\Entities\CreditorJournalPosting> $protocols
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var callable $fmt
 * @var string $actionBase
 */
use Z77\Module\Creditor\Ui\CreditorJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$posted     = $view === CreditorJournalListing::VIEW_POSTED;
$blockedTab = $view === CreditorJournalListing::VIEW_BLOCKED;
$region     = $definition->region();
$open       = (int) $totals['open'];
$blocked    = (int) $totals['blocked'];
$titles     = [CreditorJournalListing::VIEW_OPEN => 'Im Kreditorenjournal', CreditorJournalListing::VIEW_BLOCKED => 'Gesperrt — mögliche Doppel', CreditorJournalListing::VIEW_POSTED => 'Verbuchte Journale'];
?>
<div class="be-list__section" data-fetch-region="<?= e($region) ?>" data-creditor-journal>
    <div class="be-list__section-header">
        <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
        <h2 class="be-list__section-title"><?= e($titles[$view] ?? $titles[CreditorJournalListing::VIEW_OPEN]) ?></h2>
        <span class="be-list__section-badge" title="<?= $posted ? 'Verbuchte Journale' : 'Rechnungen' ?>"><?= $paging->total ?></span>
    </div>

    <?php if (!$posted): ?>
    <?php if ($blocked > 0 && !$blockedTab): ?>
    <div class="be-modal__alert be-modal__alert--error" role="status" data-journal-blocked="<?= $blocked ?>">
        <strong><?= $blocked === 1 ? '1 Rechnung ist' : $blocked . ' Rechnungen sind' ?> gesperrt — mögliches Doppel einer anderen Rechnung.</strong>
        <a href="<?= e($listUrl . '?view=' . CreditorJournalListing::VIEW_BLOCKED) ?>">Im Tab «Gesperrt» entscheiden</a>: kein Doppel — übernehmen, oder verwerfen.
    </div>
    <?php endif; ?>
    <?php if ($open + $blocked > 0 && !$blockedTab): ?>
    <div class="be-list__bar" role="status" data-journal-pending="<?= $open ?>">
        <span><strong><?= $open ?> <?= $open === 1 ? 'Rechnung' : 'Rechnungen' ?> im Journal · noch nicht verbucht</strong> · Total <?= e($fmt($totals['gross'])) ?></span>
        <small class="be-list__cell--muted">prüfen und ergänzen — das Verbuchen mit dem Zahlungsdatum folgt</small>
    </div>
    <?php endif; ?>
    <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
    <?php endif; ?>

    <div class="be-list__frame">
        <div class="be-list__table" style="<?= e($definition->style()) ?>">
            <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
            <?php if (!$posted): ?>
            <?php foreach ($invoices as $invoice): ?>
            <?php
            $id        = (int) $invoice->getId();
            $url       = $actionBase . '/edit?id=' . $id;
            $own       = $lines[$id] ?? [];
            $missing   = $invoice->missing($own);
            $complete  = $missing === [] && !$invoice->isBlocked();
            $title     = $invoice->isBlocked() ? 'Rechnung öffnen — gesperrt: ' . $invoice->getDuplicateReason()
                : ($complete ? 'Rechnung öffnen — vollständig' : 'Rechnung öffnen — es fehlt: ' . implode(', ', $missing));
            $pdf       = $pdfs[$invoice->getDocumentId()] ?? null;
            $creditor  = $invoice->getCreditor()?->getContact()?->displayName();
            $proposal  = $invoice->proposal()['name'] ?? '';
            $first     = $own[0] ?? null;
            $many      = count($own) > 1;
            ?>
            <div class="be-list__item" data-creditor-invoice="<?= $id ?>">
                <div class="be-list__row">
                    <a class="be-list__cell be-list__state be-list__state--<?= $complete ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($title) ?>" title="<?= e($title) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= $complete ? 'check' : 'edit' ?>"/></svg></a>
                    <span class="be-list__cell"><?php if ($pdf !== null && $pdf['thumbUrl'] !== null): ?><img class="be-list__thumb" src="<?= e($pdf['thumbUrl']) ?>" alt="" loading="lazy"><?php else: ?><span class="be-list__thumb" aria-hidden="true"></span><?php endif; ?></span>
                    <label class="be-list__cell" for="<?= e($definition->inputId('f_creditor')) ?>"><?php if ($creditor !== null): ?><?= e($creditor) ?><?php elseif ($proposal !== ''): ?><span class="be-list__cell--muted">Vorschlag: <?= e($proposal) ?></span><?php else: ?><span class="be-list__cell--muted">Kreditor wählen</span><?php endif; ?></label>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_number')) ?>"><?= e($invoice->getInvoiceNumber() ?? '—') ?></label>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_date')) ?>"><?= $invoice->getInvoiceDate() !== null ? e($invoice->getInvoiceDate()->format('d.m.Y')) : '<span class="be-list__cell--muted">—</span>' ?></label>
                    <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= $invoice->getDueDate() !== null ? e($invoice->getDueDate()->format('d.m.Y')) : '—' ?></span>
                    <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= $invoice->getAmount() !== null ? e($fmt($invoice->getAmount())) : '<span class="be-list__cell--muted">—</span>' ?></label>
                    <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= $many ? e(count($own) . ' Zeilen') : e($first?->getAccount() ?? '—') ?></span>
                    <span class="be-list__cell be-list__cell--mono" data-priority="3"><?php if ($many): ?>…<?php elseif ($first?->isWithoutVat()): ?><span class="be-list__cell--muted">ohne</span><?php else: ?><?= e($first?->getTaxCode() ?? '—') ?><?php endif; ?></span>
                    <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= $invoice->getPaidOn() !== null ? e($invoice->getPaidOn()->format('d.m.Y')) : '<span class="be-list__cell--muted">unbezahlt</span>' ?></span>
                    <span class="be-list__cell"><?php if ($invoice->isBlocked()): ?><span class="badge badge--danger" title="<?= e($invoice->getDuplicateReason()) ?>">Doppel?</span><?php elseif ($complete): ?><span class="badge badge--success">vollständig</span><?php else: ?><span class="badge badge--warning" title="<?= e('es fehlt: ' . implode(', ', $missing)) ?>">zu prüfen</span><?php endif; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($protocols as $protocol): ?>
            <div class="be-list__item" data-journal-posting="<?= (int) $protocol->getId() ?>">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($protocol->getPostedAt()->format('d.m.Y H:i')) ?></span>
                    <span class="be-list__cell"><?= e($protocol->getPostedBy()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= (int) $protocol->getInvoiceCount() ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($protocol->getGrossTotal())) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$posted && $invoices === []): ?>
    <p class="be-list__empty"><?= $state->isActive() ? 'Keine Rechnung gefunden.' : ($blockedTab ? 'Keine gesperrte Rechnung — kein mögliches Doppel wartet auf deinen Entscheid.' : 'Das Kreditorenjournal ist leer — eine hochgeladene PDF-Rechnung erscheint hier.') ?></p>
    <?php elseif ($posted && $protocols === []): ?>
    <p class="be-list__empty">Noch kein Kreditorenjournal verbucht — das Verbuchen mit dem Zahlungsdatum folgt.</p>
    <?php endif; ?>
    <?= $this->partial('partials/pager', [
        'paging'      => $paging,
        'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
        'unit'        => $posted ? 'Journale' : 'Rechnungen',
        'regionLinks' => true,
    ], 'Z77\Shared') ?>
</div>
