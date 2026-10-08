<?php
/**
 * Debtor master data — the action cell (hc1): «+ Debitor», the most frequent
 * action of «Stammdaten › Aufträge › Debitoren» (ADR-033 rev. 2026-10-08).
 * Exactly one button, rendered FLUSH by the shell; create = `.be-btn--primary`.
 * It opens the add dialog WITHOUT a contact: the dialog's first field is the
 * choice of an active contact without a profile (`addAction()`), so no row
 * button is needed any more. On a phone the cell becomes a square icon at the
 * right end of the toolbar; the word stays the accessible name.
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/debtor-profile') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Debitor</span>
</button>
