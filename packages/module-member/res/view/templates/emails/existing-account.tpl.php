<?php
/**
 * "You already have an account" mail BODY (B7): sent instead of a second
 * confirmation when a registration or resend names an address that already
 * has a confirmed/active account. The web page stays neutral; this mail is
 * the only place the existing account is mentioned — visible only to the
 * address owner.
 *
 * Texts: `member.mail.*` in the module dictionary (res/i18n), rendered in the
 * recipient's language (EmailMessage::language(), ADR-056).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p><?= e($name !== '' ? t('member.mail.greetingName', ['name' => $name]) : t('member.mail.greeting')) ?></p>

<p>
    <?= e(t('member.mail.existing.lead')) ?>
</p>

<p>
    <?= e(t('member.mail.existing.body')) ?>
</p>
