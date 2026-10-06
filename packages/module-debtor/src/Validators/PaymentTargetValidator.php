<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Invoicing\QrBill;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
use Z77\Module\Debtor\Services\Iban;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see PaymentTarget} for the backend and for any other writer.
 *
 * Code: `[a-z][a-z0-9-]{1,15}`, unique. Label required.
 *
 * The two IBAN fields — both optional, AT LEAST ONE set (owner 2026-09-23).
 * Each is checked in this order, so the message says what is actually wrong
 * ({@see Iban}): shape, the MOD-97-10 check digits (what catches a
 * transposed pair), CH / LI origin — a QR-bill names a Swiss or
 * Liechtenstein creditor account, nothing else — and then its KIND: the
 * plain field refuses a QR-IBAN, the QR field refuses a plain IBAN (the
 * IID 30000–31999, {@see Iban::isQrIban()}). A number is unique across both
 * fields of every row: two targets on one account would make a payment
 * ambiguous (P4).
 *
 * The creditor block (the account holder): every field optional — an empty
 * one falls back to the mandator's — at most the lengths of the Swiss QR
 * specification and only characters the QR-bill may carry
 * ({@see QrBill::isAllowedText()}); the country two capital letters.
 *
 * Ledger account: digits, at most 10 — the shape financial's `Account`
 * stores. Whether it exists and may be posted to is asked of financial when
 * that module is installed ({@see LedgerAccountCheck}); without it the
 * number stays unverified and NO error is raised, because financial is only
 * `suggest`ed (ADR-040 decision 5).
 *
 * Without the repository the uniqueness checks are skipped (format-only);
 * without the ledger check the account number is format-checked only.
 */
class PaymentTargetValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    /** The longest account number financial's `Account::NUMBER_LENGTH` holds — repeated, not imported: financial may be absent. */
    public const ACCOUNT_NUMBER_LENGTH = 10;

    public function __construct(
        PaymentTarget $target,
        private ?PaymentTargetRepository $repo = null,
        private ?LedgerAccountCheck $ledger = null,
    ) {
        parent::__construct($target);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    public function validateIban(string $iban): void
    {
        $this->validateAccountIban('iban', $iban, false);
    }

    public function validateQrIban(string $iban): void
    {
        $this->validateAccountIban('qr_iban', $iban, true);

        if ($iban === '' && $this->entity->getIban() === '' && !$this->hasFieldError('iban')) {
            $this->addFieldError('iban', 'Mindestens eine IBAN oder eine QR-IBAN erfassen.');
        }
    }

    public function validateHolderName(string $value): void { $this->validateHolderText('holder_name', 'Kontoinhaber', $value, PaymentTarget::HOLDER_NAME_LENGTH); }
    public function validateHolderStreet(string $value): void { $this->validateHolderText('holder_street', 'Strasse', $value, PaymentTarget::HOLDER_STREET_LENGTH); }
    public function validateHolderHouseNo(string $value): void { $this->validateHolderText('holder_house_no', 'Hausnummer', $value, PaymentTarget::HOLDER_HOUSE_NO_LENGTH); }
    public function validateHolderZip(string $value): void { $this->validateHolderText('holder_zip', 'PLZ', $value, PaymentTarget::HOLDER_ZIP_LENGTH); }
    public function validateHolderCity(string $value): void { $this->validateHolderText('holder_city', 'Ort', $value, PaymentTarget::HOLDER_CITY_LENGTH); }

    public function validateHolderCountry(string $value): void
    {
        if ($value !== '' && !preg_match('/^[A-Z]{2}$/', $value)) {
            $this->addFieldError('holder_country', 'Land: zwei Buchstaben nach ISO 3166 (z.B. CH).');
        }
    }

    public function validateAccountNumber(string $number): void
    {
        $this->validate('account_number', 'Konto', $number)
            ->notEmpty()
            ->maxLength(self::ACCOUNT_NUMBER_LENGTH);
        if ($this->hasFieldError('account_number')) {
            return;
        }

        if (!preg_match('/^[0-9]+$/', $number)) {
            $this->addFieldError('account_number', 'Konto: nur Ziffern (z.B. 1020).');
            return;
        }

        // Soft by construction: null = module-financial is not installed.
        if ($this->ledger?->isPostable($number) === false) {
            $this->addFieldError('account_number', 'Konto ' . $number . ' gibt es in der Buchhaltung nicht, es ist eine Gruppe oder inaktiv.');
        }
    }

    private function validateAccountIban(string $field, string $iban, bool $qr): void
    {
        if ($iban === '') {
            return;
        }
        $label = $qr ? 'QR-IBAN' : 'IBAN';
        if (!Iban::isWellFormed($iban)) {
            $this->addFieldError($field, $label . ': zwei Landesbuchstaben, zwei Prüfziffern, dann Buchstaben und Ziffern (Schweiz und Liechtenstein: 21 Zeichen).');
            return;
        }
        if (!Iban::hasValidCheckDigits($iban)) {
            $this->addFieldError($field, 'Die Prüfziffern der ' . $label . ' stimmen nicht — bitte Zeichen für Zeichen vergleichen.');
            return;
        }
        if (!Iban::isSwissArea($iban)) {
            $this->addFieldError($field, 'Ein Zahlungsziel trägt eine schweizerische oder liechtensteinische ' . $label . ' — nur die kann auf einem QR-Einzahlungsschein stehen.');
            return;
        }
        if ($qr && !Iban::isQrIban($iban)) {
            $this->addFieldError($field, 'Das ist keine QR-IBAN (die Stellen 5–9 liegen nicht zwischen 30000 und 31999) — eine normale IBAN gehört ins Feld IBAN.');
            return;
        }
        if (!$qr && Iban::isQrIban($iban)) {
            $this->addFieldError($field, 'Das ist eine QR-IBAN — sie gehört ins Feld QR-IBAN (sie trägt immer eine QR-Referenz).');
            return;
        }

        if ($this->repo === null) {
            return;
        }
        $normalized = Iban::normalize($iban);
        $ownId      = $this->entity->getId();
        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() !== $ownId && ($other->getIban() === $normalized || $other->getQrIban() === $normalized)) {
                $this->addFieldError($field, 'Diese ' . $label . ' ist bereits als Zahlungsziel «' . $other->getCode() . '» erfasst.');
                return;
            }
        }
    }

    private function validateHolderText(string $field, string $label, string $value, int $max): void
    {
        if ($value === '') {
            return;
        }
        $this->validate($field, $label, $value)->maxLength($max);
        if (!$this->hasFieldError($field) && !QrBill::isAllowedText($value)) {
            $this->addFieldError($field, $label . ': enthält Zeichen, die ein QR-Einzahlungsschein nicht drucken darf.');
        }
    }
}
