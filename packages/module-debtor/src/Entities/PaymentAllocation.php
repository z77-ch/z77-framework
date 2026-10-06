<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * What one {@see Payment} clears on one FINAL invoice (plan §6.3): an
 * amount of a {@see AllocationKind} — payment, discount or loss. The open
 * amount of an invoice is DERIVED: gross − Σ final credit notes − Σ
 * allocations of every kind (`InvoicingService::openAmount()`); nothing
 * stores it in parallel (plan §6.3: «derived, not stored»).
 *
 * Each allocation is posted ONCE through the accounting port inside the
 * unit of work that records it ({@see \Z77\Module\Debtor\Payments\PaymentPostingBuilder}),
 * and keeps the journal reference like a document does; `null` with the
 * Null gateway (bookkeeping elsewhere). Corrected only through
 * `PaymentService::update()` (owner 2026-10-06), which amends the posting
 * in place ({@see reallocate()}) or retracts it; no setters.
 *
 * Table `payment_allocation`; the mapping is the table definition
 * (`Version20261006150000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'payment_allocation')]
#[ORM\Index(name: 'idx_payment_allocation_invoice', columns: ['invoice_id'])]
class PaymentAllocation
{
    public const LEDGER_REF_LENGTH = 32;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Payment::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'payment_id', nullable: false)]
    private Payment $payment;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Invoice $invoice;

    /** Order within the payment, from 1. */
    #[ORM\Column]
    private int $position;

    /** An {@see AllocationKind} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $kind;

    /** Positive: clears that much of the invoice. (Negative = a reversal, P4 part 2.) */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    /** `{fiscal-year}/{number}` of the journal entry, once posted; null with the Null gateway. */
    #[ORM\Column(name: 'ledger_entry_ref', length: self::LEDGER_REF_LENGTH, nullable: true)]
    private ?string $ledgerEntryRef = null;

    private bool $posted = false;

    public function __construct(Payment $payment, Invoice $invoice, int $position, AllocationKind $kind, Money $amount)
    {
        if ($amount->isZero()) {
            throw new \InvalidArgumentException('An allocation of 0.00 clears nothing');
        }
        $this->payment  = $payment;
        $this->invoice  = $invoice;
        $this->position = $position;
        $this->kind     = $kind->value;
        $this->amount   = $amount;
    }

    /** Records the posting's reference (null with the Null gateway) — once, by the service inside its unit of work. */
    public function markPosted(?string $ledgerEntryRef): void
    {
        if ($this->posted) {
            throw new \LogicException('An allocation is posted once');
        }
        $this->posted         = true;
        $this->ledgerEntryRef = $ledgerEntryRef;
    }

    /**
     * A corrected amount (owner 2026-10-06, `PaymentService::update()`): the
     * row keeps its id — and with it the idempotency key of its posting,
     * which the service AMENDS in place, so the journal number stays.
     */
    public function reallocate(Money $amount): void
    {
        if ($amount->isZero()) {
            throw new \InvalidArgumentException('An allocation of 0.00 clears nothing — withdraw it instead');
        }
        $this->amount = $amount;
    }

    public function getId(): ?int { return $this->id; }
    public function getPayment(): Payment { return $this->payment; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getPosition(): int { return $this->position; }
    public function getAmount(): Money { return $this->amount; }
    public function getLedgerEntryRef(): ?string { return $this->ledgerEntryRef; }

    public function kind(): AllocationKind
    {
        return AllocationKind::from($this->kind);
    }
}
