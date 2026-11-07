<?php
/**
 * Confirmation mail BODY (B7): carries the one-time link — the only place the
 * token plaintext ever exists. Rendered inside the shared emails/layout;
 * plain-text derivation per HtmlToText contract (block tags → line breaks).
 *
 * Texts: `member.mail.*` in the module dictionary (res/i18n), rendered in the
 * recipient's language (EmailMessage::language(), ADR-056).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $confirmUrl
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p><?= e($name !== '' ? t('member.mail.greetingName', ['name' => $name]) : t('member.mail.greeting')) ?></p>

<p>
    <?= e(t('member.mail.confirm.lead')) ?>
</p>

<p>
    <a href="<?= e($confirmUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        <?= e(t('member.mail.confirm.button')) ?>
    </a>
</p>

<p>
    <?= e(t('member.mail.buttonFallback')) ?><br>
    <a href="<?= e($confirmUrl) ?>"><?= e($confirmUrl) ?></a>
</p>

<p>
    <?= e(t('member.mail.confirm.validity')) ?>
</p>

<p>
    <?= e(t('member.mail.confirm.ignore')) ?>
</p>
