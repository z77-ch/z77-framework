<?php
/**
 * Drive list — hc2 (toolbar): the Drive's TOOLS, and only tools (ADR-033: the toolbar operates,
 * the crumb line — hc3 — says where one is), all LEFT-aligned (rev. 2026-10-08): «Neuer Ordner»
 * (secondary), the folder tools edit / move, «Löschen …» (danger — a confirmation follows), then
 * the trash. The upload is in the action cell (`list.act.tpl.php`).
 *
 * Folder edit/move/delete act on the folder currently open and used to sit inside the breadcrumb
 * pane; they are static shell buttons, reading their server-built URLs off the live-refreshed
 * pane's data attributes — the same mechanism the upload button uses, so they stay current across
 * pane swaps without any URL assembly in JS. drive.js sets `hidden` on the three while no folder
 * is selected (an empty data URL = nothing to act on).
 *
 * The trash («Papierkorb») stays here for now; moving it into the folder tree is a separate change.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 */
?>
<div class="be-drive-tools" data-drive-scope>
    <button type="button" class="be-btn be-btn--ghost" data-drive-folder-add>
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-folder-plus"/></svg> <span class="be-btn__label">Neuer Ordner</span>
    </button>
    <button type="button" class="be-icon-btn" data-drive-folder-edit title="Ordner bearbeiten" hidden>
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-edit"/></svg>
    </button>
    <button type="button" class="be-icon-btn" data-drive-folder-move title="Ordner verschieben" hidden>
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-move"/></svg>
    </button>
    <button type="button" class="be-btn be-btn--danger" data-drive-folder-delete title="Ordner löschen" hidden>
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-trash"/></svg> <span class="be-btn__label">Löschen …</span>
    </button>
    <button type="button" class="be-icon-btn" data-drive-trash title="Papierkorb">
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-trash"/></svg>
    </button>
</div>
