<?php
/**
 * The booking print's RESULT LIST (step 3 of the mail plan, owner 2026-10-22) — where the
 * booking print lands, of drafts (this module) or of orders (module-order): exactly the
 * documents just booked, in number order. Before that day the booking print opened the PDFs
 * in a new tab; now the user chooses: one PDF per row, «Alle als ein PDF» (printing), «Alle als
 * ZIP», «Alle per E-Mail senden …».
 *
 * A PAGE whose run is its URL (`?ids=`): reload, bookmark and the way back from the
 * confirmation all show the same list; closed, the list «Verbucht» is the way back. Rows: the
 * document (kind + number), date, customer (the snapshot's), total, the billing e-mail ON THE
 * DOCUMENT (`addr_email`) or a muted «keine E-Mail-Adresse», what the outbox holds for it, and
 * the PDF as a row icon (backend-screen.md: a helper action in a row is an icon). Everything is
 * a plain link — without JavaScript the page works as it is; the mail itself is a form post on
 * the confirmation page (`#[Csrf]`).
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents  final, in number order
 * @var string $ids         the run, comma-separated ids
 * @var string $back        a local backend path the caller came from, or ''
 * @var bool   $mailable    module-mailing installed
 * @var array<int, list<array<string, mixed>>> $deliveries  id => outbox rows of `invoice:<id>`
 * @var \Z77\Shared\Money\Money|null $total  invoices minus credit notes
 * @var callable $fmt
 * @var string $actionBase
 */
use Z77\Module\Debtor\Mail\InvoiceMails;

$actionBase = $actionBase ?? '/backend/finance/invoice';
$query      = http_build_query(['ids' => $ids] + ($back !== '' ? ['back' => $back] : []));
$count      = count($documents);
$withMail   = count(array_filter($documents, static fn($d) => InvoiceMails::skipReason($d) === null));
$icon       = static fn(string $name): string => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-list">
    <div class="be-list__section" data-booking-result>
        <div class="z77-form-actions">
            <?php if ($mailable): ?>
            <a class="be-btn be-btn--primary" href="<?= e($actionBase . '/confirm-mail?' . $query) ?>"><?= raw($icon('icon-mail')) ?> Alle per E-Mail senden …</a>
            <?php endif; ?>
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/pdf-all?ids=' . rawurlencode($ids)) ?>" target="_blank" rel="noopener"><?= raw($icon('icon-print')) ?> Alle als ein PDF</a>
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/zip?ids=' . rawurlencode($ids)) ?>"><?= raw($icon('icon-download')) ?> Alle als ZIP</a>
            <?php if ($deliveries !== []): ?>
            <a class="be-btn be-btn--ghost" href="/backend/service/outbox/list?view=all">Postausgang</a>
            <?php endif; ?>
            <span class="z77-form-actions__spacer"></span>
            <span class="z77-form-actions__sep" aria-hidden="true"></span>
            <?php if ($back !== ''): ?>
            <a class="be-btn be-btn--quiet" href="<?= e($back) ?>">Zurück</a>
            <?php endif; ?>
            <a class="be-btn be-btn--quiet" href="<?= e($actionBase . '/list?view=final') ?>">Zur Liste «Verbucht»</a>
        </div>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Buchungsdruck <small class="be-list__cell--muted">· <?= $count ?> <?= $count === 1 ? 'Dokument' : 'Dokumente' ?> verbucht<?= $total !== null ? ' · ' . e($fmt($total)) : '' ?></small></h2>
        </div>
        <div class="be-list__table" style="--be-list-cols: 9.5rem 6.5rem minmax(9rem, 2fr) 7rem minmax(9rem, 1.6fr) minmax(9rem, 1.6fr) 2rem">
            <div class="be-list__head">
                <span class="be-list__col">Dokument</span>
                <span class="be-list__col">Datum</span>
                <span class="be-list__col">Kunde</span>
                <span class="be-list__col be-list__col--num">Betrag</span>
                <span class="be-list__col">E-Mail</span>
                <span class="be-list__col">Versand</span>
                <span class="be-list__col"></span>
            </div>
            <?php foreach ($documents as $document): ?>
            <?php $id = (int) $document->getId(); $email = trim($document->getAddress()->getEmail()); $sent = $deliveries[$id] ?? []; ?>
            <div class="be-list__item" data-invoice-id="<?= $id ?>">
                <div class="be-list__row">
                    <span class="be-list__cell"><a href="<?= e($actionBase . '/edit?id=' . $id) ?>" data-window-open="<?= e($actionBase . '/edit?id=' . $id) ?>"><?= e($document->documentName()) ?></a></span>
                    <span class="be-list__cell"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e(($document->isCreditNote() ? '−' : '') . $fmt($document->getGrossTotal())) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?php if ($email !== ''): ?><?= e($email) ?><?php if (InvoiceMails::recipientOf($document) === ''): ?> <small class="be-list__cell--muted">(ungültig)</small><?php endif; ?><?php else: ?><span class="be-list__cell--muted">keine E-Mail-Adresse</span><?php endif; ?></span>
                    <span class="be-list__cell be-list__cell--wrap be-list__cell--muted"><?= $sent !== [] ? e(InvoiceMails::deliveryLine($sent[count($sent) - 1])) : '' ?></span>
                    <span class="be-list__cell"><a class="be-icon-btn" href="<?= e($actionBase . '/pdf?id=' . $id) ?>" target="_blank" rel="noopener" title="PDF öffnen" aria-label="PDF <?= e($document->documentName()) ?>"><?= raw($icon('icon-file-text')) ?></a></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">
            Die Dokumente sind verbucht und unveränderlich. <?= $mailable ? ($withMail === $count ? 'Alle haben eine E-Mail-Adresse.' : $withMail . ' von ' . $count . ' haben eine E-Mail-Adresse — die anderen werden beim Versand übersprungen.') : 'Für den Versand per E-Mail fehlt das Modul «Mailing» (Postausgang).' ?>
            Diese Liste bleibt über ihre Adresse erreichbar; die PDFs lassen sich auch später in der Liste «Verbucht» öffnen.
        </p>
    </div>
</div>
