<?php
/**
 * Action cell (hc1, `{action}.act`) of `content/translation/list` — ADR-033 rev. 2026-10-08: the
 * most frequent action of the entry as a SPLIT picker «+ Texteintrag | ▾», rendered inset in the
 * island accent by the shell. Translation has two add kinds; the everyday one is the UI text, so
 * the main part opens that dialog directly and NAMES it (owner 2026-10-08: a split button says
 * what its main part does — «Daten sichern», «Texteintrag»), the narrow chevron part opens the
 * menu with both kinds, the default first (css-backend.md → split picker). On a phone the cell
 * is ONE square showing the chevron alone — the menu carries the default as its first item.
 *
 * Uses the shared panel-toggle contract (data-panel-root / -trigger / -panel — panel-toggle.js is
 * loaded globally, binds automatically). Auto-loaded by BackendAbstractController::loadHeaderSlots().
 */
?>
<div class="be-shell-add be-shell-add--split" data-panel-root>
    <div class="be-shell-add__main">
        <button type="button" class="be-btn be-btn--primary" data-fetch-get="/backend/content/translation/add?kind=ui" title="Texteintrag hinzufügen">
            <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg>
            <span class="be-btn__label">Texteintrag</span>
        </button>
    </div>
    <button type="button" class="be-btn be-btn--primary be-shell-add__toggle" data-panel-trigger
            aria-haspopup="true" aria-expanded="false" aria-label="Eintragsart wählen" title="Eintragsart wählen">
        <svg class="be-icon be-shell-add__chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
    </button>
    <div class="be-shell-add__panel" hidden data-panel role="menu" aria-label="Eintrag hinzufügen">
        <button type="button" class="be-shell-add__item" role="menuitem" data-fetch-get="/backend/content/translation/add?kind=ui">
            <svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#icon-type"/></svg> Texteintrag
        </button>
        <button type="button" class="be-shell-add__item" role="menuitem" data-fetch-get="/backend/content/translation/add?kind=slug">
            <svg class="be-icon" width="13" height="13" aria-hidden="true"><use href="#icon-link"/></svg> Slug-Eintrag
        </button>
    </div>
</div>
