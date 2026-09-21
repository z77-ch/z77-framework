<?php
/**
 * Contacts list — hc1 (dark left slot): the primary add action. Auto-loaded
 * into the shell header band by BackendAbstractController::loadHeaderSlots().
 * Lives in module-backend (not module-contact) because the slot loader
 * resolves header partials against the backend namespace for the mounting
 * controller — same arrangement as the tax-code and member-accounts slots.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/contact/contact') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Kontakt</span>
</button>
