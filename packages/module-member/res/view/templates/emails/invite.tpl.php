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
 * @var string $tenantName readable name of the project reference
 * @var string $inviter    name (or address) of the inviting account
 * @var string $inviteUrl
 * @var int    $validDays
 */
?>
<p>Guten Tag</p>

<p>
    <?= e($inviter) ?> hat Sie eingeladen, für
    <strong><?= e($tenantName) ?></strong> mitzuarbeiten.
</p>

<p>
    Über den folgenden Link richten Sie Ihren Zugang ein — Sie geben nur noch
    Ihren Namen an, Ihre E-Mail-Adresse ist durch diese Einladung bereits
    bestätigt:
</p>

<p>
    <a href="<?= e($inviteUrl) ?>"
       style="display:inline-block;padding:10px 20px;background-color:#222222;color:#ffffff;text-decoration:none;">
        Einladung annehmen
    </a>
</p>

<p>
    Falls die Schaltfläche nicht funktioniert, öffnen Sie diese Adresse in
    Ihrem Browser:<br>
    <a href="<?= e($inviteUrl) ?>"><?= e($inviteUrl) ?></a>
</p>

<p>
    Der Link ist <?= (int)$validDays ?> Tage gültig und nur einmal verwendbar.
    Nach Ihrer Anmeldung prüfen wir den Zugang und schalten ihn frei — Sie
    erhalten dann eine weitere E-Mail.
</p>

<p>
    Falls Sie diese Einladung nicht erwartet haben, ignorieren Sie diese
    E-Mail — ohne den Link entsteht kein Konto.
</p>
