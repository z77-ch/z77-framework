<?php
/**
 * Generic public form — renders whatever a {@see \Z77\Shared\Forms\FormDefinition}
 * declares (see docs/03-development/public-form-bauplan.md). A project that needs
 * different markup overrides THIS partial; it only has to keep the data-*
 * contract below, then the framework JS keeps working:
 *
 *   data-public-form      on the <form>   — JS entry point
 *   data-check-url        on the <form>   — blur endpoint
 *   data-validate         on the input    — check this field on blur/change
 *   data-form-row         on the row      — gets the error class
 *   data-hint-for="{f}"   on the hint      — receives the message (aria-live)
 *
 * Validation is server-side only. Native browser validation is disabled
 * (novalidate) so the error display stays consistent; required/type/maxlength
 * remain for semantics, a11y and as a hard input cap.
 *
 * @var \Z77\Shared\Forms\PublicForm $form
 * @var array<string,array> $fields     normalized field specs (render order)
 * @var array<string,string> $errors    field errors from the submit re-render
 * @var string $formError               general error (CSRF / send failure)
 * @var string $checkUrl                blur-validation endpoint
 * @var string $csrfToken               from the base controller context
 */
$rowError = static fn (string $f): string => isset($errors[$f]) ? ' fe-form__row--error' : '';
$invalid  = static fn (string $f): string => isset($errors[$f]) ? ' aria-invalid="true"' : '';
$hint     = static fn (string $f): string => $errors[$f] ?? '';
$required = static fn (array $spec): string => ($spec['rules']['required'] ?? false) || ($spec['rules']['accepted'] ?? false) ? ' required' : '';
$maxLen   = static fn (array $spec): string => isset($spec['rules']['max']) ? ' maxlength="' . (int) $spec['rules']['max'] . '"' : '';
$autoCmp  = static fn (array $spec): string => $spec['autocomplete'] !== '' ? ' autocomplete="' . e($spec['autocomplete']) . '"' : '';

/* "Bitte alle Felder ausfüllen" is only TRUE when there is more than one field and
   every one of them is mandatory. It used to render unconditionally, which told the
   member login — a single e-mail field — to fill in "all fields" (PUBLIC-FORM-005).
   A mixed form (some optional fields) gets no note rather than a wrong one; marking
   the individual mandatory fields is a separate feature, not this line's job. */
$requiredCount = count(array_filter(
    $fields,
    static fn (array $spec): bool => ($spec['rules']['required'] ?? false) || ($spec['rules']['accepted'] ?? false)
));
$showRequiredNote = $requiredCount > 1 && $requiredCount === count($fields);
?>
<section class="fe-form">
    <div class="fe-container">
        <form method="post" data-public-form data-check-url="<?= e($checkUrl) ?>" novalidate="novalidate">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <?php /* Honeypots: hidden from humans (CSS + aria), bots fill them. */ ?>
            <div class="fe-form__hp" aria-hidden="true">
                <?php foreach ($form->definition()->honeypots() as $trap): ?>
                <label><?= e($trap) ?> <input type="text" name="<?= e($trap) ?>" value="" tabindex="-1" autocomplete="off"></label>
                <?php endforeach; ?>
            </div>

            <?php if ($formError !== ''): ?>
            <p class="fe-form__error" role="alert"><?= e($formError) ?></p>
            <?php endif; ?>

            <?php foreach ($fields as $name => $spec): ?>
                <?php $id = 'pf-' . $name; $hintId = 'pf-hint-' . $name; ?>

                <?php if ($spec['type'] === 'radio'): ?>
                <fieldset class="fe-form__row fe-form__row--radio<?= $rowError($name) ?>" data-form-row>
                    <legend><?= e($spec['label']) ?></legend>
                    <?php foreach ($spec['options'] as $value => $label): ?>
                    <label>
                        <input type="radio" name="<?= e($name) ?>" value="<?= e((string) $value) ?>" data-validate<?= $required($spec) ?>
                            aria-describedby="<?= e($hintId) ?>"<?= $invalid($name) ?><?= $form->isChecked($name, (string) $value) ? ' checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </fieldset>

                <?php elseif ($spec['type'] === 'checkbox'): ?>
                <div class="fe-form__row fe-form__row--check<?= $rowError($name) ?>" data-form-row>
                    <label>
                        <input type="checkbox" name="<?= e($name) ?>" value="1" data-validate<?= $required($spec) ?>
                            aria-describedby="<?= e($hintId) ?>"<?= $invalid($name) ?><?= $form->isChecked($name) ? ' checked' : '' ?>>
                        <?php /* `labelHtml` statt `label`: ein Kaestchen, das auf
                                 Rechtstexte zeigt, braucht Links in seinem Label, und
                                 `label` ist escaped.
                                 ⚠️ Der Wert kommt aus der FormDefinition, also aus
                                 CODE — niemals aus Request-Daten. Wer hier je etwas
                                 durchreicht, das ein Besucher beeinflussen kann, hat
                                 eine XSS-Luecke gebaut. Darum auch keine Sanitize-
                                 Illusion: die Regel ist die Herkunft, nicht ein
                                 Filter. */ ?>
                        <span><?= ($spec['labelHtml'] ?? '') !== '' ? $spec['labelHtml'] : e($spec['label']) ?></span>
                    </label>
                </div>

                <?php elseif ($spec['type'] === 'textarea'): ?>
                <div class="fe-form__row fe-form__row--area<?= $rowError($name) ?>" data-form-row>
                    <label for="<?= e($id) ?>"><?= e($spec['label']) ?></label>
                    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" data-validate<?= $required($spec) ?><?= $maxLen($spec) ?>
                        aria-describedby="<?= e($hintId) ?>"<?= $invalid($name) ?>><?= e($form->get($name)) ?></textarea>
                </div>

                <?php else: ?>
                <div class="fe-form__row<?= $rowError($name) ?>" data-form-row>
                    <label for="<?= e($id) ?>"><?= e($spec['label']) ?></label>
                    <input id="<?= e($id) ?>" type="<?= e($spec['type']) ?>" name="<?= e($name) ?>" value="<?= e($form->get($name)) ?>"
                        data-validate<?= $required($spec) ?><?= $maxLen($spec) ?><?= $autoCmp($spec) ?>
                        aria-describedby="<?= e($hintId) ?>"<?= $invalid($name) ?>>
                </div>
                <?php endif; ?>

                <p class="fe-form__hint" id="<?= e($hintId) ?>" data-hint-for="<?= e($name) ?>" aria-live="polite"><?= e($hint($name)) ?></p>
            <?php endforeach; ?>

            <?php if ($showRequiredNote): ?>
            <p class="fe-form__note"><?= e(t('form.note.required')) ?></p>
            <?php endif; ?>
            <button class="fe-form__submit" type="submit"><?= e(t('form.submit')) ?></button>
        </form>
    </div>
</section>
