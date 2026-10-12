<?php
/**
 * The contact's own fields — kind, company, first / last name, language, e-mail, phone. One
 * place for them (Rule 9): the contact dialog (`edit`) and any NEW-contact form of another
 * screen render this partial with the variables of `Ui\ContactForm::vars()` (e.g. debtor's
 * «+ Kontakt» in the invoice window, together with `_addressFields`). Posts the field names
 * the contact itself has (`kind`, `company`, …); the caller owns form tag and action row.
 *
 * @var \Z77\Module\Contact\Entities\Contact $entry
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var array<string,string> $kindLabels
 * @var list<string> $languages
 */
$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
?>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Art</label>
        <select name="kind" required aria-invalid="<?= $validator->hasFieldError('kind') ? 'true' : 'false' ?>">
            <?php foreach ($kindLabels as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $entry->getKind() === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?= raw($fieldError('kind')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Firma <small>(Pflicht bei Organisation)</small></label>
        <input type="text" name="company" value="<?= e($entry->getCompany()) ?>" maxlength="120" autocomplete="off"
               aria-invalid="<?= $validator->hasFieldError('company') ? 'true' : 'false' ?>">
        <?= raw($fieldError('company')) ?>
    </div>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Vorname</label>
        <input type="text" name="first_name" value="<?= e($entry->getFirstName()) ?>" maxlength="70" autocomplete="off"
               aria-invalid="<?= $validator->hasFieldError('first_name') ? 'true' : 'false' ?>">
        <?= raw($fieldError('first_name')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Name <small>(Pflicht bei Person)</small></label>
        <input type="text" name="last_name" value="<?= e($entry->getLastName()) ?>" maxlength="70" autocomplete="off"
               aria-invalid="<?= $validator->hasFieldError('last_name') ? 'true' : 'false' ?>">
        <?= raw($fieldError('last_name')) ?>
    </div>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Sprache <small>(für Belege an diesen Kontakt)</small></label>
        <select name="language" required aria-invalid="<?= $validator->hasFieldError('language') ? 'true' : 'false' ?>">
            <?php foreach ($languages as $language): ?>
            <option value="<?= e($language) ?>"<?= $entry->getLanguage() === $language ? ' selected' : '' ?>><?= e(mb_strtoupper($language)) ?></option>
            <?php endforeach; ?>
        </select>
        <?= raw($fieldError('language')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>E-Mail</label>
        <input type="email" name="email" value="<?= e($entry->getEmail()) ?>" maxlength="190" autocomplete="off"
               aria-invalid="<?= $validator->hasFieldError('email') ? 'true' : 'false' ?>">
        <?= raw($fieldError('email')) ?>
    </div>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Telefon</label>
        <input type="text" name="phone" value="<?= e($entry->getPhone()) ?>" maxlength="40" autocomplete="off"
               aria-invalid="<?= $validator->hasFieldError('phone') ? 'true' : 'false' ?>">
        <?= raw($fieldError('phone')) ?>
    </div>
</div>
