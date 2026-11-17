<?php

namespace Z77\Module\Receipt\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A PAYMENT METHOD of a receipt — «Bezahlt mit» on the phone (owner decision E10, 2026-11-12):
 * Kasse, Firmenkarte, privat ausgelegt. It knows the CREDIT account the receipt's amount is booked
 * against; the template knows the debit side and the VAT code. Picked next to the template, applied
 * at capture (`receipt.payment_method_code` + the resolved `credit_account`, still correctable in
 * the journal).
 *
 * A payment method that pays THROUGH A BANK names the bank account (`bank_account_code` →
 * module-financial's master data «Bankkonten», ADR-060 decision 9, owner decision 15 of
 * 2026-11-16): its credit account is then the bank account's ledger account — the ledger account
 * of a bank stands at ONE place (Rule 2) — and its own `credit_account` stays empty. A payment
 * method without a bank account (cash «Kasse», «privat ausgelegt») keeps its own account, as
 * before. {@see getCreditAccount()} answers the account either way; for a bank-bound method it
 * is known only after {@see \Z77\Module\Receipt\Services\PaymentMethodService} resolved it —
 * read payment methods through that service, not through the repository.
 *
 * Installation master data, FILE-based — the same storage and rules as {@see ReceiptTemplate}:
 * `data/framework/receipt/payment_methods.json`, not seeded (the account depends on the chart),
 * written through {@see \Z77\Module\Receipt\Services\PaymentMethodService}, referenced BY `code`
 * (ADR-043 decision 19), deactivated never deleted, code immutable.
 */
#[Entity('file', 'framework/receipt/payment_methods.json')]
class PaymentMethod
{
    use ArrayMappable {
        mapToArray as private allPropertiesToArray;
    }

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation — what `receipt.payment_method_code` holds. */
    #[Clean('ident')]
    private string $code = '';

    /** German, the choice on the phone («Kasse», «Firmenkarte», «privat ausgelegt»). */
    #[Clean('text')]
    private string $label = '';

    /** Account number (digits) the amount is credited to — cash, the card's clearing account, the owner's private account. */
    #[Clean('text')]
    private string $creditAccount = '';

    /**
     * Code of the bank account this method pays through (`BankAccount::$code`); '' = none — the
     * method keeps its own {@see $creditAccount}.
     */
    #[Clean('ident')]
    private string $bankAccountCode = '';

    #[Clean('int')]
    private int $position = 0;

    /** False = not offered any more; receipts captured with it keep the code. */
    #[Clean('bool')]
    private bool $active = true;

    /**
     * The ledger account of the bank account named by {@see $bankAccountCode}, as the service
     * resolved it — NEVER stored ({@see mapToArray()} leaves it out): the bank account is the one
     * source. Null = not resolved, or the bank account does not exist.
     */
    private ?string $bankLedgerAccount = null;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    /** The choice order: position, then id (an unsaved row last). */
    public static function compare(self $a, self $b): int
    {
        return [$a->position, $a->id ?? PHP_INT_MAX] <=> [$b->position, $b->id ?? PHP_INT_MAX];
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    /**
     * The account the amount is credited to: the bank account's ledger account for a bank-bound
     * method (resolved by the service; '' while unresolved — never a stale copy), else the
     * method's own account.
     */
    public function getCreditAccount(): string
    {
        return $this->bankAccountCode !== '' ? (string) $this->bankLedgerAccount : $this->creditAccount;
    }

    /** The method's OWN account as stored — '' for a bank-bound method. For the edit form and the validator. */
    public function getOwnCreditAccount(): string { return $this->creditAccount; }
    public function getBankAccountCode(): string { return $this->bankAccountCode; }
    public function getPosition(): int { return $this->position; }
    public function isActive(): bool { return $this->active; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function setCreditAccount(string $number): void { $this->creditAccount = trim($number); }
    public function setBankAccountCode(string $code): void { $this->bankAccountCode = mb_strtolower(trim($code)); }
    public function setPosition(int $position): void { $this->position = $position; }
    public function setActive(bool $active): void { $this->active = $active; }

    /** By `PaymentMethodService` only: the ledger account of the method's bank account, or null when there is none. */
    public function resolveBankLedgerAccount(?string $number): void
    {
        $this->bankLedgerAccount = $number === null || trim($number) === '' ? null : trim($number);
    }

    /** The stored row — every property except the resolved bank ledger account, which is never written. */
    public function mapToArray(): array
    {
        $row = $this->allPropertiesToArray();
        unset($row['bank_ledger_account']);

        return $row;
    }
}
