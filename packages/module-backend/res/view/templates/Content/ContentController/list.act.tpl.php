<?php
/**
 * Action cell (hc1, `{action}.act`) of `content/content/list` — the reference migration of
 * ADR-033's revision 2026-10-08: the MOST FREQUENT action of the selected navigation entry
 * («Inhalte» → create a content), exactly one button, rendered inset by the shell (it fills the
 * cell less its inset). Create = `.be-btn--primary` (accent fill). The icon is the phone glyph: below
 * 767px the cell becomes a square at the right end of the toolbar and shows the icon alone; the
 * word stays in `.be-btn__label` (visually hidden there, still the button's accessible name).
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="/backend/content/content/add">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Inhalt</span>
</button>
