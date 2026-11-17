<?php

namespace Z77\Module\Financial\Validators;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Repositories\BankAccountRepository;
use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Payment\Iban;

/**
 * Validates a {@see BankAccount} (ADR-060 decisions 3, 8): the code (shape, unique), the label,
 * the IBAN (shape, check digits, unique — one IBAN is one bank account), the currency (stage 1:
 * the base currency only; a foreign-currency account is refused with the reason) and the ledger
 * account (exists, postable, active). Without the repositories the shape only — the references
 * are then the caller's to check.
 */
class BankAccountValidator extends EntityValidator
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9-]{1,15}$/';

    /**
     * @param string|null $baseCurrency the installation's base currency; null = the currency is not judged
     */
    public function __construct(
        BankAccount $account,
        private readonly ?BankAccountRepository $bankAccounts = null,
        private readonly ?AccountRepository $accounts = null,
        private readonly ?string $baseCurrency = null,
    ) {
        parent::__construct($account);
    }

    /** A conflict only the service or the database can see (the immutable code, a unique key under a race). */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validateCode(string $code): void
    {
        $this->validate('code', 'Kürzel', $code)->notEmpty();
        if ($this->hasFieldError('code')) {
            return;
        }
        if (!preg_match(self::CODE_PATTERN, $code)) {
            $this->addFieldError('code', 'Kürzel: Kleinbuchstaben, Ziffern und Bindestrich, 2 bis 16 Zeichen, mit einem Buchstaben beginnend (z.B. haus).');

            return;
        }
        $existing = $this->bankAccounts?->findByCode($code);
        if ($existing !== null && $existing->getId() !== $this->entity->getId()) {
            $this->addFieldError('code', 'Das Kürzel «' . $code . '» trägt bereits das Bankkonto «' . $existing->getLabel() . '».');
        }
    }

    public function validateLabel(string $label): void
    {
        $this->validate('label', 'Bezeichnung', $label)->notEmpty()->maxLength(BankAccount::LABEL_LENGTH);
    }

    public function validateIban(string $iban): void
    {
        $this->validate('iban', 'IBAN', $iban)->notEmpty();
        if ($this->hasFieldError('iban')) {
            return;
        }
        if (!Iban::isWellFormed($iban) || !Iban::hasValidCheckDigits($iban)) {
            $this->addFieldError('iban', 'Die IBAN ist ungültig (Aufbau oder Prüfziffer).');

            return;
        }
        $existing = $this->bankAccounts?->findByIban($iban);
        if ($existing !== null && $existing->getId() !== $this->entity->getId()) {
            $this->addFieldError('iban', 'Diese IBAN trägt bereits das Bankkonto «' . $existing->getLabel() . '».');
        }
    }

    public function validateCurrency(string $currency): void
    {
        $this->validate('currency', 'Währung', $currency)->notEmpty();
        if ($this->hasFieldError('currency')) {
            return;
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->addFieldError('currency', 'Währung: drei Buchstaben nach ISO 4217 (z.B. CHF).');

            return;
        }
        if ($this->baseCurrency !== null && $currency !== $this->baseCurrency) {
            $this->addFieldError('currency', 'Ein Bankkonto in ' . $currency . ' kann noch nicht geführt werden — die Buchhaltung läuft in ' . $this->baseCurrency
                . ', Fremdwährungskonten folgen in einer späteren Ausbaustufe. Eine Zahlungsart für dieses Konto behält bis dahin ihr eigenes Haben-Konto.');
        }
    }

    /** «Abgleich ab»: what the form posted and the entity could not read as a date (`BankAccount::setReconcileFrom()`). */
    public function validateReconcileFromInput(string $raw): void
    {
        if ($raw !== '') {
            $this->addFieldError('reconcile_from', 'Abgleich ab: «' . $raw . '» ist kein Datum (TT.MM.JJJJ).');
        }
    }

    public function validateLedgerAccount(string $number): void
    {
        $this->validate('ledger_account', 'Konto', $number)->notEmpty()->maxLength(Account::NUMBER_LENGTH);
        if ($this->hasFieldError('ledger_account') || $this->accounts === null) {
            return;
        }
        $account = $this->accounts->findOneBy(['number' => $number]);
        if (!$account instanceof Account) {
            $this->addFieldError('ledger_account', 'Konto ' . $number . ' gibt es im Kontenplan nicht.');
        } elseif (!$account->isPostable()) {
            $this->addFieldError('ledger_account', 'Konto ' . $account->label() . ' ist eine Gruppe — ein Bankkonto braucht ein bebuchbares Konto.');
        } elseif (!$account->isActive()) {
            $this->addFieldError('ledger_account', 'Konto ' . $account->label() . ' ist inaktiv.');
        }
    }
}
