<?php

namespace Z77\Module\Debtor\Entities;

use Z77\Module\Debtor\Services\Iban;
use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * WHERE a customer pays (plan §6.1): one bank account of the company — its
 * plain IBAN and / or its QR-IBAN, the ACCOUNT HOLDER as registered with
 * the bank (the creditor block of the QR-bill), and the ledger account that
 * same bank account is booked on, so a payment (P4) knows which account to
 * debit without a second setting.
 *
 * Installation master data, file-based (a handful of rows). Deliberately
 * NOT seeded: an IBAN cannot be guessed, and a seeded placeholder would end
 * up printed on a QR-bill. The file comes into being with the first row the
 * backend writes.
 *
 * The reference rule (ADR-043 decision 19): payments and documents
 * reference a target BY `code`; a row is DEACTIVATED, never deleted, and
 * the code is immutable once created.
 *
 * **Two IBAN fields** (owner 2026-09-23, built in P3 part 3): two numbering
 * systems. The QR-IBAN (IID 30000–31999, {@see Iban::isQrIban()}) ALWAYS
 * carries a QR reference (QRR); the plain IBAN carries NONE (reference type
 * NON). Both optional, at least one set. The USE CASE decides which one a
 * bill prints: a document with a reference (an invoice with its number) →
 * the QR-IBAN + QRR when the target has one, else the plain IBAN + NON with
 * the document named in the message; a QR-IBAN is NEVER printed with NON
 * (banks reject it) — `Invoicing\PaymentSnapshot::forDocument()`.
 *
 * **The creditor block** (owner 2026-09-23): name, street, house number,
 * zip, city and country of the ACCOUNT HOLDER, in the lengths of the Swiss
 * QR specification (70 / 70 / 16 / 16 / 35 / 2, structured address «S»).
 * It belongs to the target, not to the mandator: the account designation
 * often differs from the company name, and a QR-bill naming another creditor
 * than the account holder is faulty. An EMPTY field falls back to the
 * mandator's address field by field (wdv-630's `picDepositor*()` model —
 * the normal case is that the holder IS the company); the country follows
 * the same rule (wdv read it from the mandator unconditionally — not taken
 * over). The effective block is `Services\Creditor::of()`; the screen shows
 * it, so nobody learns only from a printed bill which name lands on it.
 */
#[Entity('file', 'framework/debtor/payment_targets.json')]
class PaymentTarget
{
    use ArrayMappable { mapFromArray as private mapFields; }

    /** The lengths of the creditor fields in the Swiss QR specification (Implementation Guidelines QR-bill v2.x, «Cdtr»). */
    public const HOLDER_NAME_LENGTH     = 70;
    public const HOLDER_STREET_LENGTH   = 70;
    public const HOLDER_HOUSE_NO_LENGTH = 16;
    public const HOLDER_ZIP_LENGTH      = 16;
    public const HOLDER_CITY_LENGTH     = 35;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation. */
    #[Clean('ident')]
    private string $code = '';

    /** German, for the backend list and the document («Postkonto», «Bank XY»). */
    #[Clean('text')]
    private string $label = '';

    /** The PLAIN IBAN (reference type NON), normalized: upper-case, no spaces. Empty = none. Never a QR-IBAN. */
    #[Clean('text')]
    private string $iban = '';

    /** The QR-IBAN (reference type QRR), normalized. Empty = none. Always a QR-IBAN. */
    #[Clean('text')]
    private string $qrIban = '';

    /** Account holder as registered with the bank — empty = the mandator's name. */
    #[Clean('text')]
    private string $holderName = '';

    #[Clean('text')]
    private string $holderStreet = '';

    #[Clean('text')]
    private string $holderHouseNo = '';

    #[Clean('text')]
    private string $holderZip = '';

    #[Clean('text')]
    private string $holderCity = '';

    /** ISO 3166-1 alpha-2 of the HOLDER — empty = the mandator's country. */
    #[Clean('text')]
    private string $holderCountry = '';

    /**
     * The ledger account number this bank account is booked on (`1020` in
     * the KMU chart). A NUMBER, not an id — the same reference shape the
     * mandator's account settings use. Checked against module-financial when
     * that module is installed
     * ({@see \Z77\Module\Mandator\Services\LedgerAccountCheck}); financial is
     * only `suggest`ed, so the check is soft by construction.
     */
    #[Clean('text')]
    private string $accountNumber = '';

    /** False = not offered for a NEW reference; existing ones still resolve. */
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /**
     * The fields, then ONE legacy rule (review 2026-09-30): before P3 part 3
     * `iban` was the only IBAN field and accepted a QR-IBAN. A row that
     * carries NO `qr_iban` key at all (written before the field existed)
     * and a QR-IBAN in `iban` is read as what it means — the QR-IBAN moves
     * to `qrIban`, `iban` becomes empty — so it snapshots QRR instead of a
     * QR-IBAN with NON, and the next save writes the new shape. A form post
     * always carries both keys and is NOT moved: a QR-IBAN typed into the
     * IBAN field is refused by the validator.
     */
    public function mapFromArray(array $data): void
    {
        $this->mapFields($data);
        if (!array_key_exists('qr_iban', $data) && $this->qrIban === '' && Iban::isQrIban($this->iban)) {
            $this->qrIban = $this->iban;
            $this->iban   = '';
        }
    }

    /** The one normalization every code comparison relies on. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getIban(): string { return $this->iban; }
    public function getQrIban(): string { return $this->qrIban; }
    public function getHolderName(): string { return $this->holderName; }
    public function getHolderStreet(): string { return $this->holderStreet; }
    public function getHolderHouseNo(): string { return $this->holderHouseNo; }
    public function getHolderZip(): string { return $this->holderZip; }
    public function getHolderCity(): string { return $this->holderCity; }
    public function getHolderCountry(): string { return $this->holderCountry; }
    public function getAccountNumber(): string { return $this->accountNumber; }
    public function isActive(): bool { return $this->active; }

    /** Whether this target can carry a QR reference (it has a QR-IBAN). */
    public function hasQrIban(): bool
    {
        return $this->qrIban !== '';
    }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setIban(string $iban): void { $this->iban = Iban::normalize($iban); }
    public function setQrIban(string $iban): void { $this->qrIban = Iban::normalize($iban); }
    public function setHolderName(string $value): void { $this->holderName = trim($value); }
    public function setHolderStreet(string $value): void { $this->holderStreet = trim($value); }
    public function setHolderHouseNo(string $value): void { $this->holderHouseNo = trim($value); }
    public function setHolderZip(string $value): void { $this->holderZip = trim($value); }
    public function setHolderCity(string $value): void { $this->holderCity = trim($value); }
    public function setHolderCountry(string $value): void { $this->holderCountry = mb_strtoupper(trim($value)); }
    public function setAccountNumber(string $number): void { $this->accountNumber = trim($number); }
    public function setActive(bool $active): void { $this->active = $active; }
}
