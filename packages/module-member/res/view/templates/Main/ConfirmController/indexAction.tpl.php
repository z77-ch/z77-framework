<?php
/**
 * Confirmation landing (B7): renders one of the three flow outcomes. 'dead'
 * covers unknown, expired and used-while-unconfirmed links with ONE wording
 * and the resend way out — the page never explains which kind of dead.
 *
 * @var string $pageTitle
 * @var string $outcome  'confirmed' | 'already' | 'dead'
 */

use Z77\Module\Member\Services\RegistrationFlow;
?>
<div class="me-card">
<?php if ($outcome === RegistrationFlow::CONFIRMED): ?>
    <h1 class="me-card__title"><?= e(t('member.confirm.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.confirm.lead')) ?>
    </p>
<?php elseif ($outcome === RegistrationFlow::ALREADY): ?>
    <h1 class="me-card__title"><?= e(t('member.confirm.alreadyTitle')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.confirm.already')) ?>
    </p>
<?php else: ?>
    <h1 class="me-card__title"><?= e(t('member.confirm.deadTitle')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.confirm.dead')) ?>
    </p>
    <p class="me-card__aside">
        <a href="<?= e(localizedUrl('/member/main/resend')) ?>"><?= e(t('member.confirm.resend')) ?></a>
    </p>
<?php endif; ?>
</div>
