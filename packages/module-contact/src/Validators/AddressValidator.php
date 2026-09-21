<?php

namespace Z77\Module\Contact\Validators;

use Z77\Module\Contact\Entities\Address;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates an {@see Address}: the addressee name, street, zip, city and
 * country are required; the rest is optional with length limits. Country is
 * ISO 3166-1 alpha-2; the zip format depends on it — four digits for CH (a
 * Swiss zip that is not four digits is a typo, not a foreign address), a
 * lenient 3–10 characters elsewhere. No zip DIRECTORY lookup (wdv's
 * `ChZipCode` table): a directory that is not maintained refuses real
 * addresses, which is worse than accepting a wrong one.
 */
class AddressValidator extends EntityValidator
{
    public function __construct(Address $address)
    {
        parent::__construct($address);
    }

    public function validateSalutation(string $salutation): void
    {
        $this->validate('salutation', 'Anrede', $salutation)->maxLength(20);
    }

    public function validateTitle(string $title): void
    {
        $this->validate('title', 'Titel', $title)->maxLength(40);
    }

    public function validateFirstName(string $firstName): void
    {
        $this->validate('first_name', 'Vorname', $firstName)->maxLength(70);
    }

    public function validateName(string $name): void
    {
        $this->validate('name', 'Name', $name)
            ->notEmpty()
            ->maxLength(70);
    }

    public function validateAddressRow(string $addressRow): void
    {
        $this->validate('address_row', 'Adresszusatz', $addressRow)->maxLength(120);
    }

    public function validateStreet(string $street): void
    {
        $this->validate('street', 'Strasse', $street)
            ->notEmpty()
            ->maxLength(120);
    }

    public function validateHouseNo(string $houseNo): void
    {
        $this->validate('house_no', 'Hausnummer', $houseNo)->maxLength(16);
    }

    public function validateZip(string $zip): void
    {
        $this->validate('zip', 'PLZ', $zip)
            ->notEmpty()
            ->maxLength(16);
        if ($this->hasFieldError('zip')) {
            return;
        }

        if ($this->entity->getCountry() === 'CH') {
            if (!preg_match('/^\d{4}$/', $zip)) {
                $this->addFieldError('zip', 'Eine Schweizer PLZ hat vier Ziffern.');
            }
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,8}[A-Za-z0-9]$/', $zip)) {
            $this->addFieldError('zip', 'PLZ: 3–10 Zeichen, Buchstaben, Ziffern, Leerzeichen oder Bindestrich.');
        }
    }

    public function validateCity(string $city): void
    {
        $this->validate('city', 'Ort', $city)
            ->notEmpty()
            ->minLength(2)
            ->maxLength(70);
    }

    public function validateCountry(string $country): void
    {
        $this->validate('country', 'Land', $country)->notEmpty();

        if (!$this->hasFieldError('country') && !preg_match('/^[A-Z]{2}$/', $country)) {
            $this->addFieldError('country', 'Land ist der zweistellige ISO-Code in Grossbuchstaben (z.B. CH).');
        }
    }
}
