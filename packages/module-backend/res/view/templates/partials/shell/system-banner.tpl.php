<?php
/** Installation-configuration Störer (ADR-030).
 *  Shown when a systemConfig value that has no meaningful default is missing —
 *  today exactly one: the canonical base URL. Without it every mail link and the
 *  SEO canonical would have to be guessed from the request, which is precisely
 *  what SEC-005 forbids, so the code throws instead. The band is what turns that
 *  into something an operator sees BEFORE the first mail fails.
 *
 *  Unlike the noindex band there is no live toggle, so it is rendered only when
 *  it applies rather than sitting hidden in the DOM.
 *
 *  @var array{initials:string,name:string,role:string}|null $headerUser
 */
if (empty($headerUser)) return;                                   // no chrome for guests (mirrors topbar)
if (defined('CANONICAL_BASE_URL') && CANONICAL_BASE_URL !== '') return;
?>
<div class="be-shell-banner" role="alert">
    <svg class="be-icon be-shell-banner__icon" width="22" height="22" aria-hidden="true"><use href="#icon-globe"/></svg>
    <div class="be-shell-banner__text">
        <strong class="be-shell-banner__title">Adresse dieser Installation fehlt</strong>
        <span class="be-shell-banner__sub">
            In <code>config/systemConfig.inc.php</code> ist <code>canonicalBaseUrl</code> leer.
            Anmelde-Mails und die SEO-Canonical lassen sich nicht erzeugen, solange dort nicht
            die Adresse steht, unter der diese Installation läuft (z.&nbsp;B. <code>https://kunde.ch</code>).
        </span>
    </div>
</div>
