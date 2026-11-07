<?php
/**
 * Magic-link login mail BODY (B8, spec 1.1.0): the one-time link (15 min) —
 * and the check digits plus the context of the request, so the reader can
 * decide BEFORE clicking whether this login is theirs.
 *
 * Wording rule for this mail: say where the number comes from (the screen
 * where the login was requested), say what to do, and say what to do when the
 * numbers differ. No sentence may leave open which of the two the reader
 * should compare.
 *
 * Texts: `member.mail.login.*` in the module dictionary (res/i18n), rendered in
 * the account's language (EmailMessage::language(), ADR-056).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $loginUrl
 * @var string $digits  four digits, also in the subject
 * @var string $device  browser/system of the requesting device
 * @var string $when    when it was requested, formatted
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p><?= e($name !== '' ? t('member.mail.greetingName', ['name' => $name]) : t('member.mail.greeting')) ?></p>

<p><?= e(t('member.mail.login.requested')) ?></p>

<p>
    <strong style="font-size:20px;letter-spacing:3px;"><?= e(t('member.mail.login.digits', ['digits' => $digits])) ?></strong><br>
    <?= e(t('member.mail.login.when', ['when' => $when, 'device' => $device])) ?>
</p>

<p>
    <strong><?= e(t('member.mail.login.compareHead')) ?></strong> <?= e(t('member.mail.login.compare')) ?>
</p>

<p>
    <a href="<?= e($loginUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        <?= e(t('member.mail.login.button')) ?>
    </a>
</p>

<p>
    <?= e(t('member.mail.login.where')) ?>
</p>

<p>
    <?= e(t('member.mail.buttonFallback')) ?><br>
    <a href="<?= e($loginUrl) ?>"><?= e($loginUrl) ?></a>
</p>

<p>
    <strong><?= e(t('member.mail.login.strangerHead')) ?></strong> <?= e(t('member.mail.login.stranger')) ?>
</p>

<p>
    <?= e(t('member.mail.login.validity')) ?>
</p>
