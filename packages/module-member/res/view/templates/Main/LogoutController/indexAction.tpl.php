<?php
/**
 * The way out when a click cannot sign out by itself (security.md SEC-GET-001): a stale tab's token,
 * a project's old link, a bookmark. The fresh token comes with this page; only its button acts.
 *
 * @var bool $stale a POST whose token did not fit
 */
?>
<div class="me-card">
    <h1 class="me-card__title"><?= e(t('member.logout.title')) ?></h1>
    <p class="me-card__lead"><?= e(t(!empty($stale) ? 'member.logout.stale' : 'member.logout.confirm')) ?></p>
    <form method="post" action="<?= e(localizedUrl('/member/main/logout')) ?>" class="fe-form" novalidate>
        <?= $this->partial('partials/csrfField', [], 'Z77\Shared') ?>
        <button class="fe-form__submit" type="submit" autofocus><?= e(t('member.logout.button')) ?></button>
    </form>
    <p class="me-card__aside"><a href="<?= e(localizedUrl('/member/main/login')) ?>"><?= e(t('member.logout.cancel')) ?></a></p>
</div>
