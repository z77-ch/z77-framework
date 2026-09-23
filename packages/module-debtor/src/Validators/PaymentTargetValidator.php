<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
use Z77\Module\Debtor\Services\Iban;
use Z77\Module\Debtor\Services\LedgerAccountCheck;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see PaymentTarget} for the backend and for any other writer.
 *
 * Code: `[a-z][a-z0-9-]{1,15}`, unique. Label required.
 *
 * IBAN — three checks, in this order, so the message says what is actually
 * wrong ({@see Iban}): shape, then the MOD-97-10 check digits (what catches
 * a transposed pair), then CH / LI origin — a QR-bill names a Swiss or
 * Liechtenstein creditor account, nothing else. The IBAN is unique across
 * the rows as well: two targets on one account would make a payment
 * ambiguous (P4). A QR-IBAN (IID 30000–31999) is accepted like any other and
 * only SHOWN as such; which reference it forces (QRR vs. SCOR) is part 2's
 * business.
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
        $this->validate('iban', 'IBAN', $iban)->notEmpty();
        if ($this->hasFieldError('iban')) {
            return;
        }

        if (!Iban::isWellFormed($iban)) {
            $this->addFieldError('iban', 'IBAN: zwei Landesbuchstaben, zwei Prüfziffern, dann Buchstaben und Ziffern (Schweiz und Liechtenstein: 21 Zeichen).');
            return;
        }
        if (!Iban::hasValidCheckDigits($iban)) {
            $this->addFieldError('iban', 'Die Prüfziffern der IBAN stimmen nicht — bitte Zeichen für Zeichen vergleichen.');
            return;
        }
        if (!Iban::isSwissArea($iban)) {
            $this->addFieldError('iban', 'Ein Zahlungsziel trägt eine schweizerische oder liechtensteinische IBAN — nur die kann auf einem QR-Einzahlungsschein stehen.');
            return;
        }

        if ($this->repo === null) {
            return;
        }
        $ownId = $this->entity->getId();
        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() !== $ownId && $other->getIban() === Iban::normalize($iban)) {
                $this->addFieldError('iban', 'Diese IBAN ist bereits als Zahlungsziel «' . $other->getCode() . '» erfasst.');
                return;
            }
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
}
