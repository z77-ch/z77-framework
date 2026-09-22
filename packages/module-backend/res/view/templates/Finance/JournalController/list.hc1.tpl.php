<?php
/**
 * Journal list — hc1 (dark left slot): post a manual entry into the shown
 * fiscal year. A plain link to the entry PAGE (the form has n lines and a
 * server-side «Weitere Zeilen» — a page, not a modal; no JavaScript).
 * Auto-loaded by BackendAbstractController::loadHeaderSlots(); lives in
 * module-backend because the slot loader resolves against the backend
 * namespace for the mounting controller.
 *
 * @var \Z77\Module\Financial\Entities\FiscalYear|null $year
 * @var string $actionBase
 */
if (($year ?? null) === null) { return; }
?>
<a class="be-btn be-btn--primary" href="<?= e(($actionBase ?? '/backend/finance/journal') . '/add?year=' . rawurlencode($year->getCode())) ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg> <span class="be-btn__label">Buchung erfassen</span>
</a>
