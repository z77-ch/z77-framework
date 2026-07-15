<?php
/** Translation list — hc1 (dark left slot): a primary «＋ Eintrag» button that opens a small
 *  picker panel to choose the entry TYPE (Text / Slug), because translation has two add kinds.
 *  Uses the shared panel-toggle contract (data-panel-root / -trigger / -panel — panel-toggle.js
 *  is loaded globally, binds automatically). hc2 is deliberately left free for other controls
 *  (e.g. a future select-all). Auto-loaded by BackendAbstractController::loadHeaderSlots(). */
?>
<div class="be-shell-add" data-panel-root>
    <button type="button" class="be-btn be-btn--primary" data-panel-trigger aria-haspopup="true" aria-expanded="false">
        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
        Eintrag
        <svg class="be-icon" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </button>
    <div class="be-shell-add__panel" hidden data-panel role="menu" aria-label="Eintrag hinzufügen">
        <button type="button" class="be-shell-add__item" role="menuitem" data-fetch-get="/backend/content/translation/add?kind=ui">
            <svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#icon-type"/></svg> Text
        </button>
        <button type="button" class="be-shell-add__item" role="menuitem" data-fetch-get="/backend/content/translation/add?kind=slug">
            <svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#icon-link"/></svg> Slug
        </button>
    </div>
</div>
