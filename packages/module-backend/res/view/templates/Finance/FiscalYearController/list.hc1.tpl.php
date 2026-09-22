<?php
/**
 * Geschäftsjahre list — hc1 (dark left slot): open the next fiscal year.
 * Auto-loaded by BackendAbstractController::loadHeaderSlots(); lives in
 * module-backend because the slot loader resolves against the backend
 * namespace for the mounting controller.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/fiscal-year') . '/open') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Geschäftsjahr eröffnen</span>
</button>
