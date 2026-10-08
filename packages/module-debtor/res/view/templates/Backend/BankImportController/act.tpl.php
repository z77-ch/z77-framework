<?php
/**
 * Zahlungseingänge — the action cell (hc1): «+ camt.054 einlesen», the most
 * frequent action of «Aufträge › Zahlungseingänge» (ADR-033 rev. 2026-10-08).
 * Exactly one button, rendered FLUSH by the shell; create = `.be-btn--primary`.
 * The upload form itself stays in the work area of the list (`#bank-upload`):
 * the button is a plain link to it — on the list it scrolls there, from a
 * message's detail it opens the list at the form. No script.
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 *
 * @var string $actionBase
 */
?>
<a class="be-btn be-btn--primary" href="<?= e(($actionBase ?? '/backend/finance/bank-import') . '/list#bank-upload') ?>">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">camt.054 einlesen</span>
</a>
