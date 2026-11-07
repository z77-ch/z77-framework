<?php
/**
 * "No account here" mail BODY (B8 spec, Kontolage-Tabelle): a login was
 * requested for an address without an account. The web page stays neutral;
 * only the address owner learns there is nothing — with the way to change
 * that.
 *
 * Texts: `member.mail.*` in the module dictionary (res/i18n). No account, no
 * language of its own: it renders in the language of the page that asked.
 *
 * @var string $registerUrl
 */
?>
<p><?= e(t('member.mail.greeting')) ?></p>

<p>
    <?= e(t('member.mail.noAccount.lead')) ?>
</p>

<p>
    <?= e(t('member.mail.noAccount.register')) ?><br>
    <a href="<?= e($registerUrl) ?>"><?= e($registerUrl) ?></a>
</p>

<p>
    <?= e(t('member.mail.ignore')) ?>
</p>
