<?php
/**
 * Add / edit a typed address of a contact — the link (type, title) and the
 * address fields in one modal. On «add» the select offers active types
 * only; on «edit» the link's own type stays selectable even when it was
 * deactivated since (history).
 *
 * @var \Z77\Module\Contact\Entities\Contact $contact
 * @var \Z77\Module\Contact\Entities\ContactAddress|null $link  null on add before POST
 * @var \Z77\Module\Contact\Entities\Address $address
 * @var string $typeCode
 * @var string $linkTitle
 * @var list<\Z77\Module\Contact\Entities\AddressType> $types
 * @var string $entityCsrf
 * @var \Z77\Module\Contact\Validators\ContactAddressValidator|null $linkValidator
 * @var \Z77\Module\Contact\Validators\AddressValidator|null $addressValidator
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/contact/contact';
$tplNs      = 'Z77\\Module\\Contact';
$isNew      = $link === null || $link->getId() === null;
$hasErrors  = ($linkValidator !== null && $linkValidator->hasErrors()) || ($addressValidator !== null && $addressValidator->hasErrors());
$target     = $isNew
    ? $actionBase . '/add-address?id=' . (int) $contact->getId()
    : $actionBase . '/edit-address?id=' . (int) $link->getId();
?>
<form data-fetch-post="<?= e($target) ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title"><?= $isNew ? 'Adresse hinzufügen' : 'Adresse bearbeiten' ?> — <?= e($contact->displayName()) ?></h2>
    </div>
    <div class="be-modal__body">
        <?php if ($hasErrors): ?>
        <div class="be-modal__alert be-modal__alert--error">Bitte überprüfe die markierten Eingaben.</div>
        <?php endif; ?>
        <?= raw($this->partial('Backend/ContactController/_addressFields', [
            'address'          => $address,
            'typeCode'         => $typeCode,
            'linkTitle'        => $linkTitle,
            'types'            => $types,
            'linkValidator'    => $linkValidator,
            'addressValidator' => $addressValidator,
            'required'         => true,
        ], $tplNs)) ?>
        <?php if (!$isNew): ?>
        <p class="be-form__hint">Belege, die diese Adresse bereits verwendet haben, behalten ihren Stand — sie tragen eine Kopie.</p>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
