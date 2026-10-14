<?php
/**
 * Aufträge › Aufträge — the action cell (hc1): «+ Neuer Auftrag», the most frequent action of the
 * entry (the owner's board; css-backend.md «action cell per area»). Exactly one control, rendered
 * inset by the shell; create = `.be-btn--primary`. Opens the order WINDOW (ADR-047,
 * `data-window-open`); the cell stands outside the list region, so it names the list as its origin
 * — the save reloads it. The href stays for a ctrl-click and a browser without the script.
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? \Z77\Module\Order\Ui\OrderLayout::BASE) . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Order\Ui\OrderListing::ID) ?>-list">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Neuer Auftrag</span>
</a>
