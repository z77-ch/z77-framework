<?php

namespace Z77\Module\Creditor\Validators;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Mandator\Services\Uid;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Repositories\PaymentMethodRepository;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Payment\Iban;

/**
 * Validates a {@see CreditorProfile} and the IBAN rows that go with it (creditor plan §2.2, §5).
 *
 *   - contact: required, persisted, ACTIVE for a NEW profile (ADR-043 decision 19 on the party — an
 *     existing profile stays editable after its contact was deactivated); at most one profile per
 *     contact (asked here, decided by `uniq_creditor_profile_contact`);
 *   - UID: optional; the canonical shape and the modulo-11 check digit (module-mandator's
 *     {@see Uid}); no other creditor may carry it (`uniq_creditor_profile_uid`);
 *   - the defaults, each optional, each judged only when it CHANGES (a NEW reference needs an
 *     active row; an unchanged one keeps a row deactivated since — ADR-043 decision 19): the
 *     account through module-receipt's {@see ReceiptFields::account()} (exists, postable, active,
 *     no VAT account of the mandator while they are locked — that one is refused even unchanged:
 *     a learned 1170 does not survive a save, ADR-042 addendum 2 of 2026-11-25, point 7),
 *     the tax code through {@see ReceiptFields::taxCode()} (active, splits a gross amount, no
 *     input-tax correction VE / VK / VN) — and
 *     refused while the mandator is NOT liable to VAT (owner decision 5 of 2026-11-15: a
 *     non-liable company books every creditor line «ohne MWST»); a tax code and «ohne MWST» never
 *     together; the payment method must exist and be active ({@see PaymentMethod});
 *   - IBAN rows ({@see validateIbanRows()}): each a well-formed IBAN with valid MOD-97 check digits
 *     (any country — a supplier abroad has a foreign one), listed once per creditor, the hint at
 *     most {@see CreditorIban::HINT_LENGTH} characters. An IBAN another creditor already carries
 *     is ALLOWED (shared IBAN, plan §2.2).
 *
 * Without the entity manager only the shape is checked — the format-only mode a screen uses to
 * render an empty form.
 */
class CreditorProfileValidator extends EntityValidator
{
    /**
     * @param ?CreditorProfile $stored the managed profile BEFORE the change (null = a new profile) — what «unchanged» compares with
     * @param ?list<array{iban: string, hint: string, active: bool}> $ibanRows null = the IBANs are not part of this write
     * @param bool $liableToVat the mandator's VAT liability (module-mandator)
     */
    public function __construct(
        CreditorProfile $profile,
        private readonly ?UnifiedEntityManager $em = null,
        private readonly ?CreditorProfile $stored = null,
        private readonly ?array $ibanRows = null,
        private readonly bool $liableToVat = true,
    ) {
        parent::__construct($profile);
    }

    /** A race the database decided, mapped onto the field the window shows. */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    protected function executeValidation(?array $only = null): void
    {
        parent::executeValidation($only);
        if ($this->ibanRows !== null && ($only === null || in_array('ibans', $only, true))) {
            $this->validateIbanRows($this->ibanRows);
        }
    }

