<?php
/**
 * PRG target of the registration — the confirmation is a page, not a session
 * flag. NEUTRAL by spec: the same page answers a new registration and a
 * repeated one with an existing address; what differs is only the mail that
 * went out. The wording must not reveal which case occurred.
 *
 * @var string $pageTitle
 */
?>
<div class="me-card">
    <h1 class="me-card__title">Vielen Dank</h1>
    <p class="me-card__lead">
        Wir haben Ihre Angaben erhalten und Ihnen eine E-Mail geschickt.
        Bitte folgen Sie dem Link darin.
    </p>
    <p class="me-card__aside">
        Keine E-Mail erhalten? Prüfen Sie den Spam-Ordner oder
        <a href="/member/main/resend">fordern Sie den Link erneut an</a>.
    </p>
</div>
