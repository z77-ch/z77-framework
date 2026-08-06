<?php
/**
 * Confirmation landing (B7): renders one of the three flow outcomes. 'dead'
 * covers unknown, expired and used-while-unconfirmed links with ONE wording
 * and the resend way out — the page never explains which kind of dead.
 *
 * @var string $pageTitle
 * @var string $outcome  'confirmed' | 'already' | 'dead'
 */

use Z77\Module\Member\Services\RegistrationFlow;
?>
<div class="me-card">
<?php if ($outcome === RegistrationFlow::CONFIRMED): ?>
    <h1 class="me-card__title">E-Mail bestätigt</h1>
    <p class="me-card__lead">
        Vielen Dank — Ihre E-Mail-Adresse ist bestätigt. Wir prüfen Ihre
        Registrierung und schalten Ihren Zugang frei. Sie erhalten eine E-Mail,
        sobald es so weit ist.
    </p>
<?php elseif ($outcome === RegistrationFlow::ALREADY): ?>
    <h1 class="me-card__title">Bereits bestätigt</h1>
    <p class="me-card__lead">
        Diese E-Mail-Adresse ist bereits bestätigt — es gibt nichts weiter zu
        tun. Sie erhalten eine E-Mail, sobald Ihr Zugang freigeschaltet ist.
    </p>
<?php else: ?>
    <h1 class="me-card__title">Link nicht mehr gültig</h1>
    <p class="me-card__lead">
        Dieser Bestätigungslink ist abgelaufen oder wurde bereits verwendet.
    </p>
    <p class="me-card__aside">
        <a href="/member/main/resend">Neuen Bestätigungslink anfordern</a>
    </p>
<?php endif; ?>
</div>
