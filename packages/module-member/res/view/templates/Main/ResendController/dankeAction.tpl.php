<?php
/**
 * PRG target of the resend — deliberately conditional wording («falls ein
 * Konto existiert»): the page answers identically for known and unknown
 * addresses, so it cannot be used to probe which addresses have accounts
 * (anti-oracle, B8 principle).
 *
 * @var string $pageTitle
 */
?>
<div class="me-card">
    <h1 class="me-card__title">Anfrage erhalten</h1>
    <p class="me-card__lead">
        Falls zu dieser Adresse ein Konto besteht, haben wir Ihnen soeben eine
        E-Mail geschickt. Bitte prüfen Sie auch den Spam-Ordner.
    </p>
</div>
