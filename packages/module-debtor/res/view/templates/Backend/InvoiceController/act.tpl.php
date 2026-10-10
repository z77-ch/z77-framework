<?php
/**
 * Documents — the action cell (hc1): «+ Rechnung», the most frequent action of
 * «Aufträge › Rechnungen» (ADR-033 rev. 2026-10-08). Exactly one button, rendered
 * FLUSH by the shell; create = `.be-btn--primary`. On a phone the cell becomes a
 * square icon at the right end of the toolbar — the word stays the accessible name
 * in `.be-btn__label`.
 *
 * Opens the editor as a WINDOW (ADR-047, addendum 2026-10-10: `data-window-open`);
 * the cell stands outside the list region, so it names the list as its origin
 * (`data-origin`) — the save reloads the list. The `href` stays: without the
 * script, and on a ctrl-click, the editor is a page.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 */
$add = ($actionBase ?? '/backend/finance/invoice') . '/add';
?>
<a class="be-btn be-btn--primary" href="<?= e($add) ?>" data-window-open="<?= e($add) ?>" data-origin="region:<?= e(\Z77\Module\Debtor\Ui\InvoiceListing::ID) ?>-list">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Rechnung</span>
</a>
