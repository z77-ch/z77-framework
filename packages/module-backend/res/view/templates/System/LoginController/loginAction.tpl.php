<?php
/** @var string|null $error */
/** @var string $username */
/** @var string $csrfToken */
?>
<div class="login">
    <div class="login__box">
        <div class="login__logo" aria-hidden="true">z77</div>
        <h1 class="login__title">Willkommen zurück</h1>
        <p class="login__sub">z77 Backend</p>

        <?php if ($error !== null): ?>
            <div class="login__error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/backend/system/login/login" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div class="be-form__group">
                <label class="be-form__label" for="username">Benutzername</label>
                <input
                    class="be-form__control"
                    type="text"
                    id="username"
                    name="username"
                    value="<?= e($username) ?>"
                    autofocus
                    required
                >
            </div>
            <div class="be-form__group">
                <label class="be-form__label" for="password">Passwort</label>
                <input
                    class="be-form__control"
                    type="password"
                    id="password"
                    name="password"
                    required
                >
            </div>
            <button class="be-btn be-btn--primary be-btn--full" type="submit">Anmelden</button>
        </form>
    </div>
</div>
