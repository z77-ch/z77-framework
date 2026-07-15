<?php
/** Drive list — hc1 (dark left slot): the primary add action (upload). Auto-loaded into the shell
 *  header band by BackendAbstractController::loadHeaderSlots(). The button carries `data-drive-upload`;
 *  drive.js reads the upload target off the (live-refreshed) breadcrumb pane's `data-add-url`. Marked
 *  `data-drive-scope` so drive.js treats this out-of-fragment slot as part of the Drive. */
?>
<button type="button" class="be-btn be-btn--primary" data-drive-upload data-drive-scope>
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-upload"/></svg> Hochladen
</button>
