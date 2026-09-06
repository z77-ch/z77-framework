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
 * @var string $tenantName readable name of the project reference
 * @var string $inviter    name (or address) of the inviting account
 * @var string $inviteUrl
 * @var int    $validDays
 * @var bool   $known      the invited address already has an account
 */
$known = (bool)($known ?? false);
?>
<p>Guten Tag</p>

<p>
    <?= e($inviter) ?> hat Sie eingeladen, für
    <strong><?= e($tenantName) ?></strong> mitzuarbeiten.
</p>

<?php if ($known): ?>
<p>
    Zu Ihrer E-Mail-Adresse besteht bereits ein Konto. Über den folgenden Link
    fügen Sie diese Verwaltung als weiteren Mandanten hinzu — Ihr Konto, Ihre
    Anmeldung und Ihre Geräte bleiben, wie sie sind:
</p>
<?php else: ?>
<p>
    Über den folgenden Link richten Sie Ihren Zugang ein — Sie geben nur noch
    Ihren Namen an, Ihre E-Mail-Adresse ist durch diese Einladung bereits
    bestätigt:
</p>
<?php endif; ?>

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
    E-Mail — ohne den Link <?= $known ? 'ändert sich an Ihrem Konto nichts' : 'entsteht kein Konto' ?>.
</p>
