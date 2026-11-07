<?php
/**
 * Join activation mail BODY (ADR-037/038): the project activated a membership
 * — an EXISTING account may now work for an additional tenant. Sent by the
 * project through {@see \Z77\Module\Member\Services\InvitationFlow::sendJoinActivated()}. Same event from the
 * customer's chair as «Sie sind freigeschaltet», with one difference worth a
 * sentence: he signs in as always and CHOOSES the tenant in the header.
 * Rejection sends NO automatic mail, as with accounts.
 *
 * Texts: `member.mail.*` in the module dictionary (res/i18n), rendered in the
 * recipient's language (EmailMessage::language(), ADR-056). A `…Html` key is
 * markup: its placeholders are escaped HERE, the sentence goes out raw.
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $tenantName   readable name of the added tenant
 * @var string $loginUrl     absolute URL of the member entry point
 * @var string $mailLanguage the language this mail renders in (EmailService)
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
// the word for the tenant (memberConfig tenantNoun), in the forms this language's sentence needs
$noun = \Z77\Module\Member\Services\TenantNoun::params($mailLanguage ?? 'de');
?>
<p><?= e($name !== '' ? t('member.mail.greetingName', ['name' => $name]) : t('member.mail.greeting')) ?></p>

<p>
    <?= raw(t('member.mail.joinActivated.leadHtml', array_map('e', $noun) + [
        'tenant' => '<strong>' . e($tenantName) . '</strong>',
    ])) ?>
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
