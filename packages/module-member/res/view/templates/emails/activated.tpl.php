<?php
/**
 * Activation mail BODY (B7): sent by the backend activation step («Sie sind
 * freigeschaltet»). Rejection sends NO automatic mail by spec — what we
 * write, we write ourselves.
 *
 * Texts: `member.mail.*` in the module dictionary (res/i18n), rendered in the
 * recipient's language (EmailMessage::language(), ADR-056).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $loginUrl absolute URL of the member entry point
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p><?= e($name !== '' ? t('member.mail.greetingName', ['name' => $name]) : t('member.mail.greeting')) ?></p>

<p>
    <?= e(t('member.mail.activated.lead')) ?>
</p>

<p>
    <a href="<?= e($loginUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        <?= e(t('member.mail.toLogin')) ?>
    </a>
</p>

<p>
    <?= e(t('member.mail.buttonFallback')) ?><br>
    <a href="<?= e($loginUrl) ?>"><?= e($loginUrl) ?></a>
</p>
