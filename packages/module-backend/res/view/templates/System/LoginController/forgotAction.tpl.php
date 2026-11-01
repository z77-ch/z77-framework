<?php
/** @var string|null $error */
/** @var string|null $notice the one neutral answer after a request; null = show the form */
/** @var string $identifier */
/** @var string $csrfToken */
?>
<div class="login">
    <div class="login__box">
        <?= $this->partial('partials/brandMark', ['class' => 'login__logo'], 'Z77\\Shared') ?>
        <h1 class="login__title">Passwort vergessen</h1>

        <?php if ($notice !== null): ?>
            <div class="login__notice" role="status"><?= e($notice) ?></div>
        <?php else: ?>
            <p class="login__sub">Gib deinen Benutzernamen oder deine E-Mail-Adresse ein. Ist bei deinem Konto keine E-Mail-Adresse hinterlegt, wende dich an den Administrator.</p>

            <?php if ($error !== null): ?>
                <div class="login__error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/backend/system/login/forgot" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <div class="be-form__group">
                    <label class="be-form__label" for="identifier">Benutzername oder E-Mail</label>
                    <input
                        class="be-form__control"
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= e($identifier) ?>"
                        autofocus
                        required
                    >
                </div>
                <button class="be-btn be-btn--primary be-btn--full" type="submit">Link anfordern</button>
            </form>
        <?php endif; ?>

        <p class="login__after"><a href="/login">Zurück zur Anmeldung</a></p>
    </div>
</div>
