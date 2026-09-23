<?php

namespace Z77\Module\Debtor\Entities;

use Z77\Module\Debtor\Services\Iban;
use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * WHERE a customer pays (plan §6.1): one bank account of the company — its
 * IBAN or QR-IBAN, and the ledger account that same bank account is booked
 * on, so a payment (P4) knows which account to debit without a second
 * setting.
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
 * QR-IBAN vs. IBAN ({@see Iban}): a QR-IBAN's institution identification
 * lies in 30000–31999 and it carries a QR reference (QRR); a normal IBAN
 * carries a creditor reference (SCOR) or none. Part 2 needs the difference
 * to print the right reference — this entity only records and shows it.
 */
#[Entity('file', 'framework/debtor/payment_targets.json')]
class PaymentTarget
{
    use ArrayMappable;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation. */
    #[Clean('ident')]
    private string $code = '';

    /** German, for the backend list and the document («Postkonto», «Bank XY»). */
    #[Clean('text')]
    private string $label = '';

    /** Normalized: upper-case, no spaces. Validated for shape, checksum and CH / LI origin. */
    #[Clean('text')]
    private string $iban = '';

    /**
     * The ledger account number this bank account is booked on (`1020` in
     * the KMU chart). A NUMBER, not an id — the same reference shape
     * `financialConfig → vatAccounts` and `debtorConfig → debtorAccounts`
     * use. Checked against module-financial when that module is installed
     * ({@see \Z77\Module\Debtor\Services\LedgerAccountCheck}); financial is
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

    /** The one normalization every code comparison relies on. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getIban(): string { return $this->iban; }
    public function getAccountNumber(): string { return $this->accountNumber; }
    public function isActive(): bool { return $this->active; }

    /** «CH93 0076 2011 6238 5295 7» — for a screen or a document. */
    public function formattedIban(): string
    {
        return Iban::format($this->iban);
    }

    /** Whether this target's IBAN is a QR-IBAN (QRR reference) rather than a plain one. */
    public function isQrIban(): bool
    {
        return Iban::isQrIban($this->iban);
    }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setIban(string $iban): void { $this->iban = Iban::normalize($iban); }
    public function setAccountNumber(string $number): void { $this->accountNumber = trim($number); }
    public function setActive(bool $active): void { $this->active = $active; }
}