    public function validateContact(?Contact $contact): void
    {
        if ($contact === null || $contact->getId() === null) {
            $this->addFieldError('contact_id', 'Ohne Kontakt gibt es keinen Kreditor — bitte einen gespeicherten Kontakt wählen.');

            return;
        }
        if ($this->entity->getId() === null && !$contact->isActive()) {
            $this->addFieldError('contact_id', 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Kreditor angelegt.');

            return;
        }
        $existing = $this->profiles()?->findByContact($contact);
        if ($existing !== null && $existing->getId() !== $this->entity->getId()) {
            $this->addFieldError('contact_id', '«' . $contact->displayName() . '» ist bereits Kreditor.');
        }
    }

    public function validateBankText(?string $text): void
    {
        if ($text === null) {
            return;
        }
        if (mb_strlen($text) < CreditorProfile::BANK_TEXT_MIN) {
            $this->addFieldError('bank_text', 'Banktext: mindestens ' . CreditorProfile::BANK_TEXT_MIN . ' Zeichen — sonst passt er auf fast jede Bewegung.');
        } elseif (mb_strlen($text) > CreditorProfile::BANK_TEXT_LENGTH) {
            $this->addFieldError('bank_text', 'Banktext: höchstens ' . CreditorProfile::BANK_TEXT_LENGTH . ' Zeichen.');
        }
    }

    /** «Kontierungstext» — the standard line text (owner 2026-11-17); as long as an invoice line's text. */
    public function validateDefaultLineText(?string $text): void
    {
        if ($text !== null && mb_strlen($text) > CreditorProfile::LINE_TEXT_LENGTH) {
            $this->addFieldError('default_line_text', 'Kontierungstext: höchstens ' . CreditorProfile::LINE_TEXT_LENGTH . ' Zeichen.');
        }
    }

    public function validateUid(?string $uid): void
    {
        if ($uid === null) {
            return;
        }
        if (!Uid::isWellFormed($uid)) {
            $this->addFieldError('uid', 'UID im Format CHE-123.456.789 eingeben (oder leer lassen).');

            return;
        }
        if (!Uid::hasValidCheckDigit($uid)) {
            $this->addFieldError('uid', 'Die UID ' . $uid . ' hat eine falsche Prüfziffer — bitte vom Beleg abschreiben.');

            return;
        }
        $other = $this->profiles()?->findByUid($uid);
        if ($other !== null && $other->getId() !== $this->entity->getId()) {
            $this->addFieldError('uid', 'Die UID ' . $uid . ' trägt schon der Kreditor «' . ($other->getContact()?->displayName() ?? '#' . $other->getId()) . '».');
        }
    }

    public function validateDefaultAccount(?string $number): void
    {
        if ($number === null || $this->em === null) {
            return;
        }
        if ($number === $this->stored?->getDefaultAccount()) {
            // Unchanged keeps a row deactivated since (ADR-043 decision 19) — but never one of the mandator's VAT
            // accounts once they are locked: a learned 1170 is refused on save (ADR-042 addendum 2 of 2026-11-25, point 7).
            $refusal = (new ReceiptFields($this->em))->vatAccountRefusal($number);
            if ($refusal !== null) {
                $this->addFieldError('default_account', $refusal);
            }

            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->account($number, 'default_account', $errors);
        $this->takeErrors($errors);
    }

    public function validateDefaultTaxCode(?string $code): void
    {
        if ($code === null) {
            return;
        }
        if ($this->entity->isDefaultWithoutVat()) {
            $this->addFieldError('default_tax', 'Entweder ein MWST-Code oder «ohne MWST» — nicht beides.');

            return;
        }
        if ($this->em === null || $code === $this->stored?->getDefaultTaxCode()) {
            return;
        }
        if (!$this->liableToVat) {
            $this->addFieldError('default_tax', 'Der Mandant ist nicht MWST-pflichtig — Rechnungen von Kreditoren werden ohne MWST gebucht.');

            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->taxCode($code, 'default_tax', $errors);
        $this->takeErrors($errors);
    }

    public function validateDefaultPaymentMethodCode(?string $code): void
    {
        if ($code === null) {
            return;
        }
        if (mb_strlen($code) > CreditorProfile::PAYMENT_METHOD_LENGTH) {
            $this->addFieldError('default_payment_method_code', 'Zahlungsart «' . $code . '» gibt es nicht.');

            return;
        }
        if ($this->em === null || $code === $this->stored?->getDefaultPaymentMethodCode()) {
            return;
        }
        /** @var PaymentMethodRepository $methods */
        $methods = $this->em->getRepository(PaymentMethod::class);
        $method  = $methods->findByCode($code);
        if ($method === null) {
            $this->addFieldError('default_payment_method_code', 'Zahlungsart «' . $code . '» gibt es nicht.');
        } elseif (!$method->isActive()) {
            $this->addFieldError('default_payment_method_code', 'Zahlungsart «' . $method->getLabel() . '» ist inaktiv.');
        }
    }

    /**
     * The IBAN rows as the window posts them: an empty IBAN is no row (the caller drops it). Errors
     * go under `iban_{n}` / `hint_{n}` — n the row's index in $rows.
     *
     * @param list<array{iban: string, hint: string, active: bool}> $rows
     */
    public function validateIbanRows(array $rows): void
    {
        $seen = [];
        foreach (array_values($rows) as $n => $row) {
            $iban = Iban::normalize((string) ($row['iban'] ?? ''));
            if (mb_strlen((string) ($row['hint'] ?? '')) > CreditorIban::HINT_LENGTH) {
                $this->addFieldError('hint_' . $n, 'Hinweis: höchstens ' . CreditorIban::HINT_LENGTH . ' Zeichen.');
            }
            if (!Iban::isWellFormed($iban)) {
                $this->addFieldError('iban_' . $n, 'Keine IBAN (zwei Buchstaben, zwei Ziffern, dann bis 30 Zeichen; CH und LI genau 21).');
                continue;
            }
            if (!Iban::hasValidCheckDigits($iban)) {
                $this->addFieldError('iban_' . $n, 'Die IBAN ' . Iban::format($iban) . ' hat eine falsche Prüfziffer — bitte vom Beleg abschreiben.');
                continue;
            }
            if (isset($seen[$iban])) {
                $this->addFieldError('iban_' . $n, 'Die IBAN ' . Iban::format($iban) . ' steht schon in Zeile ' . ($seen[$iban] + 1) . '.');
                continue;
            }
            $seen[$iban] = $n;
        }
    }

    private function profiles(): ?CreditorProfileRepository
    {
        return $this->em?->getRepository(CreditorProfile::class);
    }

    /** @param array<string, string> $errors */
    private function takeErrors(array $errors): void
    {
        foreach ($errors as $field => $message) {
            $this->addFieldError($field, $message);
        }
    }
}
