<?php
/**
 * PRG target of the registration — the confirmation is a page, not a session
 * flag. NEUTRAL by spec: the same page answers a new registration and a
 * repeated one with an existing address; what differs is only the mail that
 * went out. The wording must not reveal which case occurred.
 *
 * A redeemed INVITATION lands here too (B7 v1.1.0) and says something else,
 * because the two premises differ: there is no confirmation mail to wait for
 * (the invitation was the verification), and pointing at the resend page would
 * be wrong — that page cannot renew an invitation.
 *
 * A GRANT (ADR-037) is the invitation's third wording: the person already has
 * a login, so what follows the activation mail is choosing the tenant, not
 * signing in for the first time. And a DECLINED one says that nothing changed.
 *
 * @var string $pageTitle
 * @var bool   $fromInvite
 * @var bool   $fromGrant
 * @var bool   $declined
 */
$fromInvite = (bool)($fromInvite ?? false);
$fromGrant  = (bool)($fromGrant ?? false);
$declined   = (bool)($declined ?? false);
?>
<div class="me-card">
<?php if ($declined): ?>
    <h1 class="me-card__title">Einladung abgelehnt</h1>
    <p class="me-card__lead">
        Es ist nichts geschehen — Ihr Konto ist unverändert, und der Link aus
        der Einladung wirkt nicht mehr. Falls Sie es sich anders überlegen,
        kann die Verwaltung Sie erneut einladen.
    </p>
<?php elseif ($fromGrant): ?>
    <h1 class="me-card__title">Vielen Dank</h1>
    <p class="me-card__lead">
        Die Verwaltung ist Ihrem Konto hinzugefügt und wartet auf die
        Freischaltung. Sie erhalten eine E-Mail, sobald es so weit ist — danach
        wählen Sie nach der Anmeldung, für welche Verwaltung Sie arbeiten.
    </p>
    <p class="me-card__aside"><a href="/member/main/login">Zur Anmeldung</a></p>
<?php elseif ($fromInvite): ?>
    <h1 class="me-card__title">Vielen Dank</h1>
    <p class="me-card__lead">
        Ihr Zugang ist beantragt. Ihre E-Mail-Adresse ist durch die Einladung
        bereits bestätigt — wir prüfen den Zugang und schalten ihn frei. Sie
        erhalten eine E-Mail, sobald es so weit ist.
    </p>
<?php else: ?>
    <h1 class="me-card__title">Vielen Dank</h1>
    <p class="me-card__lead">
        Wir haben Ihre Angaben erhalten und Ihnen eine E-Mail geschickt.
        Bitte folgen Sie dem Link darin.
    </p>
    <p class="me-card__aside">
        Keine E-Mail erhalten? Prüfen Sie den Spam-Ordner oder
        <a href="/member/main/resend">fordern Sie den Link erneut an</a>.
    </p>
<?php endif; ?>
</div>
