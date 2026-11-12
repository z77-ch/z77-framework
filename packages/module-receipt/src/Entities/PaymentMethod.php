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
 * Installation master data, FILE-based — the same storage and rules as {@see ReceiptTemplate}:
 * `data/framework/receipt/payment_methods.json`, not seeded (the account depends on the chart),
 * written through {@see \Z77\Module\Receipt\Services\PaymentMethodService}, referenced BY `code`
 * (ADR-043 decision 19), deactivated never deleted, code immutable.
 */
#[Entity('file', 'framework/receipt/payment_methods.json')]
class PaymentMethod
{
    use ArrayMappable;

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

    #[Clean('int')]
    private int $position = 0;

    /** False = not offered any more; receipts captured with it keep the code. */
    #[Clean('bool')]
    private bool $active = true;

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
    public function getCreditAccount(): string { return $this->creditAccount; }
    public function getPosition(): int { return $this->position; }
    public function isActive(): bool { return $this->active; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function setCreditAccount(string $number): void { $this->creditAccount = trim($number); }
    public function setPosition(int $position): void { $this->position = $position; }
    public function setActive(bool $active): void { $this->active = $active; }
}
