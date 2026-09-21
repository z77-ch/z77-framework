<?php

namespace Z77\Module\Contact\Validators;

use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Repositories\AddressTypeRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates the LINK part of a {@see ContactAddress}: the type code and the
 * title. The address fields themselves are {@see AddressValidator}'s.
 *
 * The reference rule (ADR-043 decision 19) in validator form: the code must
 * name an existing {@see \Z77\Module\Contact\Entities\AddressType}; when
 * the link is NEW or its type is being CHANGED ($requireActiveType) it must
 * be an ACTIVE one — a deactivated type is history, kept for the links that
 * already carry it, never chosen again (owner, 2026-09-21).
 *
 * Without a repository only the format is checked.
 */
class ContactAddressValidator extends EntityValidator
{
    public function __construct(
        ContactAddress $link,
        private ?AddressTypeRepository $types = null,
        private bool $requireActiveType = true,
    ) {
        parent::__construct($link);
    }

    /** A conflict only the database could see (the unique (contact, address, type) index under a race). */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validateTypeCode(string $typeCode): void
    {
        $this->validate('type_code', 'Adresstyp', $typeCode)
            ->notEmpty()
            ->maxLength(ContactAddress::TYPE_CODE_LENGTH);

        if ($this->hasFieldError('type_code') || $this->types === null) {
            return;
        }
        $type = $this->types->findByCode($typeCode);
        if ($type === null) {
            $this->addFieldError('type_code', 'Unbekannter Adresstyp «' . $typeCode . '».');
        } elseif ($this->requireActiveType && !$type->isActive()) {
            $this->addFieldError('type_code', 'Adresstyp «' . $type->getLabel() . '» ist inaktiv und wird nicht mehr vergeben.');
        }
    }

    public function validateTitle(string $title): void
    {
        $this->validate('title', 'Bezeichnung', $title)->maxLength(80);
    }
}
