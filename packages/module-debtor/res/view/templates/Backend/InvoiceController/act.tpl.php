<?php
/**
 * Documents — the action cell (hc1): «+ Rechnung», the most frequent action of
 * «Aufträge › Rechnungen» (ADR-033 rev. 2026-10-08). Exactly one button, rendered
 * FLUSH by the shell; create = `.be-btn--primary`. On a phone the cell becomes a
 * square icon at the right end of the toolbar — the word stays the accessible name
 * in `.be-btn__label`. A link, not a fetch dialog: the invoice editor is a page.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 */
?>
<a class="be-btn be-btn--primary" href="<?= e(($actionBase ?? '/backend/finance/invoice') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Rechnung</span>
</a>
