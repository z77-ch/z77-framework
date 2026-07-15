<?php
/** Site-wide crawl-block Störer (SEO-NOINDEX-001, see metadata.md).
 *  A large, full-width, non-dismissible banner shown across the top of the backend
 *  shell whenever the site is blocked from search engines (SEO_NOINDEX). It is
 *  ALWAYS in the DOM (guarded only against guests) so the service-panel toggle can
 *  show/hide it live via `system/cache.js`; the `hidden` attribute reflects the flag.
 *  The nag fires on the ACTIVE (blocked) state — a live site left on noindex is the
 *  dangerous-if-forgotten direction. */
/** @var array{initials:string,name:string,role:string}|null $headerUser */
if (empty($headerUser)) return; // no chrome for guests (mirrors topbar)
?>
<div id="js-noindex-banner" class="be-noindex-banner" role="alert"<?= SEO_NOINDEX ? '' : ' hidden' ?>>
    <svg class="be-icon be-noindex-banner__icon" width="22" height="22" aria-hidden="true"><use href="#icon-globe"/></svg>
    <div class="be-noindex-banner__text">
        <strong class="be-noindex-banner__title">Website für Suchmaschinen gesperrt</strong>
        <span class="be-noindex-banner__sub">noindex ist aktiv — Google &amp; Co. werden ausgesperrt. Vor dem Go-Live im Benutzermenü deaktivieren.</span>
    </div>
</div>
