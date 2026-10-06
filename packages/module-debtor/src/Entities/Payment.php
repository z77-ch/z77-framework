<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One SETTLEMENT EVENT on the receivables side (plan §6.3, P4 part 1): a
 * payment that arrived on a payment target (bank account), or a write-off
 * without money — each with its ALLOCATIONS to final invoices
 * ({@see PaymentAllocation}: payment / discount / loss). «Zahlung 100.00
 * auf Rechnung 12 plus 8.10 Skonto» is ONE payment with two allocations;
 * one bank receipt covering three invoices is one payment with three.
 *
 * Immutable once recorded: a wrong payment is not edited — it is reversed
 * by a counter payment (negative allocation, P4 part 2) — and a payment is
 * never deleted (its allocations are posted). There are no setters.
 *
 * `amount` is the MONEY that moved (0.00 for a pure write-off); the
 * allocations of kind `payment` sum to it exactly — the service refuses
 * anything else. `paymentTargetCode` names the bank account the money
 * arrived on (by code, no foreign key, ADR-043 decision 19; '' with
 * 0.00). `sourceType` / `sourceRef` say where the payment came from
 * (`manual`, or a CAMT.054 transaction in part 2 — dedup lives there).
 *
 * Table `payment` — the mapping is the table definition
 * (`Version20261006150000` creates it identically, `z77-db diff` sees no
 * change).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_date', columns: ['payment_date'])]
class Payment
{
    public const TARGET_CODE_LENGTH = 16;
    public const NOTE_LENGTH        = 140;
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;
    public const ACTOR_LENGTH       = 80;

    public const SOURCE_MANUAL = 'manual';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The value date — the day the money arrived, or the day of the write-off. */
    #[ORM\Column(name: 'payment_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The money that moved; 0.00 for a write-off. Base currency (`MoneyType`). */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    /** {@see PaymentTarget::$code} — the bank account; '' when no money moved. */
    #[ORM\Column(name: 'payment_target_code', length: self::TARGET_CODE_LENGTH)]
    private string $paymentTargetCode = '';

    #[ORM\Column(length: self::NOTE_LENGTH, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'source_type', length: self::SOURCE_TYPE_LENGTH)]
    private string $sourceType = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'source_ref', length: self::SOURCE_REF_LENGTH, nullable: true)]
    private ?string $sourceRef = null;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PaymentAllocation> in position order */
    #[ORM\OneToMany(targetEntity: PaymentAllocation::class, mappedBy: 'payment', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $allocations;

    public function __construct(\DateTimeImmutable $date, Money $amount, string $paymentTargetCode, ?string $note, string $createdBy, \DateTimeImmutable $createdAt, string $sourceType = self::SOURCE_MANUAL, ?string $sourceRef = null)
    {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('A payment amount is not negative — a reversal is a counter allocation');
        }
        $this->date              = $date;
        $this->amount            = $amount;
        $this->paymentTargetCode = PaymentTarget::normalizeCode($paymentTargetCode);
        $this->note              = $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, self::NOTE_LENGTH);
        $this->createdBy         = $createdBy;
        $this->createdAt         = $createdAt;
        $this->sourceType        = $sourceType;
        $this->sourceRef         = $sourceRef;
        $this->allocations       = new ArrayCollection();
    }

    /** Adds an allocation — by the service, before the payment is persisted. */
    public function allocate(Invoice $invoice, AllocationKind $kind, Money $amount): PaymentAllocation
    {
        $allocation = new PaymentAllocation($this, $invoice, $this->allocations->count() + 1, $kind, $amount);
        $this->allocations->add($allocation);

        return $allocation;
    }

    public function getId(): ?int { return $this->id; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getAmount(): Money { return $this->amount; }
    public function getPaymentTargetCode(): string { return $this->paymentTargetCode; }
    public function getNote(): ?string { return $this->note; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return list<PaymentAllocation> */
    public function getAllocations(): array
    {
        return $this->allocations->toArray();
    }

    /** Σ of the allocations of $kind (all kinds when null). */
    public function allocated(?AllocationKind $kind = null): Money
    {
        $sum = Money::zero($this->amount->currency);
        foreach ($this->allocations as $allocation) {
            if ($kind === null || $allocation->kind() === $kind) {
                $sum = $sum->add($allocation->getAmount());
            }
        }

        return $sum;
    }
}
