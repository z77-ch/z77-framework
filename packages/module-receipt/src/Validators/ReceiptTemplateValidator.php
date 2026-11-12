<?php

namespace Z77\Module\Receipt\Validators;

use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Module\Receipt\Repositories\ReceiptTemplateRepository;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see ReceiptTemplate} for the backend and for any other writer.
 *
 *   - code and label ({@see ValidatesMasterDataRow});
 *   - icon: '' or `[a-z0-9-]`, at most 32 (a key, not markup);
 *   - debit REQUIRED (a template is decided in full, E2), an account that takes a new posting
 *     ({@see ReceiptFields::account()}); the credit side is the payment method's (E10);
 *   - tax code: '' (without VAT) or an active code that splits a gross amount
 *     ({@see ReceiptFields::taxCode()}).
 *
 * Without the entity manager only the shape is checked — the format-only mode a screen uses for an
 * empty form. Whether a template together with a payment method can post (e.g. both sides P&L with
 * VAT) is judged per receipt by the posting and its preview, not here.
 */
class ReceiptTemplateValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    public const ICON_MAX_LENGTH = 32;

    public function __construct(
        ReceiptTemplate $template,
        private readonly ?ReceiptTemplateRepository $repo = null,
        private readonly ?UnifiedEntityManager $em = null,
    ) {
        parent::__construct($template);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    public function validateIcon(string $icon): void
    {
        if ($icon !== '' && (mb_strlen($icon) > self::ICON_MAX_LENGTH || !preg_match('/^[a-z0-9-]+$/', $icon))) {
            $this->addFieldError('icon', 'Symbol: Kleinbuchstaben, Ziffern und Bindestrich, höchstens ' . self::ICON_MAX_LENGTH . ' Zeichen.');
        }
    }

    public function validateDebitAccount(string $number): void
    {
        $this->validate('debit_account', 'Soll', $number)->notEmpty();
        if ($this->hasFieldError('debit_account') || $this->em === null) {
            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->account($number, 'debit_account', $errors);
        foreach ($errors as $field => $message) {
            $this->addFieldError($field, $message);
        }
    }

    public function validateTaxCode(string $code): void
    {
        if ($this->em === null) {
            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->taxCode($code, 'tax_code', $errors);
        foreach ($errors as $field => $message) {
            $this->addFieldError($field, $message);
        }
    }
}
