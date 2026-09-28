<?php
/**
 * Toolbar (ADR-033 rev. 2026-09-28): the action acts on the list in the work area, so it stands there — not in the action cell over the rail.
 *
 * Address types list — toolbar (hc1 until 2026-09-28): the primary add action.
 * Auto-loaded by BackendAbstractController::loadHeaderSlots(); lives in
 * module-backend because the slot loader resolves against the backend
 * namespace for the mounting controller.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/contact/address-type') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Adresstyp</span>
</button>
