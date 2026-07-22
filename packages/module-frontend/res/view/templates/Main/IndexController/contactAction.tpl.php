<?php
/**
 * Contact page — hero, direct contact details and the reference public form
 * (docs/03-development/public-form-bauplan.md). The form's state comes from
 * PublicFormHandler::viewContext(); the partial renders whatever
 * ContactFormDefinition declares.
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
<section class="fe-hero">
    <div class="fe-container">
        <div class="fe-hero__eyebrow">Kontakt</div>
        <h1 class="fe-hero__title">Reden ist günstiger als raten.</h1>
        <p class="fe-hero__subline">
            Kurze E-Mail, kurzes Telefonat — meistens ist nach 15 Minuten klar,
            ob z77 zu Ihrem Projekt passt.
        </p>
    </div>
</section>

<section class="fe-section">
    <div class="fe-container">
        <div class="fe-section__eyebrow">Direkt</div>
        <h2 class="fe-section__title">So erreichen Sie uns.</h2>

        <dl class="fe-dl">
            <div class="fe-dl__row">
                <dt class="fe-dl__term">E-Mail</dt>
                <dd class="fe-dl__def"><a href="mailto:hello@z77.example">hello@z77.example</a></dd>
            </div>
            <div class="fe-dl__row">
                <dt class="fe-dl__term">Telefon</dt>
                <dd class="fe-dl__def"><a href="tel:+41000000000">+41 00 000 00 00</a></dd>
            </div>
            <div class="fe-dl__row">
                <dt class="fe-dl__term">Adresse</dt>
                <dd class="fe-dl__def">Beispielstrasse 1<br>8000 Zürich<br>Schweiz</dd>
            </div>
            <div class="fe-dl__row">
                <dt class="fe-dl__term">Antwortzeit</dt>
                <dd class="fe-dl__def">Werktags innerhalb von 24 Stunden.</dd>
            </div>
        </dl>
    </div>
</section>

<?= $this->partial('partials/publicForm', [
    'form'      => $form,
    'fields'    => $fields,
    'errors'    => $errors,
    'formError' => $formError,
    'checkUrl'  => $checkUrl,
    'csrfToken' => $csrfToken,
]) ?>
