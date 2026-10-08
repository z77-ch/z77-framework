<?php
/**
 * Journal — the action cell (hc1) of the capture area: «Buchen», the most frequent action of
 * the entry Finanzen › Journal (ADR-033 rev. 2026-10-08; it stood in the toolbar from
 * 2026-09-28). Something is WRITTEN, so it is the green confirm button (`.be-btn--confirm`)
 * with the check glyph — on a phone the cell is that glyph alone, the word stays the
 * accessible name (`.be-btn__label`).
 *
 * A submit OUTSIDE the form (`form="journal-capture"`, no script). The action cell comes
 * before the work area in document order, so it is the form's FIRST submit button: Enter in
 * any field posts — in the Sammelbuchung too, where it sends `op=save` ahead of «Weitere Zeilen».
 *
 * Part of the fragment: added by the trait (financial.md, «fragment slots»).
 */
?>
<button type="submit" form="journal-capture" class="be-btn be-btn--confirm" name="op" value="save">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
    <span class="be-btn__label">Buchen</span>
</button>
