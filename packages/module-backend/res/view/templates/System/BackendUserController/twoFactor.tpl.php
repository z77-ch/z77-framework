<?php
/**
 * The signed-in user's own second factor (login.md BACKEND-2FA-001). Two shapes: off → QR, key,
 * password and code; on → the code that switches it off. Posts by fetch; a wrong field answers in
 * place. The fields are named `tf_*` because the page behind may carry a `password` field of its
 * own, and the envelope's field marking searches the document.
 *
 * @var bool   $active
 * @var string $activatedAt  ISO 8601, '' when off
 * @var string $qrSvg        from Z77\Shared\Qr\QrCode, only when off
 * @var string $secret       Base32 in groups of four, only when off
 */
?>
<?php if ($active): ?>
<form data-fetch-post="/backend/system/backend-user/two-factor-disable">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Zwei-Faktor-Schutz entfernen</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Entfernen', 'kind' => 'danger'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <p>Der Schutz ist aktiv seit <?= e(date('d.m.Y', (int) strtotime($activatedAt))) ?>. Zum Entfernen brauchst du einen gültigen Code aus der App. Danach meldest du dich nur noch mit dem Passwort an.</p>
        <div class="be-form__field" data-z77-field-wrapper>
            <label for="tf-code">Code aus der App</label>
            <input type="text" id="tf-code" name="tf_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
        </div>
    </div>
</form>
<?php else: ?>
<form data-fetch-post="/backend/system/backend-user/two-factor-enable">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Zwei-Faktor-Schutz einrichten</h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Aktivieren'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <p>Die Anmeldung fragt dann zusätzlich nach einem Code aus deiner Authenticator-App (z.B. Google Authenticator, Aegis, 2FAS). Scanne den QR-Code und bestätige mit Passwort und Code. Bis dahin ändert sich nichts.</p>
        <div class="be-form__qr"><?= raw($qrSvg) ?></div>
        <p class="be-form__hint">Ohne Kamera: Schlüssel von Hand eingeben: <code class="be-form__secret"><?= e($secret) ?></code></p>
        <p class="be-form__hint">Bei einem verlorenen Gerät kann ein Super-User den Schutz zurücksetzen.</p>
        <div class="be-form__grid" style="grid-template-columns:1fr 1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="tf-password">Dein Passwort</label>
                <input type="password" id="tf-password" name="tf_password" autocomplete="current-password" required>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="tf-code">Code aus der App</label>
                <input type="text" id="tf-code" name="tf_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>
