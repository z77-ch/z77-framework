<?php
/**
 * Aufträge › Aufträge (plan §9.3; the owner's board on the developer canvas) — one VIEW tab at a
 * time (toolbar): Offerten · In Arbeit · Geliefert · Abgeschlossen · Storniert · Alle — the
 * PHASES (`OrderListing::viewOf()`, ADR-043 addendum 2026-10-15). A STANDARD LIST (listing.md): the head and the
 * search form are the kernel's partials over {@see \Z77\Module\Order\Ui\OrderListing}, the rows
 * are `_row`; no CSS and no JavaScript of its own:
 *
 *   - the state icon opens the order as a WINDOW (ADR-047), the href stays for a ctrl-click;
 *   - in «In Arbeit» and «Geliefert» (the bookable phases) each row carries a checkbox of the form `#order-book` (GET to
 *     `confirm-book`) with `{id}:{version}` — the version THIS row shows, so an order changed
 *     before the booking print is refused instead of booked unseen; «Buchungsdruck …» is bound
 *     to that selection, so it stands in the SELECTION BAR above the list (green, ADR-033
 *     exception 2), the confirmation names the count;
 *   - the whole list is a FETCH REGION (`order-find-list`): sort / page / search reload only this
 *     part, and a save in a window opened from here reloads it (`refresh-region`).
 *
 * @var list<\Z77\Module\Order\Entities\SalesOrder> $orders
 * @var array<int, \Z77\Module\Debtor\Services\PaymentState> $payments  order id → state («Gebucht» only)
 * @var array<string, \Z77\Module\Order\Entities\OrderStatus> $statuses  code → status
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var string $view
 * @var array<string, string> $views
 * @var string $actionBase
 */
use Z77\Module\Order\Ui\OrderListing;

$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$selectable = OrderListing::isSelectable($view);
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
            <h2 class="be-list__section-title"><?= e($views[$view] ?? '') ?></h2>
            <span class="be-list__section-badge" title="Aufträge"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
        <?php if ($selectable && $orders !== []): ?>
        <form id="order-book" method="get" action="<?= e($actionBase) ?>/confirm-book"></form>
        <div class="z77-form-actions">
            <button type="submit" form="order-book" class="be-btn be-btn--confirm be-btn--sm">
                <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
                <span class="be-btn__label">Buchungsdruck …</span>
            </button>
            <small class="be-list__cell--muted">für die angekreuzten Aufträge die Rechnungen erstellen, verbuchen und als ein PDF drucken — die Bestätigung nennt Anzahl und Total</small>
        </div>
        <?php endif; ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="sales-order">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($orders as $order): ?>
                <?= $this->partial('Backend/OrderController/_row', [
                    'order'      => $order,
                    'status'     => $statuses[$order->getStatusCode()] ?? null,
                    'payment'    => $payments[$order->getId()] ?? null,
                    'view'       => $view,
                    'definition' => $definition,
                    'actionBase' => $actionBase,
                ], 'Z77\\Module\\Order') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($orders === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Auftrag gefunden.' : 'Keine Aufträge in dieser Ansicht.' ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Aufträge',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
