<?php
/**
 * «Auftragsbestätigung …» and «Lieferschein …» — the two prints that MOVE an order (ADR-043
 * addendum 2026-10-15; plan D6) — loaded into the order window (`data-window-link`; masks
 * `order-confirmation` / `order-delivery-note`). Says what the print does: it moves the order into
 * the next phase (its default status) when the order stands BEFORE it, or it is a reprint that
 * changes nothing — and, for the delivery note, to which address it goes. One button POSTs
 * (`#[Csrf]`, the version shown) into a NEW TAB (`target="_blank"` — core.js leaves such a form to
 * the browser): the service first (`printConfirmation()` / `printDeliveryNote()`), then the PDF.
 * When the status moves, `data-submit-navigate` sends THIS tab to the list (the window would show
 * the old status; SUBMIT-NAV-001); a reprint leaves the window as it is.
 * Action row first (ADR-049): <document> drucken · Zurück.
 *
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var string $document  `confirmation` | `delivery-note`
 * @var \Z77\Module\Order\Entities\OrderStatus|null $current
 * @var \Z77\Module\Order\Entities\OrderStatus|null $target  the status the print sets, null = a reprint
 * @var \Z77\Module\Debtor\Entities\AddressSnapshot|null $address  the delivery note's, null for the confirmation
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$window     = !empty($window);
$id         = (int) $order->getId();
$isNote     = $document === 'delivery-note';
$name       = $isNote ? 'Lieferschein' : 'Auftragsbestätigung';
$title      = $name . ' ' . $order->documentNumber();
$moves      = $target !== null;
$winAttr    = $window ? ' data-window="order-' . e($document) . '" data-window-entity="sales-order:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
$navigate   = $moves ? ' data-submit-navigate="' . e($actionBase . '/list?view=all') . '"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/' . $document . '?id=' . $id) ?>" target="_blank"<?= $navigate ?> class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $order->getVersion() ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => $name . ' drucken',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($moves): ?>
        <p>Der Druck setzt den Auftrag von «<?= e($current?->getLabel() ?? $order->getStatusCode()) ?>» auf <strong>«<?= e($target->getLabel()) ?>»</strong> (<?= e($target->getPhase()->label()) ?>) und öffnet <?= $isNote ? 'den Lieferschein' : 'die Auftragsbestätigung' ?> in einem neuen Tab. Dieser Tab wechselt danach zur Liste.</p>
        <?php else: ?>
        <p>Nachdruck: der Status bleibt «<?= e($current?->getLabel() ?? $order->getStatusCode()) ?>». <?= $isNote ? 'Der Lieferschein' : 'Die Auftragsbestätigung' ?> öffnet sich in einem neuen Tab.</p>
        <?php endif; ?>
        <?php if ($isNote): ?>
        <p class="be-form__hint">An: <?= $address !== null ? e(implode(', ', $address->lines())) : 'keine Adresse beim Kunden erfasst' ?><?= $order->deliveryAddress() !== null ? ' (Lieferadresse des Auftrags)' : ' (Rechnungsadresse — im Auftrag ist keine Lieferadresse gewählt)' ?></p>
        <?php endif; ?>
    </form>
</div>
