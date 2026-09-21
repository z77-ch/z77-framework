<?php
/**
 * Confirm removing a typed address from a contact. The address row goes with
 * the link when nothing else points at it; documents that used the address
 * keep their own copy, so nothing issued changes.
 *
 * @var \Z77\Module\Contact\Entities\ContactAddress $entry
 * @var string $entityCsrf
 * @var \Z77\Module\Contact\Services\AddressTypes $addressTypes
 * @var string $actionBase
 */
$actionBase  = $actionBase ?? '/backend/contact/contact';
$addressLine = $this->partial('Backend/ContactController/_address', ['link' => $entry, 'addressTypes' => $addressTypes], 'Z77\\Module\\Contact');
?>
<form data-fetch-post="<?= e($actionBase) ?>/remove-address">
    <input type="hidden" name="id"          value="<?= (int) $entry->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Adresse entfernen</h2>
    </div>
    <div class="be-modal__body">
        <p>Die Adresse <?= raw($addressLine) ?> von «<?= e($entry->getContact()->displayName()) ?>» wirklich entfernen?
           Bereits erstellte Belege behalten ihre Kopie.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Entfernen</button>
    </div>
</form>
