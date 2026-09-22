<?php
/**
 * Kontenplan list — hc1 (dark left slot): the primary add action. Auto-loaded
 * by BackendAbstractController::loadHeaderSlots(); lives in module-backend
 * because the slot loader resolves against the backend namespace for the
 * mounting controller. «KMU-Kontenrahmen übernehmen» is not here: it is
 * offered in the list body, and only while the chart is empty.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/account') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Konto</span>
</button>
