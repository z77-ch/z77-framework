<?php
/**
 * Steuercodes list — the action cell (hc1, `{action}.act`, ADR-033 rev. 2026-10-08; the toolbar
 * from 2026-09-28): «+ Steuercode», the entry's most frequent action. Create = `.be-btn--primary`
 * with the plus glyph; the word in `.be-btn__label` (on a phone the cell shows the glyph alone,
 * the word stays the accessible name). It opens the add dialog (`data-fetch-get`).
 *
 * Auto-loaded into the shell header band by BackendAbstractController::loadHeaderSlots().
 * Lives in module-backend (not module-vat) because the slot loader resolves
 * header partials against the backend namespace for the mounting controller —
 * same arrangement as the member accounts and DMS Drive slots.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/tax-code') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Steuercode</span>
</button>
