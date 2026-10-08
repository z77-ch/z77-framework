<?php
/**
 * Action cell (hc1, `{action}.act`) of `documents/drive/list` — ADR-033 rev. 2026-10-08: the most
 * frequent action of the entry (upload), exactly one button, rendered inset by the shell. Create =
 * `.be-btn--primary`, glyph «+» like every create action (the phone square shows it alone; the
 * word stays in `.be-btn__label` as the accessible name).
 *
 * It keeps `data-drive-upload` + `data-drive-scope`: drive.js opens the upload for any
 * `[data-drive-upload]` inside a `[data-drive-scope]` and reads the target folder off the
 * live-refreshed breadcrumb pane — the place of the button does not matter.
 *
 * Auto-loaded by BackendAbstractController::loadHeaderSlots().
 */
?>
<button type="button" class="be-btn be-btn--primary" data-drive-upload data-drive-scope>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
    <span class="be-btn__label">Hochladen</span>
</button>
