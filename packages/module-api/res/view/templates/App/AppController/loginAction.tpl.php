<?php
/** The code sign-in of an app user (`/app/login`, AppController::loginAction, ADR-058): two steps on
 *  one page. Step 1 — the app e-mail → `POST /api/v1/devices/login` (always the same answer, for
 *  every address); step 2 — the code from the mail → `POST /api/v1/devices/verify`, which sets the
 *  HttpOnly device cookie. `_Z77.app.shell` runs both with `X-Z77-App: 1`; the code is typed, never
 *  a link — it works in whichever browser the app is open in, and a mail scanner cannot use it up.
 *
 *  The texts are the same for every address and every failure (no oracle, no tries counter).
 *  Without JavaScript the forms only reload this page (GET): a plain form cannot carry the header
 *  the device channel requires (ADR-057 decision 3) — the notice says so. Nothing from the address
 *  bar is put into the page. */
?>
<section class="za-pad za-stack" data-app-login>
    <h1 class="za-h1">Anmelden</h1>

    <form class="za-stack" method="get" action="/app/login" data-app-login-email novalidate>
        <p>Deine App-E-Mail eingeben. Du bekommst einen Code per E-Mail — kein Passwort.</p>
        <label class="za-field">
            <span class="za-label">App-E-Mail</span>
            <input class="za-input" type="email" name="email" required maxlength="254"
                   autocomplete="email" autocapitalize="off" autocorrect="off" spellcheck="false" inputmode="email">
        </label>
        <p class="za-error" data-error-for="email" role="alert" hidden></p>
        <button type="submit" class="za-primary" data-app-login-send>Code anfordern</button>
    </form>

    <form class="za-stack" method="get" action="/app/login" data-app-login-code hidden novalidate>
        <p data-app-login-sent>Wenn diese Adresse für die App freigeschaltet ist, kommt in Kürze ein Code per E-Mail. Er gilt 15 Minuten.</p>
        <label class="za-field">
            <span class="za-label">Code aus der E-Mail</span>
            <input class="za-input za-code" name="code" required maxlength="6" pattern="[0-9]*"
                   inputmode="numeric" autocomplete="one-time-code" autocorrect="off" spellcheck="false">
        </label>
        <p class="za-error" data-error-for="code" role="alert" hidden></p>
        <button type="submit" class="za-primary" data-app-login-verify>Anmelden</button>
        <button type="button" class="za-link" data-app-login-again>Neuen Code anfordern</button>
    </form>

    <p class="za-small za-muted">Gib den Code niemandem weiter. Niemand von z77 fragt dich danach.</p>
    <noscript>
        <p class="za-notice">Die Anmeldung braucht JavaScript. Bitte JavaScript im Browser einschalten.</p>
    </noscript>
</section>
