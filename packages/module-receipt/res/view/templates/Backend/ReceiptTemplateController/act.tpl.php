<?php
/**
 * Belegvorlagen — the action cell (hc1): «+ Vorlage», the most frequent action of this master-data
 * entry (backend-screen.md §2). Exactly one control, rendered inset by the shell; create =
 * `.be-btn--primary`. Opens the template WINDOW (ADR-047, `data-window-open`); the href stays for a
 * ctrl-click and a browser without the script. The origin names the list region, so the save
 * reloads it.
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::TEMPLATE_BASE) . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Receipt\Ui\MasterDataListing::templates()->region()) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Vorlage</span>
</a>
