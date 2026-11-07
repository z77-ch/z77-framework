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
    <h1 class="me-card__title"><?= e(t('member.totp.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.totp.lead')) ?>
    </p>

    <?php if ($error !== ''): ?>
    <p class="fe-form__error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" class="fe-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="fe-form__row">
            <label for="totp-code"><?= e(t('member.totp.code')) ?></label>
            <input id="totp-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]{6}" maxlength="6" required autofocus>
        </div>
        <button class="fe-form__submit" type="submit"><?= e(t('member.totp.submit')) ?></button>
    </form>

    <p class="me-card__aside">
        <?= e(t('member.totp.noApp')) ?> <a href="<?= e(localizedUrl('/member/main/login')) ?>"><?= e(t('member.totp.back')) ?></a> —
        <?= e(t('member.totp.lost')) ?>
    </p>
</div>
