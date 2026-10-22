<?php
/**
 * Section «form» (block type `form`) — the text above a public form, then the
 * form itself (`partials/publicForm`, rendered from its FormDefinition — the
 * fields are code, not content). Data: PageSections::form() + the handler's
 * PublicFormHandler::viewContext().
 *
 * @var array{eyebrow:string,title:string,text:string,edit:string} $section
 * @var \Z77\Shared\Forms\PublicForm $form
 * @var array<string,array>  $fields
 * @var array<string,string> $errors
 * @var string $formError
 * @var string $checkUrl
 * @var string $csrfToken
 */
?>
<section class="fe-section fe-section--form"<?= raw($section['edit']) ?>>
    <div class="fe-container">
        <?php if ($section['eyebrow'] !== ''): ?>
        <div class="fe-section__eyebrow"><?= e($section['eyebrow']) ?></div>
        <?php endif; ?>
        <?php if ($section['title'] !== ''): ?>
        <h2 class="fe-section__title"><?= e($section['title']) ?></h2>
        <?php endif; ?>
        <?php if ($section['text'] !== ''): ?>
        <p class="fe-section__lead"><?= e($section['text']) ?></p>
        <?php endif; ?>
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
