<?php

namespace Z77\Module\Receipt\Validators;

use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Repositories\PaymentMethodRepository;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see PaymentMethod} («Bezahlt mit», owner decision E10): code and label
 * ({@see ValidatesMasterDataRow}); the credit account REQUIRED and an account that takes a new
 * posting ({@see ReceiptFields::account()}). Without the entity manager the shape only.
 */
class PaymentMethodValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    public function __construct(
        PaymentMethod $method,
        private readonly ?PaymentMethodRepository $repo = null,
        private readonly ?UnifiedEntityManager $em = null,
    ) {
        parent::__construct($method);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    public function validateCreditAccount(string $number): void
    {
        $this->validate('credit_account', 'Haben', $number)->notEmpty();
        if ($this->hasFieldError('credit_account') || $this->em === null) {
            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->account($number, 'credit_account', $errors);
        foreach ($errors as $field => $message) {
            $this->addFieldError($field, $message);
        }
    }
}
