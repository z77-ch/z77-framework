<?php
/**
 * Waiting page (B8 stage D): the neutral answer of the request form — plus
 * the check digits this request is waiting under.
 *
 * Wording rule for this page: the number belongs to THIS screen. Say that the
 * same number is in the mail (subject line included), say what happens after
 * the confirmation, and say what a mismatch means. Nothing here may be
 * readable as «the number is only over there».
 *
 * @var string $pageTitle
 * @var string $digits  four digits, or '' when nothing is waiting here
 */
?>
<div class="me-card" data-login-wait>
    <h1 class="me-card__title">Anmeldung angefordert</h1>
    <p class="me-card__lead">
        Falls zu dieser Adresse ein Konto besteht, ist eine E-Mail unterwegs.
        Sie können sie hier oder auf einem anderen Gerät öffnen — zum Beispiel
        auf dem Handy.
    </p>

    <?php if ($digits !== ''): ?>
    <p class="me-check">
        Prüfzahl dieser Anmeldung: <strong class="me-check__digits"><?= e($digits) ?></strong>
    </p>
    <p class="me-card__note">
        Diese Zahl steht auch in der E-Mail — im Betreff und im Text.
        Stimmen die beiden Zahlen überein, ist es Ihre Anmeldung: Bestätigen
        Sie sie in der E-Mail, und dieses Gerät hier meldet sich automatisch an.
        Lassen Sie diese Seite so lange offen.
    </p>
    <p class="me-card__note">
        Zeigt die E-Mail eine <strong>andere</strong> Zahl, gehört sie zu einer
        anderen Anmeldung — bestätigen Sie sie dann nicht.
    </p>
    <p class="me-card__note" data-login-wait-note>Warte auf die Bestätigung …</p>
    <?php endif; ?>

    <p class="me-card__aside">
        Keine E-Mail erhalten? <a href="/member/main/login">Erneut anfordern</a>
    </p>
</div>
