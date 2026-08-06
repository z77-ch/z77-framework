<?php
/**
 * Confirmation mail BODY (B7): carries the one-time link — the only place the
 * token plaintext ever exists. Rendered inside the shared emails/layout;
 * plain-text derivation per HtmlToText contract (block tags → line breaks).
 *
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var string $confirmUrl
 */
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
?>
<p>Guten Tag<?= $name !== '' ? ' ' . e($name) : '' ?></p>

<p>
    Vielen Dank für Ihre Registrierung. Bitte bestätigen Sie Ihre
    E-Mail-Adresse über den folgenden Link:
</p>

<p>
    <a href="<?= e($confirmUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        E-Mail-Adresse bestätigen
    </a>
</p>

<p>
    Falls die Schaltfläche nicht funktioniert, öffnen Sie diese Adresse in
    Ihrem Browser:<br>
    <a href="<?= e($confirmUrl) ?>"><?= e($confirmUrl) ?></a>
</p>

<p>
    Der Link ist 7 Tage gültig. Nach der Bestätigung prüfen wir Ihre
    Registrierung und schalten Ihren Zugang frei — Sie erhalten dann eine
    weitere E-Mail.
</p>

<p>
    Falls Sie sich nicht registriert haben, ignorieren Sie diese E-Mail —
    es entsteht kein Konto ohne diese Bestätigung.
</p>
