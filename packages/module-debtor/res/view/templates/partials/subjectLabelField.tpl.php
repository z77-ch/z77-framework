<?php
/**
 * The «Betreffname» field of a document head (owner decision 2026-10-21) — a shared building
 * block (Rule 9): debtor's invoice editor and module-order's order window render it right before
 * their «Betreff». A short text input `subject_label` with a `<datalist>` of the labels in use
 * and the defaults (`SubjectLabelDefaults::suggestions()` — a short list), freely overridable.
 *
 * `prefill` (a NEW document): the input carries `data-prefill` = the default it was pre-filled
 * with — a customer choice re-fills it with the new customer's default only while the field
 * still holds that value (core.js `set-value` with `untouched`, `CustomerPickerTrait`); what the
 * user typed stays. An existing document has no `data-prefill`: its label never changes by itself.
 *
 * Rendered with `$this->partial('partials/subjectLabelField', [...], 'Z77\Module\Debtor')`.
 *
 * @var string $id           the input id, e.g. `invoice-subject_label`
 * @var string $value        what the field shows
 * @var list<string> $suggestions
 * @var bool $prefill        a new document — see above
 * @var string $error        the field's error message, '' = none
 */
$listId = $id . '-list';
?>
<div class="be-form__field" data-z77-field-wrapper>
    <label for="<?= e($id) ?>">Betreffname</label>
    <input type="text" id="<?= e($id) ?>" name="subject_label" value="<?= e($value) ?>" maxlength="<?= \Z77\Module\Debtor\Invoicing\SubjectLabel::LENGTH ?>" autocomplete="off"
           <?= $suggestions !== [] ? 'list="' . e($listId) . '"' : '' ?><?= !empty($prefill) ? ' data-prefill="' . e($value) . '"' : '' ?> aria-invalid="<?= $error !== '' ? 'true' : 'false' ?>">
    <?php if ($suggestions !== []): ?>
    <datalist id="<?= e($listId) ?>">
        <?php foreach ($suggestions as $label): ?>
        <option value="<?= e($label) ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <small class="be-form__field-error" data-z77-field-error><?= e($error) ?></small>
    <?php endif; ?>
</div>
