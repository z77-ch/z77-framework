<?php

namespace Z77\Module\Receipt\Validators;

use Z77\Persistence\File\Repository\FileRepository;

/**
 * The two fields every file-based master-data row of this module has — receipt template and
 * payment method — with the identical rule: a `code` `[a-z][a-z0-9-]{1,15}`, unique
 * (case-insensitive; the key receipts carry, ADR-043 decision 19), and a required `label` of at
 * most 80 characters. The using validator names the collection ({@see masterDataRows()}); null =
 * format-only (the uniqueness check is skipped).
 */
trait ValidatesMasterDataRow
{
    public const CODE_MIN_LENGTH  = 2;
    public const CODE_MAX_LENGTH  = 16;
    public const LABEL_MAX_LENGTH = 80;

    abstract protected function masterDataRows(): ?FileRepository;

    public function validateCode(string $code): void
    {
        $this->validate('code', 'Code', $code)
            ->notEmpty()
            ->minLength(self::CODE_MIN_LENGTH)
            ->maxLength(self::CODE_MAX_LENGTH);
        if (!$this->hasFieldError('code') && !preg_match('/^[a-z][a-z0-9-]*$/', $code)) {
            $this->addFieldError('code', 'Code: Kleinbuchstaben (a–z), Ziffern und Bindestrich, mit einem Buchstaben beginnend.');
        }
        $rows = $this->masterDataRows();
        if ($this->hasFieldError('code') || $rows === null) {
            return;
        }
        $ownId = $this->entity->getId();
        foreach ($rows->findAll() as $other) {
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
            ->maxLength(self::LABEL_MAX_LENGTH);
    }
}
