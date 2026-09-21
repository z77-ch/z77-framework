<?php
/**
 * Steuercodes list — hc1 (dark left slot): the primary add action. Auto-loaded
 * into the shell header band by BackendAbstractController::loadHeaderSlots().
 * Lives in module-backend (not module-vat) because the slot loader resolves
 * header partials against the backend namespace for the mounting controller —
 * same arrangement as the member accounts and DMS Drive slots.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/tax-code') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Steuercode</span>
</button>
