<?php
/**
 * «Status ändern …» (plan §6 / §9.4) — loaded into the order window (`data-window-link`; mask
 * `order-status`, record `sales-order:<id>`). The targets the transition rule allows
 * (`OrderStatuses::targets()`: forward and lateral, backward only while no invoice is booked,
 * never the booked status — the booking print's alone —, only active ones) as a select; the
 * VERSION shown and the entity token travel with it. When the stock seam demands it (consumed
 * stock would come back, ADR-043 decision 14) the form comes back with a mandatory «Grund».
 * Action row first (ADR-049): Status setzen · Zurück (the order, in this window).
 *
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var \Z77\Module\Order\Entities\OrderStatus|null $current
 * @var list<\Z77\Module\Order\Entities\OrderStatus> $targets
 * @var string $selected
 * @var string $reason
 * @var bool   $needReason
 * @var string $error
 * @var string $entityCsrf
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$window     = !empty($window);
$id         = (int) $order->getId();
$title      = 'Auftrag ' . $order->documentNumber() . ' · Status ändern';
$winAttr    = $window ? ' data-window="order-status" data-window-entity="sales-order:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/status?id=' . $id) ?>" class="be-list__section" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) $order->getVersion() ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => $targets !== [] ? 'Status setzen' : '',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="be-form__grid">
            <div class="be-form__field">
                <label for="order-status-current">Jetzt</label>
                <input type="text" id="order-status-current" value="<?= e($current?->getLabel() ?? $order->getStatusCode()) ?>" readonly>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status-to">Neuer Status</label>
                <?php if ($targets === []): ?>
                <input type="text" id="order-status-to" value="kein Wechsel möglich" readonly>
                <?php else: ?>
                <select id="order-status-to" name="to" required>
                    <?php foreach ($targets as $target): ?>
                    <option value="<?= e($target->getCode()) ?>"<?= $target->getCode() === $selected ? ' selected' : '' ?>><?= e($target->getLabel()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php if ($needReason): ?>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="order-status-reason">Grund der Rückbuchung</label>
                <textarea id="order-status-reason" name="stock_reason" rows="2" maxlength="255" required aria-invalid="<?= $reason === '' ? 'true' : 'false' ?>"><?= e($reason) ?></textarea>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($order->getInvoice() !== null): ?>
        <p class="be-form__hint">Die Rechnung ist verbucht: nur noch vorwärts — eine Korrektur ist eine Gutschrift auf der Rechnung.</p>
        <?php endif; ?>
    </form>
</div>
