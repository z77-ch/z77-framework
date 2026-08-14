<?php
/**
 * Registration page (B7): intro + the declared form. The form markup comes
 * from the frontend module's generic publicForm partial (one source of truth
 * for the data-* contract the framework JS binds to) — member.css styles its
 * fe-* classes for this view-area.
 *
 * Three shapes in one file (B7 v1.1.0), because they are the same page with
 * different premises and a second template would drift from this one:
 *   $invite === null    the open registration
 *   $invite['dead']     a link that cannot be redeemed any more
 *   otherwise           redeeming an invitation
 *
 * @var string $pageTitle
 * @var ?array $invite    null | ['dead'=>true] | ['dead'=>false,'email'=>…,'outcome'=>?string]
 * @var ?\Z77\Shared\Forms\PublicForm $form
 * @var array<string,array>  $fields
 * @var array<string,string> $errors
 * @var string $formError
 * @var string $checkUrl
 * @var string $csrfToken
 * @var ?string $originNote  Satz zum Angebot, über das jemand hergekommen ist
 */

use Z77\Module\Member\Services\InvitationFlow;

$isInvite = is_array($invite ?? null);
$isDead   = $isInvite && ($invite['dead'] ?? false);
?>
<div class="me-card">
<?php if ($isDead): ?>
    <h1 class="me-card__title">Einladung nicht mehr gültig</h1>
    <p class="me-card__lead">
        Diese Einladung ist abgelaufen oder wurde zurückgezogen.
    </p>
    <p class="me-card__aside">
        <?php /* Deliberately no resend: only the person who invited may renew
                 an invitation — otherwise the recipient keeps his own access
                 to the tenant alive (B7 v1.1.0). */ ?>
        Bitte wenden Sie sich an die Person, die Sie eingeladen hat — sie kann
        Ihnen eine neue Einladung schicken.
    </p>

<?php elseif ($isInvite): ?>
    <h1 class="me-card__title">Einladung annehmen</h1>

    <?php if (($invite['outcome'] ?? null) === InvitationFlow::ALREADY_TAKEN): ?>
    <p class="me-card__lead" role="alert">
        Für diese E-Mail-Adresse besteht bereits ein Konto. Melden Sie sich mit
        Ihrer Adresse an — diese Einladung wird nicht mehr gebraucht.
    </p>
    <p class="me-card__aside"><a href="/member/main/login">Zur Anmeldung</a></p>
    <?php else: ?>
    <p class="me-card__lead">
        Sie wurden eingeladen, an einer bestehenden Verwaltung mitzuarbeiten.
        Geben Sie noch Ihren Namen an — Ihre E-Mail-Adresse ist durch die
        Einladung bereits bestätigt. Anschliessend prüfen wir den Zugang und
        schalten ihn frei.
    </p>

    <?php /* The address is TEXT, not a field: it comes from the token, and a
             pre-filled input would only look unchangeable (B7 v1.1.0). */ ?>
    <p class="me-card__aside">
        Ihre E-Mail-Adresse: <strong><?= e((string)$invite['email']) ?></strong><br>
        Sie lässt sich hier nicht ändern — die Einladung gilt genau für diese
        Adresse.
    </p>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>
    <?php endif; ?>

<?php else: ?>
    <h1 class="me-card__title">Registrieren</h1>
    <p class="me-card__lead">
        Erstellen Sie Ihr Konto. Sie erhalten anschliessend eine E-Mail mit einem
        Bestätigungslink — erst danach prüfen wir Ihre Registrierung und schalten
        Ihren Zugang frei.
    </p>

    <?php /* Der Satz zum Angebot, über das jemand hergekommen ist
             (memberConfig `originNotes`). Er steht ÜBER dem Formular: wer auf
             «Demo-Konto anlegen» geklickt hat und hier «Registrieren» liest,
             soll nicht erst raten, ob er richtig ist. Ohne passenden Eintrag
             steht hier nichts. */ ?>
    <?php if (trim((string)($originNote ?? '')) !== ''): ?>
    <p class="me-card__aside"><?= e((string)$originNote) ?></p>
    <?php endif; ?>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>
<?php endif; ?>
</div>
