<?php
/**
 * PRG target of the registration — the confirmation is a page, not a session
 * flag. NEUTRAL by spec: the same page answers a new registration and a
 * repeated one with an existing address; what differs is only the mail that
 * went out. The wording must not reveal which case occurred.
 *
 * A redeemed INVITATION lands here too (B7 v1.1.0) and says something else,
 * because the two premises differ: there is no confirmation mail to wait for
 * (the invitation was the verification), and pointing at the resend page would
 * be wrong — that page cannot renew an invitation.
 *
 * A GRANT (ADR-037) is the invitation's third wording: the person already has
 * a login, so what follows the activation mail is choosing the tenant, not
 * signing in for the first time. And a DECLINED one says that nothing changed.
 *
 * @var string $pageTitle
 * @var bool   $fromInvite
 * @var bool   $fromJoin
 * @var bool   $declined
 */
$fromInvite = (bool)($fromInvite ?? false);
$fromJoin   = (bool)($fromJoin ?? false);
$declined   = (bool)($declined ?? false);
$noun       = \Z77\Module\Member\Services\TenantNoun::current(); // the word for the tenant (memberConfig tenantNoun), as placeholders of this language
?>
<div class="me-card">
<?php if ($declined): ?>
    <h1 class="me-card__title"><?= e(t('member.thanks.declinedTitle')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.thanks.declined', $noun)) ?>
    </p>
<?php elseif ($fromJoin): ?>
    <h1 class="me-card__title"><?= e(t('member.thanks.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.thanks.join', $noun)) ?>
    </p>
    <p class="me-card__aside"><a href="<?= e(localizedUrl('/member/main/login')) ?>"><?= e(t('member.invite.toLogin')) ?></a></p>
<?php elseif ($fromInvite): ?>
    <h1 class="me-card__title"><?= e(t('member.thanks.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.thanks.invite')) ?>
    </p>
<?php else: ?>
    <h1 class="me-card__title"><?= e(t('member.thanks.title')) ?></h1>
    <p class="me-card__lead">
        <?= e(t('member.thanks.register')) ?>
    </p>
    <p class="me-card__aside">
        <?= e(t('member.thanks.noMail')) ?>
        <a href="<?= e(localizedUrl('/member/main/resend')) ?>"><?= e(t('member.thanks.resend')) ?></a>.
    </p>
<?php endif; ?>
</div>
