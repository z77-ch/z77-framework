<?php

namespace Z77\Module\Contact\Validators;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactKind;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see Contact}. Which name part is required follows the kind:
 * an organisation needs a company, a person a last name; the other is
 * optional. Language is ISO 639-1; e-mail optional but deliverable when set.
 */
class ContactValidator extends EntityValidator
{
    public function __construct(Contact $contact)
    {
        parent::__construct($contact);
    }

    public function validateKind(string $kind): void
    {
        $this->validate('kind', 'Art', $kind)->notEmpty();

        if (!$this->hasFieldError('kind') && ContactKind::tryFrom($kind) === null) {
            $this->addFieldError('kind', 'Unbekannte Art: ' . $kind);
        }
    }

    public function validateCompany(string $company): void
    {
        $this->validate('company', 'Firma', $company)->maxLength(120);

        if ($this->entity->isOrganisation()) {
            $this->notEmpty();
        }
    }

    public function validateFirstName(string $firstName): void
    {
        $this->validate('first_name', 'Vorname', $firstName)->maxLength(70);
    }

    public function validateLastName(string $lastName): void
    {
        $this->validate('last_name', 'Name', $lastName)->maxLength(70);

        if (!$this->entity->isOrganisation()) {
            $this->notEmpty();
        }
    }

    public function validateLanguage(string $language): void
    {
        $this->validate('language', 'Sprache', $language)->notEmpty();

        if (!$this->hasFieldError('language') && !preg_match('/^[a-z]{2}$/', $language)) {
            $this->addFieldError('language', 'Sprache ist der zweistellige ISO-Code in Kleinbuchstaben (z.B. de).');
        }
    }

    public function validateEmail(string $email): void
    {
        if ($email === '') {
            return;
        }
        $this->validate('email', 'E-Mail', $email)
            ->maxLength(190)
            ->isEmail();
    }

    public function validatePhone(string $phone): void
    {
        $this->validate('phone', 'Telefon', $phone)->maxLength(40);
    }
}
