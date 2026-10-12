<?php
/**
 * Add / edit a contact. On «add» the form carries an OPTIONAL first address
 * (type + address block): leave it empty for a contact without an address,
 * fill any field and the whole block is validated. On «edit» only the
 * contact's own fields are here — addresses are managed in the ⋮ hub.
 * There is no delete: a contact is deactivated on the list row. The fields
 * are the shared partials `_contactFields` and `_addressFields`; the
 * variables come from `Ui\ContactForm::vars()`.
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

$addressErrors = ($linkValidator !== null && $linkValidator->hasErrors()) || ($addressValidator !== null && $addressValidator->hasErrors());
?>
<form data-fetch-post="<?= e($actionBase) ?>/<?= $isNew ? 'add' : 'edit?id=' . e((string) $entry->getId()) ?>">
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Kontakt anlegen' : 'Kontakt «' . e($entry->displayName()) . '» bearbeiten' ?></h2>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Speichern'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <?php if ($validator->hasErrors() || $addressErrors): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($validator->getErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
            Bitte überprüfe die markierten Eingaben.
        </div>
        <?php endif; ?>
        <?= raw($this->partial('Backend/ContactController/_contactFields', [
            'entry'      => $entry,
            'validator'  => $validator,
            'kindLabels' => $kindLabels,
            'languages'  => $languages,
        ], $tplNs)) ?>
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
</form>
