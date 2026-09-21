<?php

namespace Z77\Module\Vat\Validators;

use Z77\Module\Vat\Entities\TaxCategory;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see TaxCode} for the backend.
 *
 * Code: `[A-Z0-9]{2,8}`, unique across codes (case-insensitive — the entity
 * upper-cases, a hand-edited file might not). Country: two upper-case
 * letters. Category: one of {@see TaxCategory}. Label: required.
 *
 * Without a repository the uniqueness check is skipped (format-only).
 */
class TaxCodeValidator extends EntityValidator
{
    public function __construct(
        TaxCode $taxCode,
        private ?TaxCodeRepository $repo = null,
    ) {
        parent::__construct($taxCode);
    }

    public function validateCode(string $code): void
    {
        $this->validate('code', 'Code', $code)
            ->notEmpty()
            ->minLength(2)
            ->maxLength(8);

        if (!$this->hasFieldError('code') && !preg_match('/^[A-Z0-9]+$/', $code)) {
            $this->addFieldError('code', 'Code darf nur Grossbuchstaben (A–Z) und Ziffern (0–9) enthalten.');
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

    public function validateCountry(string $country): void
    {
        $this->validate('country', 'Land', $country)->notEmpty();

        if (!$this->hasFieldError('country') && !preg_match('/^[A-Z]{2}$/', $country)) {
            $this->addFieldError('country', 'Land ist der zweistellige ISO-Code in Grossbuchstaben (z.B. CH).');
        }
    }

    public function validateCategory(string $category): void
    {
        $this->validate('category', 'Kategorie', $category)->notEmpty();

        if (!$this->hasFieldError('category') && TaxCategory::tryFrom($category) === null) {
            $this->addFieldError('category', 'Unbekannte Kategorie: ' . $category);
        }
    }

    public function validateLabel(string $label): void
    {
        $this->validate('label', 'Bezeichnung', $label)
            ->notEmpty()
            ->maxLength(80);
    }
}
