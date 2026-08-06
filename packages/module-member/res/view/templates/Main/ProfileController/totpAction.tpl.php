<?php
/**
 * 2FA setup page (B8): QR (server-rendered data URI) + manual key fallback +
 * the confirming code form — 2FA is active only after a valid code.
 *
 * @var string $pageTitle
 * @var string $qrDataUri
 * @var string $secret   Base32, grouped for hand-typing
 * @var string $error
 * @var string $csrfToken
 */
?>
<div class="me-card">
    <h1 class="me-card__title">Zwei-Faktor-Schutz einrichten</h1>
    <p class="me-card__lead">
        Scannen Sie den QR-Code mit Ihrer Authenticator-App (z.&nbsp;B. Google
        Authenticator, Aegis, 2FAS) und bestätigen Sie mit dem angezeigten Code.
    </p>

    <p style="text-align:center">
        <img src="<?= e($qrDataUri) ?>" alt="QR-Code für die Authenticator-App" width="220" height="220">
    </p>

    <p class="me-card__aside">
        Ohne Kamera: Schlüssel von Hand eingeben —
        <code style="user-select:all"><?= e($secret) ?></code>
    </p>

    <?php if ($error !== ''): ?>
    <p class="fe-form__error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="fe-form__row">
            <label for="totp-code">Code aus der App</label>
            <input id="totp-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" required autofocus>
        </div>
        <button class="fe-form__submit" type="submit">Aktivieren</button>
    </form>

    <p class="me-card__aside"><a href="/member/main/profile">Abbrechen</a></p>
</div>
