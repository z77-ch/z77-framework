<?php
/**
 * A print of the order that cannot be made — the PAGE the new tab of a PDF link or of the
 * delivery-note POST shows instead of the PDF: the reason (debtor's preview refusal, a stale
 * version, a lock conflict); nothing was written. Leads back to the list.
 *
 * @var string $message
 * @var \Z77\Module\Order\Entities\SalesOrder|null $order
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE;
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="z77-form-actions">
            <a class="be-btn be-btn--ghost" href="<?= e($actionBase . '/list?view=all') ?>">Zur Liste</a>
        </div>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= $order !== null ? 'Auftrag ' . e($order->documentNumber()) : 'Auftrag' ?> <small class="be-list__cell--muted">· nicht gedruckt</small></h2>
        </div>
        <div class="be-modal__alert be-modal__alert--error"><?= e($message) ?></div>
    </div>
</div>
