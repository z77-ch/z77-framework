<?php
/**
 * Action cell (hc1, `{action}.act`) of `contact/contact/list` — ADR-033 rev. 2026-10-08: the most
 * frequent action of the entry (add a contact), exactly one button, rendered inset by the shell.
 * Create = `.be-btn--primary`. Below 767px the cell is a square at the right end of the toolbar
 * showing the icon alone; the word stays in `.be-btn__label` (accessible name).
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/contact/contact') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Kontakt</span>
</button>
