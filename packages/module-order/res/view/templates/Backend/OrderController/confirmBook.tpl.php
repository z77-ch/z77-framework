<?php
/**
 * «Buchungsdruck» from the order (plan §7 / §9.3) — the last look: the orders selected in «In
 * Arbeit» / «Geliefert» (or the one order of the window), each with the version it SHOWED
 * (`order[]` = `{id}:{version}`), customer, subject and total, and the INVOICE DATE (default
 * today). One button posts the same pairs in THIS tab (`#[Csrf]`):
 * `OrderService::book()` issues and books each order's invoice, links it and sets the order to
 * the default status of phase `closed` («Gebucht») — one unit of work, all or nothing — and
 * answers with a redirect to debtor's RESULT LIST of the booked invoices
 * (`/backend/finance/invoice/booking-result?ids=…`, step 3 of the mail plan, owner 2026-10-22: per
 * invoice the PDF, «Alle als ein PDF», «Alle als ZIP», «Alle per E-Mail senden …»). Until that
 * day the answer was the combined PDF in a new tab (a blank target + `data-submit-navigate`) —
 * both gone. A repeated submit lands on the same result list; a refusal is a message page naming
 * the order. Orders already stale here (changed, booked, not in a bookable phase) are named and
 * left out. Each row starts with the eye icon «Rechnungsvorschau» (`invoice-preview`, a new tab,
 * owner 2026-10-22): the invoice checked once more before the booking makes it final.
 *
 * A PAGE, not a window (the DEBTOR-WIN-002 pattern: the selection bar is a GET form, core.js has
 * no opener that turns it into a window).
 * Action row first (ADR-049).
 *
 * @var list<\Z77\Module\Order\Entities\SalesOrder> $orders  bookable at the version shown
 * @var list<string> $stale  names of the ones left out
 * @var list<string> $zero   names of the ones totalling 0 — no invoice, left out (owner 2026-10-20)
 * @var \Z77\Shared\Money\Money|null $total
 * @var \DateTimeImmutable $today
 * @var \Z77\Module\Order\Entities\OrderStatus|null $booked  the status the booking print sets
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$count      = count($orders);
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase) ?>/book" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => $orders !== [] && $booked !== null ? 'Buchungsdruck' : '',
            'cancel'     => 'Zur Liste',
            'cancelHref' => $actionBase . '/list?view=delivered',
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Buchungsdruck <small class="be-list__cell--muted">· <?= $count ?> <?= $count === 1 ? 'Auftrag' : 'Aufträge' ?><?= $total !== null ? ' · ' . e($fmt($total)) : '' ?></small></h2>
        </div>
        <?php if ($stale !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">Seit der Auswahl geändert, bereits verbucht oder weder bestätigt noch geliefert — ausgelassen: <?= e(implode(', ', $stale)) ?>. Liste neu laden.</div>
        <?php endif; ?>
        <?php if (!empty($zero)): ?>
        <div class="be-modal__alert">Total 0 — keine Rechnung, ausgelassen: <?= e(implode(', ', $zero)) ?>. Diese Aufträge auf «Erledigt» setzen.</div>
        <?php endif; ?>
        <?php if ($booked === null): ?>
        <div class="be-modal__alert be-modal__alert--error">Die Phase «Abgeschlossen» hat keinen aktiven Auftragsstatus — unter Stammdaten › Aufträge › Auftragsstatus einen anlegen (z.B. «Gebucht») und «Wird gesetzt beim Buchungsdruck» einschalten.</div>
        <?php endif; ?>
        <?php if ($orders !== []): ?>
        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-book-date">Rechnungsdatum</label>
                <input type="date" id="order-book-date" name="invoice_date" value="<?= e($today->format('Y-m-d')) ?>" required>
            </div>
        </div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 2rem 8rem 6.5rem minmax(10rem, 2fr) minmax(8rem, 2fr) 8rem">
            <?php foreach ($orders as $order): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <a class="be-list__cell be-list__state" href="<?= e($actionBase . '/invoice-preview?id=' . $order->getId()) ?>" target="_blank" rel="noopener" aria-label="Rechnungsvorschau" title="Rechnungsvorschau — die Rechnung, wie der Buchungsdruck sie heute erstellen würde (ohne Nummer und Zahlteil)"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-eye"/></svg></a>
                    <span class="be-list__cell be-list__cell--mono">Nr. <?= e($order->documentNumber()) ?><input type="hidden" name="order[]" value="<?= e($order->getId() . ':' . $order->getVersion()) ?>"></span>
                    <span class="be-list__cell"><?= e($order->getOrderDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell"><?= e($order->getContact()->displayName()) ?></span>
                    <span class="be-list__cell"><?= e($order->getSubject()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $order->getTotalGross() !== null ? e($fmt($order->getTotalGross())) : '–' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Der Buchungsdruck erstellt zu jedem Auftrag die Rechnung, vergibt die Belegnummer, verbucht sie und zeigt sie danach als Liste (PDF einzeln, alle als ein PDF, als ZIP oder per E-Mail); die Aufträge stehen danach in «<?= e($booked?->getLabel() ?? 'Gebucht') ?>». Ein Kunde ohne Debitorenprofil wird dabei Debitor (Standard-Konditionen). Verbuchte Rechnungen sind unveränderlich — eine Korrektur ist eine Gutschrift.</p>
    </form>
</div>
