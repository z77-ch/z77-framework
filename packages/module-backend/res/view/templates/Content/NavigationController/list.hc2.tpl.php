<?php
/** Navigation list — hc2 (toolbar), all left-aligned (ADR-033 rev. 2026-10-08): the view-area tabs
 *  (`.be-viewtabs`, moved from the work area), the live text filter, then «Drucken» (secondary).
 *  «+ Eintrag» is in the action cell (`list.act.tpl.php`); the URL-alias shortcut was dropped —
 *  Nav Alias has its own rail entry. Auto-loaded into the shell header band; the
 *  `.be-viewtabs__tab[data-group]` / `#js-nav-filter` / `#js-nav-print` hooks are read by
 *  `navigation/list.js` (same page, same DOM).
 *
 *  @var array<int,array{key:string,label:string}> $areas
 */
?>
<div class="be-viewtabs" role="tablist" aria-label="Bereich">
    <button type="button" class="be-viewtabs__tab is-active" data-group="*" role="tab" aria-selected="true">Alle</button>
    <?php foreach ($areas as $area): ?>
    <button type="button" class="be-viewtabs__tab" data-group="<?= e($area['key']) ?>" role="tab" aria-selected="false"><?= e($area['label']) ?></button>
    <?php endforeach; ?>
</div>
<div class="be-list__filter">
    <svg class="be-icon be-list__filter-icon" width="13" height="13" aria-hidden="true"><use href="#icon-search"/></svg>
    <input type="search" id="js-nav-filter" class="be-list__filter-input" placeholder="Filtern …" autocomplete="off">
</div>
<button type="button" id="js-nav-print" class="be-btn be-btn--ghost">
    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-print"/></svg> <span class="be-btn__label">Drucken</span>
</button>
