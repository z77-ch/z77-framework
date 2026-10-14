<?php
/**
 * Auftragsstatus — the action cell (hc1): «+ Status», the most frequent action of this
 * master-data entry (backend-screen.md §2, css-backend.md «action cell per area»). Exactly one
 * control, rendered inset by the shell; create = `.be-btn--primary`. Opens the status WINDOW
 * (ADR-047, `data-window-open`); the href stays for a ctrl-click and a browser without the
 * script. The origin names the list region, so the save reloads it.
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? \Z77\Module\Order\Ui\OrderStatusLayout::BASE) . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Order\Ui\OrderStatusListing::definition()->region()) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Status</span>
</a>
