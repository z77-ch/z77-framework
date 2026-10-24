<?php
/**
 * «Auftrag löschen …» (ADR-054 decision 14, owner decision O5; step 6 of the Faktura-Journal build plan) —
 * loaded into the order window (`data-window-link`; mask `order-delete`, record `sales-order:<id>`): number,
 * customer, total, the invoice that goes «gelöscht» with it, and a MANDATORY reason. The order is KEPT —
 * stamped deleted with its number (it may stand on a quote), hidden from the lists, under «Gelöscht»; its
 * stock effects are brought to zero. An order whose invoice is posted is not deleted (the refusal stands
 * here, no button). Action row first (ADR-049).
 *
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var string $reason
 * @var string $error
 * @var bool   $refused  the order may not be deleted (no button)
 * @var string $entityCsrf
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
$window     = !empty($window);
$id         = (int) $order->getId();
$title      = 'Auftrag ' . $order->documentNumber() . ' · löschen';
$winAttr    = $window ? ' data-window="order-delete" data-window-entity="sales-order:' . $id . '" data-window-title="' . e($title) . '" data-window-width="40rem"' : '';
$invoice    = $order->getInvoice();
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/confirm-delete?id=' . $id) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
        <input type="hidden" name="version" value="<?= (int) $order->getVersion() ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => empty($refused) ? 'Auftrag löschen' : '',
            'kind'       => 'danger',
            'cancel'     => 'Zurück',
            'cancelHref' => $actionBase . '/edit?id=' . $id,
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 11rem minmax(12rem, 1fr)">
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Kunde</span><span class="be-list__cell"><?= e($order->getContact()->displayName()) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Total</span><span class="be-list__cell"><?= $order->getTotalGross() !== null ? e($fmt($order->getTotalGross())) : '–' ?></span></div></div>
            <?php if ($invoice !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Rechnung</span><span class="be-list__cell"><?= e($invoice->documentName() . ' · ' . $invoice->state()->label()) ?></span></div></div>
            <?php endif; ?>
        </div>
        <?php if (empty($refused)): ?>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="order-delete-reason">Grund</label>
            <textarea id="order-delete-reason" name="reason" rows="2" maxlength="255" required aria-invalid="<?= $error !== '' && $reason === '' ? 'true' : 'false' ?>"><?= e($reason) ?></textarea>
        </div>
        <p class="be-form__hint">Der Auftrag bleibt mit seiner Nummer und dem Grund unter «Gelöscht» aufbewahrt; reservierter und ausgelieferter Bestand wird zurückgebucht.<?= $invoice !== null ? ' Die fakturierte Rechnung ' . e((string) $invoice->getNumber()) . ' ist noch nicht verbucht: sie wird mit dem Auftrag gelöscht (bleibt mit Nummer unter «Gelöscht»), wartende E-Mails zu ihr werden abgebrochen.' : '' ?></p>
        <?php endif; ?>
    </form>
</div>
