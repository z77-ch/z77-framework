<?php
/**
 * Payment terms — hc1 (dark left slot): the primary add action. Added by
 * the fragment's `listAction()` (financial.md, «fragment slots»), so the
 * button lives with the screen it belongs to rather than in the host.
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/payment-terms') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Konditionen</span>
</button>
