<?php
/**
 * Contact — intro text (the page title, h1), the text above the form and the
 * reference public form (docs/03-development/public-form-bauplan.md). The
 * texts are the content document `contact`; the form state comes from
 * PublicFormHandler::viewContext(), the fields from ContactFormDefinition.
 *
 * @var array $intro
 * @var array $formText
 * @var \Z77\Shared\Forms\PublicForm $form
 * @var array<string,array>  $fields
 * @var array<string,string> $errors
 * @var string $formError
 * @var string $checkUrl
 * @var string $csrfToken
 */
?>
<?= $this->partial('partials/sections/textSection', ['section' => $intro, 'titleTag' => 'h1']) ?>
<?= $this->partial('partials/sections/form', [
    'section'   => $formText,
    'form'      => $form,
    'fields'    => $fields,
    'errors'    => $errors,
    'formError' => $formError,
    'checkUrl'  => $checkUrl,
    'csrfToken' => $csrfToken,
]) ?>
