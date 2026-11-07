<?php
/**
 * Invitation mail BODY (B7 v1.1.0): carries the one-time link — the only place
 * the token plaintext ever exists. Rendered inside the shared emails/layout;
 * plain-text derivation per HtmlToText contract (block tags → line breaks).
 *
 * It names WHO invited and FOR WHOM, because the recipient did not ask for
 * this mail: an invitation without both reads like spam, and a link in a mail
 * that reads like spam is one nobody clicks.
 *
 * The page behind the link has two shapes (ADR-037), and the mail says which
 * one awaits: a NAME for a new account, or a YES for adding the tenant to the
 * account this address already has. `$known` is told to the recipient only —
 * it is his own account, and the inviting master never learns it.
 *
 * Texts: `member.mail.invite.*` in the module dictionary (res/i18n), rendered in
 * the language the inviter chose (ADR-056). A `…Html` key is markup: its
 * placeholders are escaped HERE, the sentence goes out raw.
 *
 * @var string $tenantName   readable name of the project reference
 * @var string $inviter      name (or address) of the inviting account
 * @var string $inviteUrl
 * @var int    $validDays
 * @var bool   $known        the invited address already has an account
 * @var string $mailLanguage the language this mail renders in (EmailService)
 */
$known = (bool)($known ?? false);
// the word for the tenant (memberConfig tenantNoun), in the forms this language's sentence needs
$noun  = \Z77\Module\Member\Services\TenantNoun::params($mailLanguage ?? 'de');
?>
<p><?= e(t('member.mail.greeting')) ?></p>

<p>
    <?= raw(t('member.mail.invite.leadHtml', [
        'inviter' => e($inviter),
        'tenant'  => '<strong>' . e($tenantName) . '</strong>',
    ])) ?>
</p>

<p>
    <?= e(t($known ? 'member.mail.invite.known' : 'member.mail.invite.new', $noun + ['tenant' => $tenantName])) ?>
</p>

<p>
    <a href="<?= e($inviteUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        <?= e(t('member.mail.invite.button')) ?>
    </a>
</p>

<p>
    <?= e(t('member.mail.buttonFallback')) ?><br>
    <a href="<?= e($inviteUrl) ?>"><?= e($inviteUrl) ?></a>
</p>

<p>
    <?= e(t('member.mail.invite.validity', ['days' => (int)$validDays])) ?>
</p>

<p>
    <?= e(t($known ? 'member.mail.invite.ignoreKnown' : 'member.mail.invite.ignoreNew')) ?>
</p>
