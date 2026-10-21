<?php
/**
 * «Buchungsdruck» from the order (plan §7 / §9.3) — the last look: the orders selected in «In
 * Arbeit» / «Geliefert» (or the one order of the window), each with the version it SHOWED
 * (`order[]` = `{id}:{version}`), customer, subject and total, and the INVOICE DATE (default
 * today). One button posts the same pairs into a NEW TAB (`target="_blank"`, `#[Csrf]`):
 * `OrderService::book()` issues and books each order's invoice, links it and sets the order to
 * the default status of phase `closed` («Gebucht») — one unit of work, all or nothing — and the tab shows the
 * invoices as ONE PDF. A repeated submit reprints that PDF; a refusal is a message page naming
 * the order. Orders already stale here (changed, booked, not in a bookable phase) are named and left out.
 *
 * This tab does not stay stale: `data-submit-navigate` (core.js, SUBMIT-NAV-001) sends it to
 * «Abgeschlossen» right after the submit. A PAGE, not a window (the DEBTOR-WIN-002 pattern: the
 * selection bar is a GET form, core.js has no opener that turns it into a window).
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
    <form method="post" action="<?= e($actionBase) ?>/book" target="_blank" data-submit-navigate="<?= e($actionBase . '/list?view=closed') ?>" class="be-list__section">
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
        <div class="be-list__table" style="--be-list-cols: 5rem 6.5rem minmax(10rem, 2fr) minmax(8rem, 2fr) 8rem">
            <?php foreach ($orders as $order): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--mono">Nr. <?= e($order->documentNumber()) ?><input type="hidden" name="order[]" value="<?= e($order->getId() . ':' . $order->getVersion()) ?>"></span>
                    <span class="be-list__cell"><?= e($order->getOrderDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell"><?= e($order->getContact()->displayName()) ?></span>
                    <span class="be-list__cell"><?= e($order->getSubject()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $order->getTotalGross() !== null ? e($fmt($order->getTotalGross())) : '–' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Der Buchungsdruck erstellt zu jedem Auftrag die Rechnung, vergibt die Belegnummer, verbucht sie und öffnet alle als ein PDF in einem neuen Tab; die Aufträge stehen danach in «<?= e($booked?->getLabel() ?? 'Gebucht') ?>». Ein Kunde ohne Debitorenprofil wird dabei Debitor (Standard-Konditionen). Verbuchte Rechnungen sind unveränderlich — eine Korrektur ist eine Gutschrift.</p>
    </form>
</div>
