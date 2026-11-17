<?php

namespace Z77\Module\Receipt\Validators;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Repositories\PaymentMethodRepository;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see PaymentMethod} («Bezahlt mit», owner decision E10): code and label
 * ({@see ValidatesMasterDataRow}); the credit account REQUIRED and an account that takes a new
 * posting ({@see ReceiptFields::account()}) — UNLESS the method names a bank account
 * (`bank_account_code`, ADR-060 decision 9): then the bank account must exist and be active, and
 * the method needs no account of its own (the bank account carries it). Without the entity
 * manager the shape only.
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

    public function validateBankAccountCode(string $code): void
    {
        if ($code === '' || $this->em === null) {
            return;
        }
        $account = $this->em->getRepository(BankAccount::class)->findByCode($code);
        if ($account === null) {
            $this->addFieldError('bank_account_code', 'Bankkonto «' . $code . '» gibt es nicht — zuerst unter Stammdaten › Finanzen › Bankkonten anlegen.');
        } elseif (!$account->isActive()) {
            $this->addFieldError('bank_account_code', 'Bankkonto «' . $account->getLabel() . '» ist inaktiv.');
        }
    }

    public function validateCreditAccount(string $number): void
    {
        if ($this->entity->getBankAccountCode() !== '') {
            return;   // the bank account carries the ledger account; the service clears an own one on save
        }
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
