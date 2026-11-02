<?php
/**
 * The second step of a login with 2FA (login.md BACKEND-2FA-001): the password was right, the
 * session has no identity yet.
 *
 * @var string|null $error
 * @var string $csrfToken
 */
?>
<div class="login">
    <div class="login__box">
        <?= $this->partial('partials/brandMark', ['class' => 'login__logo'], 'Z77\\Shared') ?>
        <h1 class="login__title">Code eingeben</h1>
        <p class="login__sub">Den 6-stelligen Code findest du in deiner Authenticator-App.</p>

        <?php if ($error !== null): ?>
            <div class="login__error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/backend/system/login/code" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="be-form__group">
                <label class="be-form__label" for="code">Code</label>
                <input
                    class="be-form__control"
                    type="text"
                    id="code"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autofocus
                    required
                >
            </div>
            <button class="be-btn be-btn--primary be-btn--full" type="submit">Anmelden</button>
        </form>
        <p class="login__after">Gerät verloren? Ein Administrator kann den Schutz zurücksetzen.</p>
        <p class="login__after"><a href="/login">Zurück zur Anmeldung</a></p>
    </div>
</div>
