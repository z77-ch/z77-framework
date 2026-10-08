<?php
/**
 * Payment targets — the action cell (hc1): «+ Zahlungsziel», the most frequent action
 * of this master-data entry (ADR-033 rev. 2026-10-08; until then the toolbar's add button
 * `addButton.tpl.php`). Exactly one button, rendered FLUSH by the shell; create =
 * `.be-btn--primary`, opening the add dialog (`data-fetch-get`). On a phone the cell
 * becomes a square icon at the right end of the toolbar; the word stays the accessible name.
 *
 * Part of the fragment: added by `listAction()` (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 */
?>
<button type="button" class="be-btn be-btn--primary" data-fetch-get="<?= e(($actionBase ?? '/backend/finance/payment-target') . '/add') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Zahlungsziel</span>
</button>
