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
 * ⚠️ `$repeated` is the ONLY thing on this page that may differ between two
 * visitors, and it says nothing about an account — only that THIS browser
 * asked again (see LoginController::askedBefore()). The neutral lead above it
 * is unchanged in both cases; what changes is the advice at the foot.
 *
 * @var string $pageTitle
 * @var string $digits    four digits, or '' when nothing is waiting here
 * @var bool   $repeated  this browser has asked for a link before, this hour
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

    <?php if ($repeated): ?>
    <p class="me-card__aside">
        <strong>Sie haben in dieser Stunde schon einmal einen Link angefordert.</strong>
        Sehen Sie im <strong>Spam-Ordner</strong> nach — dort landet die E-Mail
        am häufigsten. Massgeblich ist die <strong>zuletzt geschickte E-Mail</strong>:
        ihr Link gilt, frühere sind damit hinfällig. Fordern Sie keinen neuen an —
        jede weitere Anforderung macht den vorherigen Link ungültig.
    </p>
    <?php else: ?>
    <p class="me-card__aside">
        Keine E-Mail erhalten? <a href="/member/main/login">Erneut anfordern</a>
    </p>
    <?php endif; ?>
</div>
