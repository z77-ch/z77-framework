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
    <h1 class="me-card__title"><?= e(t('member.totpSetup.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.totpSetup.lead')) ?>
    </p>

    <p style="text-align:center">
        <img src="<?= e($qrDataUri) ?>" alt="<?= e(t('member.totpSetup.qrAlt')) ?>" width="220" height="220">
    </p>

    <p class="me-card__aside">
        <?= e(t('member.totpSetup.manual')) ?>
        <code style="user-select:all"><?= e($secret) ?></code>
    </p>

    <?php if ($error !== ''): ?>
    <p class="fe-form__error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="fe-form__row">
            <label for="totp-code"><?= e(t('member.totpSetup.code')) ?></label>
            <input id="totp-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" required autofocus>
        </div>
        <button class="fe-form__submit" type="submit"><?= e(t('member.totpSetup.activate')) ?></button>
    </form>

    <p class="me-card__aside"><a href="<?= e(localizedUrl('/member/main/profile')) ?>"><?= e(t('member.logout.cancel')) ?></a></p>
</div>
