<?php
/**
 * Mandant — the action cell (hc1, ADR-033 rev. 2026-10-08): «Speichern» («Mandant anlegen» while
 * there is no record yet), the entry's most frequent action. Something is WRITTEN, so it is the
 * green confirm button (`.be-btn--confirm`) with the check glyph — on a phone the cell is that
 * glyph alone, the word stays the accessible name (`.be-btn__label`).
 *
 * A submit OUTSIDE the form: `form="mandator-edit"` reaches the page form in the work area, no
 * script (ADR-033: «a form's submit belongs in the action cell, not at the end of the body»).
 * Part of the fragment: the trait's `editAction()` adds it to the shell slot with
 * `addPartials()`, so it comes along wherever the fragment is mounted.
 *
 * @var bool $isNew
 */
?>
<button type="submit" form="mandator-edit" class="be-btn be-btn--confirm">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label"><?= !empty($isNew) ? 'Mandant anlegen' : 'Speichern' ?></span>
</button>
