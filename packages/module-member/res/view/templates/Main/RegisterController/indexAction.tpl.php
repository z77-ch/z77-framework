<?php
/**
 * Registration page (B7): intro + the declared form. The form markup comes
 * from the frontend module's generic publicForm partial (one source of truth
 * for the data-* contract the framework JS binds to) — member.css styles its
 * fe-* classes for this view-area.
 *
 * Four shapes in one file, because they are the same page with different
 * premises and a second template would drift from this one:
 *   $invite === null    the open registration
 *   $invite['dead']     a link that cannot be redeemed any more
 *   $invite['grant']    the invited address HAS an account — «add this tenant
 *                       to it?», a yes/no POST (ADR-037)
 *   otherwise           redeeming an invitation into a NEW account (name form)
 *
 * @var string $pageTitle
 * @var ?array $invite    null | ['dead'=>true]
 *                        | ['dead'=>false,'grant'=>false,'email'=>…,'outcome'=>?string]
 *                        | ['dead'=>false,'grant'=>true,'email'=>…,'tenantName'=>…,'outcome'=>?string]
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
$isGrant  = $isInvite && !$isDead && ($invite['grant'] ?? false);
$taken    = $isInvite && (($invite['outcome'] ?? null) === InvitationFlow::ALREADY_TAKEN);
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

<?php elseif ($taken): ?>
    <h1 class="me-card__title">Einladung annehmen</h1>
    <?php /* Home or grant, whatever state — the address is on this tenant
             already. The link is consumed; nothing to do but sign in. */ ?>
    <p class="me-card__lead" role="alert">
        Ihre E-Mail-Adresse gehört bereits zu dieser Verwaltung. Melden Sie sich
        einfach an — diese Einladung wird nicht mehr gebraucht.
    </p>
    <p class="me-card__aside"><a href="/member/main/login">Zur Anmeldung</a></p>

<?php elseif ($isGrant): ?>
    <h1 class="me-card__title">Verwaltung hinzufügen?</h1>
    <p class="me-card__lead">
        Sie wurden eingeladen, für <strong><?= e((string)$invite['tenantName']) ?></strong>
        mitzuarbeiten. Zu Ihrer Adresse <strong><?= e((string)$invite['email']) ?></strong>
        besteht bereits ein Konto — es bleibt, wie es ist. Wenn Sie zustimmen,
        kommt diese Verwaltung als weiterer Mandant hinzu, und Sie wählen nach
        der Anmeldung, für wen Sie gerade arbeiten.
    </p>
    <p class="me-card__aside">
        Wir prüfen den Zugang und schalten ihn frei; Sie erhalten dann eine
        E-Mail. Sie können den Zugang jederzeit wieder ablegen — die Verwaltung,
        die Sie eingeladen hat, kann ihn ebenso pausieren oder entfernen.
    </p>

    <?php /* One form, two buttons. `decision` decides; there is nothing else
             to type. No JS — a POST with a name attribute is the whole of it. */ ?>
    <form method="post" class="fe-form me-decision" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="fe-form__submit" type="submit" name="decision" value="add">Verwaltung hinzufügen</button>
        <button class="fe-form__submit fe-form__submit--quiet" type="submit" name="decision" value="decline">Ablehnen</button>
    </form>

<?php elseif ($isInvite): ?>
    <h1 class="me-card__title">Einladung annehmen</h1>
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
