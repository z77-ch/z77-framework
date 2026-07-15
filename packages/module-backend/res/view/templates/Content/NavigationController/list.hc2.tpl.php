<?php
/** Navigation list — hc2 (middle slot): the live filter + shortcut actions (print, URL aliases).
 *  Auto-loaded into the shell header band; the `#js-nav-filter` / `#js-nav-print` hooks are read by
 *  `navigation/list.js` (same page, same DOM). */
?>
<div class="be-list__filter">
    <svg class="be-icon be-list__filter-icon" width="13" height="13" aria-hidden="true"><use href="#icon-search"/></svg>
    <input type="search" id="js-nav-filter" class="be-list__filter-input" placeholder="Filtern …" autocomplete="off">
</div>
<span style="flex:1"></span>
<button type="button" id="js-nav-print" class="be-icon-btn" title="Drucken">
    <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-print"/></svg>
</button>
<a class="be-icon-btn" href="/backend/content/navigation-alias/list" title="URL-Aliase verwalten">
    <svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#icon-link"/></svg>
</a>
