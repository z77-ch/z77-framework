<?php
/**
 * Drive list — hc2 (toolbar): the Drive's TOOLS, and only tools (ADR-033: the toolbar operates,
 * the crumb line — hc3 — says where one is), all LEFT-aligned (rev. 2026-10-08).
 *
 * **One group, one look** (owner 2026-10-10, after the live look): the four folder tools are
 * `.be-btn` with icon AND label — «Neuer Ordner», «Bearbeiten», «Verschieben», «Löschen …».
 * Before, the same row held a labelled button, two bare icon buttons, a labelled danger button
 * and another bare icon: four tools in three dresses, and the eye had to decide for each one
 * what it was looking at. An icon replaces a word only where the word does not fit
 * (`backend-screen.md` → Controls); here it fits.
 *
 * **The trash is no longer a tool here, it is a VIEW — a tab at the right end** (owner
 * 2026-10-10). It was the second `#icon-trash` right beside «Löschen …»: one picture for
 * «delete this folder» and for «show what was deleted», the one pair that must not look alike.
 * And it is not an operation on the open folder at all — emptying the trash is a rare cleanup
 * on the whole COLLECTION. Such views get their own cluster at the right end of the toolbar,
 * away from the tools that act on what is selected; more of them are expected (owner), which is
 * why this is a tab strip and not a single button.
 *
 * Folder edit/move/delete act on the folder currently open and used to sit inside the breadcrumb
 * pane; they are static shell buttons, reading their server-built URLs off the live-refreshed
 * pane's data attributes — the same mechanism the upload button uses, so they stay current across
 * pane swaps without any URL assembly in JS. drive.js sets `hidden` on the three while no folder
 * is selected (an empty data URL = nothing to act on).
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 */
?>
<div class="be-drive-tools" data-drive-scope>
    <button type="button" class="be-btn be-btn--ghost" data-drive-folder-add>
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-folder-plus"/></svg> <span class="be-btn__label">Neuer Ordner</span>
    </button>
    <button type="button" class="be-btn be-btn--ghost" data-drive-folder-edit hidden>
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg> <span class="be-btn__label">Bearbeiten</span>
    </button>
    <button type="button" class="be-btn be-btn--ghost" data-drive-folder-move hidden>
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-move"/></svg> <span class="be-btn__label">Verschieben</span>
    </button>
    <button type="button" class="be-btn be-btn--danger" data-drive-folder-delete hidden>
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-trash"/></svg> <span class="be-btn__label">Löschen …</span>
    </button>

    <?php /* Views of the COLLECTION, right-aligned by the gap — not tools on the open folder.
             A tab, because that is the backend's form for «another view of the same thing»
             (ADR-033 / backend-screen.md), and because the owner expects more of them. */ ?>
    <span class="be-drive-tools__gap"></span>
    <div class="be-viewtabs">
        <button type="button" class="be-viewtabs__tab" data-drive-trash>Papierkorb</button>
    </div>
</div>
