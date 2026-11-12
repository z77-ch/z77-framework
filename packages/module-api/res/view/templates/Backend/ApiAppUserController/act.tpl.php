<?php
/**
 * App-Benutzer — the action cell (hc1): «+ App-Benutzer», the most frequent action of this entry
 * (backend-screen.md §2). Exactly one control, rendered inset by the shell; create =
 * `.be-btn--primary`. Opens the window of a NEW app user (ADR-047, `data-window-open`); the href
 * stays for a ctrl-click and a browser without the script. The origin names the list region, so
 * the save reloads it.
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? \Z77\Module\Api\Ui\ApiAppUserLayout::BASE) . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Api\Ui\ApiAppUserListing::definition()->region()) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">App-Benutzer</span>
</a>
