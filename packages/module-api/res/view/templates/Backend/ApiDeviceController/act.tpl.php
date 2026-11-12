<?php
/**
 * Geräte — the action cell (hc1): «+ Gerät koppeln», the most frequent action of this entry
 * (backend-screen.md §2, board «Geräte»). Exactly one control, rendered inset by the shell;
 * create = `.be-btn--primary`. Opens the pairing WINDOW (ADR-047, `data-window-open`); the href
 * stays for a ctrl-click and a browser without the script. The origin names the list region,
 * so a pairing reloads it.
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var string $actionBase
 */
$pair = ($actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE) . '/pair';
?>
<a class="be-btn be-btn--primary" href="<?= e($pair) ?>" data-window-open="<?= e($pair) ?>" data-origin="region:<?= e(\Z77\Module\Api\Ui\ApiDeviceListing::definition()->region()) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Gerät koppeln</span>
</a>
