<?php

namespace Z77\Module\Contact\Validators;

use Z77\Module\Contact\Entities\AddressType;
use Z77\Module\Contact\Repositories\AddressTypeRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates an {@see AddressType} for the backend. Code: `[a-z][a-z0-9-]{1,15}`,
 * unique (the entity lower-cases; a hand-edited file might not). Label: required.
 *
 * Without a repository the uniqueness check is skipped (format-only).
 */
class AddressTypeValidator extends EntityValidator
{
    public function __construct(
        AddressType $type,
        private ?AddressTypeRepository $repo = null,
    ) {
        parent::__construct($type);
    }

    public function validateCode(string $code): void
    {
        $this->validate('code', 'Code', $code)
            ->notEmpty()
            ->minLength(2)
            ->maxLength(16);

        if (!$this->hasFieldError('code') && !preg_match('/^[a-z][a-z0-9-]*$/', $code)) {
            $this->addFieldError('code', 'Code: Kleinbuchstaben (a–z), Ziffern und Bindestrich, mit einem Buchstaben beginnend.');
        }

        if ($this->hasFieldError('code') || $this->repo === null) {
            return;
        }

        $ownId = $this->entity->getId();
        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() !== $ownId && strcasecmp($other->getCode(), $code) === 0) {
                $this->addFieldError('code', 'Code «' . $code . '» existiert bereits.');
                return;
            }
        }
    }

    public function validateLabel(string $label): void
    {
        $this->validate('label', 'Bezeichnung', $label)
            ->notEmpty()
            ->maxLength(80);
    }
}
