<?php
/**
 * Waiting page (B8 stage D): the neutral answer of the request form — plus
 * the check digits, if a request is actually waiting under this session.
 * The digits are the number the customer compares on the confirmation page;
 * they are shown, never typed.
 *
 * @var string $pageTitle
 * @var string $digits  four digits, or '' when nothing is waiting here
 */
?>
<div class="me-card" data-login-wait>
    <h1 class="me-card__title">Anmeldung angefordert</h1>
    <p class="me-card__lead">
        Falls zu dieser Adresse ein Konto besteht, ist eine E-Mail unterwegs.
        Öffnen Sie den Link — auf diesem Gerät oder auf dem Handy.
    </p>

    <?php if ($digits !== ''): ?>
    <p class="me-check">
        Prüfzahl <strong class="me-check__digits"><?= e($digits) ?></strong>
    </p>
    <p class="me-card__note">
        Dieselbe Zahl steht in der E-Mail-Bestätigung. Bestätigen Sie nur, wenn
        sie übereinstimmt — dann meldet sich dieses Gerät hier automatisch an.
    </p>
    <p class="me-card__note" data-login-wait-note>Warte auf die Bestätigung …</p>
    <?php endif; ?>

    <p class="me-card__aside">
        Keine E-Mail erhalten? <a href="/member/main/login">Erneut anfordern</a>
    </p>
</div>
