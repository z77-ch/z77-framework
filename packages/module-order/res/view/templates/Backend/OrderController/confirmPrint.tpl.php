<?php
/**
 * «Lieferschein …» (plan D6, step 8) — loaded into the order window (`data-window-link`; mask
 * `order-delivery-note`). Says what the print does: it moves the order to the status flagged
 * `deliveryNoteTarget` when that lies AHEAD, or it is a reprint that changes nothing — and to
 * which address the note goes. One button POSTs (`#[Csrf]`, the version shown) into a NEW TAB
 * (`target="_blank"` — core.js leaves such a form to the browser): `printDeliveryNote()` first,
 * then the PDF. When the status moves, `data-submit-navigate` sends THIS tab to the list (the
 * window would show the old status; SUBMIT-NAV-001); a reprint leaves the window as it is.
 * Action row first (ADR-049): Lieferschein drucken · Zurück.
 *
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var \Z77\Module\Order\Entities\OrderStatus|null $current
 * @var \Z77\Module\Order\Entities\OrderStatus|null $target  the delivery-note target, null = none marked
 * @var bool $moves  the print moves the status
 * @var \Z77\Module\Debtor\Entities\AddressSnapshot|null $address
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$window     = !empty($window);
$id         = (int) $order->getId();
$title      = 'Lieferschein ' . $order->documentNumber();
$winAttr    = $window ? ' data-window="order-delivery-note" data-window-entity="sales-order:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
$navigate   = $moves ? ' data-submit-navigate="' . e($actionBase . '/list?view=all') . '"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/delivery-note?id=' . $id) ?>" target="_blank"<?= $navigate ?> class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) $order->getVersion() ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => 'Lieferschein drucken',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($moves): ?>
        <p>Der Druck setzt den Auftrag von «<?= e($current?->getLabel() ?? $order->getStatusCode()) ?>» auf <strong>«<?= e($target->getLabel()) ?>»</strong> und öffnet den Lieferschein in einem neuen Tab. Dieser Tab wechselt danach zur Liste.</p>
        <?php else: ?>
        <p>Nachdruck: der Status bleibt «<?= e($current?->getLabel() ?? $order->getStatusCode()) ?>»<?= $target === null ? ' (kein Status ist als Ziel des Lieferscheins markiert)' : '' ?>. Der Lieferschein öffnet sich in einem neuen Tab.</p>
        <?php endif; ?>
        <p class="be-form__hint">An: <?= $address !== null ? e(implode(', ', $address->lines())) : 'keine Adresse beim Kunden erfasst' ?><?= $order->deliveryAddress() !== null ? ' (Lieferadresse des Auftrags)' : ' (Rechnungsadresse — im Auftrag ist keine Lieferadresse gewählt)' ?></p>
    </form>
</div>
