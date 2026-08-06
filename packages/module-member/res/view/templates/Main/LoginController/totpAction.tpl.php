<?php
/**
 * 2FA interstitial (B8): the link was valid, the session waits for the app
 * code. Plain form — no framework JS needed.
 *
 * @var string $pageTitle
 * @var string $error
 * @var string $csrfToken
 */
?>
<div class="me-card">
    <h1 class="me-card__title">Code eingeben</h1>
    <p class="me-card__lead">
        Ihr Konto ist mit Zwei-Faktor-Schutz gesichert — geben Sie den
        6-stelligen Code aus Ihrer Authenticator-App ein.
    </p>

    <?php if ($error !== ''): ?>
    <p class="fe-form__error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="fe-form__row">
            <label for="totp-code">Code</label>
            <input id="totp-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" required autofocus>
        </div>
        <button class="fe-form__submit" type="submit">Anmelden</button>
    </form>

    <p class="me-card__aside">
        Kein Zugriff auf die App? <a href="/member/main/login">Zurück zur Anmeldung</a> —
        bei verlorenem Gerät setzen wir den Schutz für Sie zurück.
    </p>
</div>
