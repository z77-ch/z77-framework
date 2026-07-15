<?php
/** @var string $mode */                                                  // 'form' | 'locked' | 'unavailable'
/** @var string|null $error */
/** @var string $username */
/** @var string $csrfToken */
/** @var \Z77\Shared\Validators\LoginUserValidator|null $validator */

$mode      = $mode      ?? 'form';
$error     = $error     ?? null;
$username  = $username  ?? 'admin';
$csrfToken = $csrfToken ?? '';
$validator = $validator ?? null;

$fieldError = function (string $name) use ($validator): string {
    return ($validator !== null && $validator->hasFieldError($name))
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
$invalid = function (string $name) use ($validator): string {
    return ($validator !== null && $validator->hasFieldError($name)) ? 'true' : 'false';
};
?>
<div class="login">
    <div class="login__box">
        <div class="login__logo" aria-hidden="true">z77</div>

        <?php if ($mode === 'locked'): ?>
            <h1 class="login__title">Setup abgeschlossen</h1>
            <p class="login__sub">Es existiert bereits ein Konto — das Setup ist gesperrt.</p>
            <a class="be-btn be-btn--primary be-btn--full" href="/login">Zur Anmeldung</a>

        <?php elseif ($mode === 'unavailable'): ?>
            <h1 class="login__title">Setup nicht verfügbar</h1>
            <p class="login__sub">Kein Setup-Token vorhanden. Das Setup ist nur direkt nach einer nicht-interaktiven Installation möglich.</p>
            <a class="be-btn be-btn--primary be-btn--full" href="/login">Zur Anmeldung</a>

        <?php else: ?>
            <h1 class="login__title">Erstes Setup</h1>
            <p class="login__sub">Administrator-Konto anlegen</p>

            <?php if ($error !== null): ?>
                <div class="login__error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/backend/system/setup/setup" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div class="be-form__group" data-z77-field-wrapper>
                    <label class="be-form__label" for="setup_token">Setup-Token</label>
                    <input
                        class="be-form__control"
                        type="text"
                        id="setup_token"
                        name="setup_token"
                        autofocus
                        required
                        placeholder="Inhalt von data/framework/auth/SETUP_TOKEN"
                    >
                </div>

                <div class="be-form__group" data-z77-field-wrapper>
                    <label class="be-form__label" for="username">Benutzername</label>
                    <input
                        class="be-form__control"
                        type="text"
                        id="username"
                        name="username"
                        value="<?= e($username) ?>"
                        required
                        aria-invalid="<?= $invalid('username') ?>"
                    >
                    <?= raw($fieldError('username')) ?>
                </div>

                <div class="be-form__group" data-z77-field-wrapper>
                    <label class="be-form__label" for="password">Passwort <small>(mind. 12 Zeichen empfohlen)</small></label>
                    <input
                        class="be-form__control"
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="new-password"
                        data-z77-password
                        data-z77-password-min="<?= e($passwordMinLength) ?>"
                        aria-invalid="<?= $invalid('password') ?>"
                    >
                    <div data-z77-password-meter></div>
                    <?= raw($fieldError('password')) ?>
                </div>

                <button class="be-btn be-btn--primary be-btn--full" type="submit">Konto erstellen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
