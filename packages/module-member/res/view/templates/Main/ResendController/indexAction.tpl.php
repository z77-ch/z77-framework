<?php
/**
 * Resend page (B7): one field, neutral outcome (see dankeAction).
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
    <h1 class="me-card__title">Link erneut anfordern</h1>
    <p class="me-card__lead">
        Geben Sie Ihre E-Mail-Adresse ein — wir senden Ihnen den
        Bestätigungslink erneut.
    </p>

    <?= $this->partial('partials/publicForm', [
        'form'      => $form,
        'fields'    => $fields,
        'errors'    => $errors,
        'formError' => $formError,
        'checkUrl'  => $checkUrl,
        'csrfToken' => $csrfToken,
    ], 'Z77\\Module\\Frontend') ?>
</div>
