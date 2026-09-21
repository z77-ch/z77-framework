<?php
/**
 * The address block of a form — type + title of the link, then the address
 * fields. Used by the contact form (optional first address on «add») and
 * the address modal (add / edit). One place for the fields (Rule 8).
 *
 * Address fields post under the `address_` prefix (`address_first_name`, …)
 * because `first_name` and `title` exist on the contact / the link as well;
 * the controller strips the prefix before cleaning. Link fields post as
 * `type_code` and `title`.
 *
 * @var \Z77\Module\Contact\Entities\Address $address
 * @var string $typeCode
 * @var string $linkTitle
 * @var list<\Z77\Module\Contact\Entities\AddressType> $types  what the select offers
 * @var \Z77\Module\Contact\Validators\ContactAddressValidator|null $linkValidator
 * @var \Z77\Module\Contact\Validators\AddressValidator|null $addressValidator
 * @var bool $required  whether the block's required fields carry `required` (false = optional block on «add»)
 */
$required  = $required ?? true;
$req       = $required ? ' required' : '';
$linkError = function (string $name) use ($linkValidator): string {
    return $linkValidator !== null && $linkValidator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($linkValidator->getFieldError($name)) . '</small>'
        : '';
};
$addrError = function (string $name) use ($addressValidator): string {
    return $addressValidator !== null && $addressValidator->hasFieldError($name)
        ? '<small class="be-form__field-error" data-z77-field-error>' . e($addressValidator->getFieldError($name)) . '</small>'
        : '';
};
$invalid = fn(?object $v, string $name): string => $v !== null && $v->hasFieldError($name) ? 'true' : 'false';
?>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Adresstyp</label>
        <select name="type_code"<?= $req ?> aria-invalid="<?= $invalid($linkValidator, 'type_code') ?>">
            <?php foreach ($types as $type): ?>
            <option value="<?= e($type->getCode()) ?>"<?= $typeCode === $type->getCode() ? ' selected' : '' ?>><?= e($type->getLabel()) ?><?= $type->isActive() ? '' : ' (inaktiv)' ?></option>
            <?php endforeach; ?>
        </select>
        <?= raw($linkError('type_code')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Bezeichnung <small>(unterscheidet zwei Adressen gleichen Typs, z.B. «Lager Ost»)</small></label>
        <input type="text" name="title" value="<?= e($linkTitle) ?>" maxlength="80" autocomplete="off"
               aria-invalid="<?= $invalid($linkValidator, 'title') ?>">
        <?= raw($linkError('title')) ?>
    </div>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Anrede</label>
        <input type="text" name="address_salutation" value="<?= e($address->getSalutation()) ?>" maxlength="20" autocomplete="off" placeholder="Herr / Frau / Firma"
               aria-invalid="<?= $invalid($addressValidator, 'salutation') ?>">
        <?= raw($addrError('salutation')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Titel</label>
        <input type="text" name="address_title" value="<?= e($address->getTitle()) ?>" maxlength="40" autocomplete="off"
               aria-invalid="<?= $invalid($addressValidator, 'title') ?>">
        <?= raw($addrError('title')) ?>
    </div>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Vorname</label>
        <input type="text" name="address_first_name" value="<?= e($address->getFirstName()) ?>" maxlength="70" autocomplete="off"
               aria-invalid="<?= $invalid($addressValidator, 'first_name') ?>">
        <?= raw($addrError('first_name')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Name <small>(Nachname oder Firma)</small></label>
        <input type="text" name="address_name" value="<?= e($address->getName()) ?>" maxlength="70" autocomplete="off"<?= $req ?>
               aria-invalid="<?= $invalid($addressValidator, 'name') ?>">
        <?= raw($addrError('name')) ?>
    </div>
</div>
<div class="be-form__field" data-z77-field-wrapper>
    <label>Adresszusatz <small>(Abteilung, c/o)</small></label>
    <input type="text" name="address_address_row" value="<?= e($address->getAddressRow()) ?>" maxlength="120" autocomplete="off"
           aria-invalid="<?= $invalid($addressValidator, 'address_row') ?>">
    <?= raw($addrError('address_row')) ?>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Strasse</label>
        <input type="text" name="address_street" value="<?= e($address->getStreet()) ?>" maxlength="120" autocomplete="off"<?= $req ?>
               aria-invalid="<?= $invalid($addressValidator, 'street') ?>">
        <?= raw($addrError('street')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Nr.</label>
        <input type="text" name="address_house_no" value="<?= e($address->getHouseNo()) ?>" maxlength="16" autocomplete="off"
               aria-invalid="<?= $invalid($addressValidator, 'house_no') ?>">
        <?= raw($addrError('house_no')) ?>
    </div>
</div>
<div class="be-form__grid">
    <div class="be-form__field" data-z77-field-wrapper>
        <label>PLZ</label>
        <input type="text" name="address_zip" value="<?= e($address->getZip()) ?>" maxlength="16" autocomplete="off"<?= $req ?>
               aria-invalid="<?= $invalid($addressValidator, 'zip') ?>">
        <?= raw($addrError('zip')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Ort</label>
        <input type="text" name="address_city" value="<?= e($address->getCity()) ?>" maxlength="70" autocomplete="off"<?= $req ?>
               aria-invalid="<?= $invalid($addressValidator, 'city') ?>">
        <?= raw($addrError('city')) ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label>Land <small>(ISO, z.B. CH)</small></label>
        <input type="text" name="address_country" value="<?= e($address->getCountry()) ?>" maxlength="2" autocomplete="off"<?= $req ?>
               aria-invalid="<?= $invalid($addressValidator, 'country') ?>">
        <?= raw($addrError('country')) ?>
    </div>
</div>
