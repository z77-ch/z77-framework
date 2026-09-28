<?php
/**
 * Drive list — hc2 (middle slot): the Drive's TOOLS, and only tools (ADR-033:
 * the toolbar operates, the crumb line — hc3 — says where one is). Folder
 * edit/move/delete act on the folder currently open and used to sit inside the
 * breadcrumb pane; they are static shell buttons now, reading their
 * server-built URLs off the live-refreshed pane's data attributes — the same
 * mechanism the upload button has always used, so they stay current
 * across pane swaps without any URL assembly in JS. drive.js hides the three
 * while no folder is selected (an empty data URL = nothing to act on).
 *
 * The upload comes FIRST (it was hc1 until 2026-09-28): the tools push their
 * right group to the edge with `__gap`, so an action after them would land
 * at the far right. It keeps `data-drive-scope` — drive.js treats it as part
 * of the Drive. Toolbar (ADR-033 rev. 2026-09-28): the action acts on the list in the work area, so it stands there — not in the action cell over the rail.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 */
?>
<button type="button" class="be-btn be-btn--primary" data-drive-upload data-drive-scope>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-upload"/></svg> <span class="be-btn__label">Hochladen</span>
</button>
<div class="be-drive-tools" data-drive-scope>
    <button type="button" class="be-icon-btn" data-drive-folder-edit title="Ordner bearbeiten" hidden>
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-edit"/></svg>
    </button>
    <button type="button" class="be-icon-btn" data-drive-folder-move title="Ordner verschieben" hidden>
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-move"/></svg>
    </button>
    <button type="button" class="be-icon-btn" data-drive-folder-delete title="Ordner löschen" hidden>
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-trash"/></svg>
    </button>
    <span class="be-drive-tools__gap" aria-hidden="true"></span>
    <button type="button" class="be-icon-btn" data-drive-folder-add title="Neuer Ordner">
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-folder-plus"/></svg>
    </button>
    <button type="button" class="be-icon-btn" data-drive-trash title="Papierkorb">
        <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-trash"/></svg>
    </button>
</div>
