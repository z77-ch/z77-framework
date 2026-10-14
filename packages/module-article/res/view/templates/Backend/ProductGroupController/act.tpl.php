<?php
/**
 * Warengruppen — the action cell (hc1): «+ Warengruppe», the most frequent action of this
 * master-data entry (backend-screen.md §2). Exactly one control; create = `.be-btn--primary`.
 * Opens the group WINDOW (ADR-047) for a new ROOT group; a sub-group is started from its parent's
 * row («+ Untergruppe») or by choosing the parent in the window. The href stays for a ctrl-click
 * and a browser without the script.
 *
 * Part of the fragment: added by `listAction()`.
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? \Z77\Module\Article\Ui\ProductGroupLayout::BASE) . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Article\Ui\ProductGroupLayout::REGION) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Warengruppe</span>
</a>
