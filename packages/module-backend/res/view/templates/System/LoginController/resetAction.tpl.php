<?php
/** @var string $mode 'form' | 'invalid' */
/** @var string|null $error */
/** @var string $token */
/** @var int $passwordMinLength */
/** @var string $csrfToken */
/** @var \Z77\Shared\Validators\BackendUserValidator|null $validator */

$passwordError = $validator !== null && $validator->hasFieldError('password') ? $validator->getFieldError('password') : null;
?>
<div class="login">
    <div class="login__box">
        <?= $this->partial('partials/brandMark', ['class' => 'login__logo'], 'Z77\\Shared') ?>

        <?php if ($mode === 'invalid'): ?>
            <h1 class="login__title">Link nicht gültig</h1>
            <p class="login__sub">Dieser Link ist ungültig, abgelaufen oder wurde bereits verwendet.</p>
            <a class="be-btn be-btn--primary be-btn--full" href="/backend/system/login/forgot">Neuen Link anfordern</a>
        <?php else: ?>
            <h1 class="login__title">Neues Passwort</h1>
            <p class="login__sub">Mindestens <?= e($passwordMinLength) ?> Zeichen empfohlen.</p>

            <?php if ($error !== null): ?>
                <div class="login__error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="/backend/system/login/reset" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="be-form__group" data-z77-field-wrapper>
                    <label class="be-form__label" for="password">Neues Passwort</label>
                    <input
                        class="be-form__control"
                        type="password"
                        id="password"
                        name="password"
                        autofocus
                        required
                        autocomplete="new-password"
                        data-z77-password
                        data-z77-password-min="<?= e($passwordMinLength) ?>"
                        aria-invalid="<?= $passwordError !== null ? 'true' : 'false' ?>"
                    >
                    <div data-z77-password-meter></div>
                    <?php if ($passwordError !== null): ?>
                        <small class="be-form__field-error" data-z77-field-error><?= e($passwordError) ?></small>
                    <?php endif; ?>
                </div>
                <div class="be-form__group">
                    <label class="be-form__label" for="password_repeat">Passwort wiederholen</label>
                    <input
                        class="be-form__control"
                        type="password"
                        id="password_repeat"
                        name="password_repeat"
                        required
                        autocomplete="new-password"
                    >
                </div>
                <button class="be-btn be-btn--primary be-btn--full" type="submit">Passwort speichern</button>
            </form>
        <?php endif; ?>
    </div>
</div>
