<?php
/**
 * Login request page (B8): one field, magic link — no password, ever.
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
    <h1 class="me-card__title">Anmelden</h1>
    <p class="me-card__lead">
        Geben Sie Ihre E-Mail-Adresse ein — wir senden Ihnen einen
        Anmelde-Link. Ein Passwort gibt es nicht.
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
        Noch kein Konto? <a href="/member/main/register">Jetzt registrieren</a>
    </p>
</div>
