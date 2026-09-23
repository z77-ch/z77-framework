<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Persistence\File\Repository\FileRepository;

/**
 * The two fields every file-based master-data row of this module has, with
 * the identical rule (Rule 8): a `code` that is `[a-z][a-z0-9-]{1,15}` and
 * unique, and a required `label` of at most 80 characters.
 *
 * `code` is the key database rows and issued documents carry (ADR-043
 * decision 19); the entity lower-cases it at the setter, a hand-edited file
 * might not, so the uniqueness check is case-insensitive.
 *
 * The using validator says where the rows come from
 * ({@see masterDataRows()}); `null` means «no repository» and the
 * uniqueness check is skipped — the format-only mode every screen uses for
 * an empty form.
 */
trait ValidatesMasterDataRow
{
    public const CODE_MIN_LENGTH  = 2;
    public const CODE_MAX_LENGTH  = 16;
    public const LABEL_MAX_LENGTH = 80;

    /** The collection this row's code must be unique in — null = format-only. */
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
