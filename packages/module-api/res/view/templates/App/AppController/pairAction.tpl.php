<?php
/** Pairing (`/app/pair?code=…`, AppController::pairAction): the QR of the backend screen
 *  «Geräte» opens this page with the code filled in; typing it is the other way (and the way
 *  inside an installed app that keeps its own cookies). `_Z77.app.shell` sends it to
 *  `POST /api/v1/devices/pair` with `X-Z77-App: 1`; the answer sets the HttpOnly device cookie.
 *
 *  Without JavaScript the form only reloads this page (GET): a plain form cannot carry the header
 *  the device channel requires (ADR-057 decision 3) — the notice says so instead of ending in a
 *  JSON 403. The code is never auto-submitted: a link preview must not burn a one-time code. */
/** @var string $code the code from the address, shape-checked, '' when none */
?>
<section class="za-pad za-stack">
    <h1 class="za-h1">Handy koppeln</h1>
    <p>Im Backend unter <strong>Geräte</strong> «+ Gerät koppeln» wählen. Dann den QR-Code mit der Kamera scannen — oder den Code hier eintippen:</p>
    <form class="za-stack" method="get" action="/app/pair" data-app-pair novalidate>
        <label class="za-field">
            <span class="za-label">Code</span>
            <input class="za-input za-code" name="code" value="<?= e($code) ?>" required maxlength="24"
                   autocomplete="one-time-code" autocapitalize="characters" autocorrect="off" spellcheck="false">
        </label>
        <p class="za-error" data-error-for="code" role="alert" hidden></p>
        <button type="submit" class="za-primary" data-app-pair-submit>Koppeln</button>
    </form>
    <p class="za-small za-muted">Der Code gilt 10 Minuten und nur einmal. Kein Passwort auf dem Handy.</p>
    <noscript>
        <p class="za-notice">Koppeln braucht JavaScript. Bitte JavaScript im Browser einschalten.</p>
    </noscript>
</section>
