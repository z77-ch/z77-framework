<?php
/**
 * Add / edit a contact. On «add» the form carries an OPTIONAL first address
 * (type + address block): leave it empty for a contact without an address,
 * fill any field and the whole block is validated. On «edit» only the
 * contact's own fields are here — addresses are managed in the ⋮ hub.
 * There is no delete: a contact is deactivated on the list row.
 *
 * @var \Z77\Module\Contact\Entities\Contact $entry
 * @var string $entityCsrf
 * @var \Z77\Persistence\Validation\EntityValidator $validator
 * @var array<string,string> $kindLabels
 * @var list<string> $languages
 * @var \Z77\Module\Contact\Entities\Address $address
 * @var string $typeCode
 * @var string $linkTitle
 * @var list<\Z77\Module\Contact\Entities\AddressType> $types
 * @var \Z77\Module\Contact\Validators\ContactAddressValidator|null $linkValidator
 * @var \Z77\Module\Contact\Validators\AddressValidator|null $addressValidator
 * @var string $actionBase
 */
$isNew      = $entry->getId() === null;
$actionBase = $actionBase ?? '/backend/contact/contact';
$tplNs      = 'Z77\\Module\\Contact';

$fieldError = function (string $name) use ($validator): string {
    return $validator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($validator->getFieldError($name)) . '</small>'
        : '';
};
$addressErrors = ($linkValidator !== null && $linkValidator->hasErrors()) || ($addressValidator !== null && $addressValidator->hasErrors());
?>
<form data-fetch-post="<?= e($actionBase) ?>/<?= $isNew ? 'add' : 'edit?id=' . e((string) $entry->getId()) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Kontakt anlegen' : 'Kontakt «' . e($entry->displayName()) . '» bearbeiten' ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors() || $addressErrors): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
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
        <?php if ($isNew): ?>
        <div class="be-form__section">Erste Adresse <small>(optional — leer lassen für einen Kontakt ohne Adresse)</small></div>
        <?= raw($this->partial('Backend/ContactController/_addressFields', [
            'address'          => $address,
            'typeCode'         => $typeCode,
            'linkTitle'        => $linkTitle,
            'types'            => $types,
            'linkValidator'    => $linkValidator,
            'addressValidator' => $addressValidator,
            'required'         => false,
        ], $tplNs)) ?>
        <?php else: ?>
        <p class="be-form__hint">Adressen werden im ⋮-Menü der Zeile verwaltet.</p>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
