<?php
/**
 * Zahlungsarten — the action cell (hc1): «+ Zahlungsart», the most frequent action of this
 * master-data entry (backend-screen.md §2) — the look of «+ Vorlage». Opens the WINDOW (ADR-047);
 * the origin names the list region, so the save reloads it.
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::PAYMENT_METHOD_BASE) . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Receipt\Ui\MasterDataListing::paymentMethods()->region()) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Zahlungsart</span>
</a>
