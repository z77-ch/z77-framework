<?php
/**
 * Registration page (B7): intro + the declared form. The form markup comes
 * from the frontend module's generic publicForm partial (one source of truth
 * for the data-* contract the framework JS binds to) — member.css styles its
 * fe-* classes for this view-area.
 *
 * @var string $pageTitle
 * @var \Z77\Shared\Forms\PublicForm $form
 * @var array<string,array>  $fields
 * @var array<string,string> $errors
 * @var string $formError
 * @var string $checkUrl
 * @var string $csrfToken
 */
?>
<div class="me-card">
    <h1 class="me-card__title">Registrieren</h1>
    <p class="me-card__lead">
        Erstellen Sie Ihr Konto. Sie erhalten anschliessend eine E-Mail mit einem
        Bestätigungslink — erst danach prüfen wir Ihre Registrierung und schalten
        Ihren Zugang frei.
    </p>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>

    <p class="me-card__aside">
        Keine Bestätigungs-E-Mail erhalten?
        <a href="/member/main/resend">Link erneut anfordern</a>
    </p>
</div>
