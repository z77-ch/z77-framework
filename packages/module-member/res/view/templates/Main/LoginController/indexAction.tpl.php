<?php
/**
 * Login request page (B8): one field, magic link — no password, ever.
 *
 * @var string $pageTitle
 * @var \Z77\Shared\Forms\PublicForm $form
 * @var array<string,array>  $fields
 * @var array<string,string> $errors
 * @var string $formError
 * @var string $checkUrl
 * @var string $csrfToken
 */
?>
<div class="me-card">
    <h1 class="me-card__title"><?= e(t('member.login.title')) ?></h1>
    <?php if (!empty($deleted)): ?>
    <div class="me-band me-band--info">
        <span class="me-band__dot" aria-hidden="true"></span>
        <span class="me-band__text"><?= e(t('member.login.deleted')) ?></span>
    </div>
    <?php endif; ?>
    <p class="me-card__lead">
        <?= e(t('member.login.lead')) ?>
    </p>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>

    <p class="me-card__aside">
        <?= e(t('member.login.noAccount')) ?> <a href="<?= e(localizedUrl('/member/main/register')) ?>"><?= e(t('member.login.register')) ?></a>
    </p>
</div>
