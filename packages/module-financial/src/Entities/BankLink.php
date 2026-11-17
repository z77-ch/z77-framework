<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * The LINK between a bank movement and what it pays (ADR-061 decision 9, stage C1): a creditor
 * invoice today, a debtor payment (C3), a receipt (C5) or a direct posting (C2) later. Financial
 * owns the link and never resolves its owner: `owner_key` is an opaque key the owning module
 * builds (`creditor:17` — the ledger's idempotency-key model), `owner_type` its first part (what
 * a list filters by), `owner_label` the words the list «Bewegungen» shows («Rechnung 4711 ·
 * Muster AG» — kept current by the owner through `BankLinkService::relabel()`).
 *
 * It stores an AMOUNT, not a yes / no: the rule today is ONE link per movement over its FULL
 * amount and one movement per owner — guarded by the two unique indexes and by
 * `BankLinkService::link()` under the movement's row lock. n:1 (a collective debit for several
 * invoices) and 1:n (an invoice paid in instalments) drop an index and relax the service; the
 * table stays.
 *
 * `basis` says how certain the link is (decision 11): {@see BASIS_REFERENCE} — an identity (the
 * QR reference), set without asking; {@see BASIS_PROPOSAL} — a unique likeness the office
 * confirmed; {@see BASIS_MANUAL} — picked by the office among candidates.
 *
 * Immutable but for the label; released by deleting the row, in the owner's unit of work
 * (decision 10). Table `bank_link` (`Version20261117100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_link')]
#[ORM\UniqueConstraint(name: BankLink::UNIQUE_MOVEMENT, columns: ['movement_id'])]
#[ORM\UniqueConstraint(name: BankLink::UNIQUE_OWNER, columns: ['owner_key'])]
class BankLink
{
    /** One link per movement — the rule of stage C1; dropped when n:1 is built. */
    public const UNIQUE_MOVEMENT = 'uniq_bank_link_movement';
    /** One movement per owner — the rule of stage C1; dropped when 1:n is built. */
    public const UNIQUE_OWNER = 'uniq_bank_link_owner';

    public const BASIS_REFERENCE = 'reference';
    public const BASIS_PROPOSAL  = 'proposal';
    public const BASIS_MANUAL    = 'manual';
    public const BASES = [self::BASIS_REFERENCE, self::BASIS_PROPOSAL, self::BASIS_MANUAL];

    public const OWNER_TYPE_LENGTH = 20;
    public const OWNER_KEY_LENGTH  = 60;
    public const LABEL_LENGTH      = 120;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankMovement::class)]
    #[ORM\JoinColumn(name: 'movement_id', nullable: false)]
    private BankMovement $movement;

    /** The owning module's kind of record: `creditor`, later `debtor-payment`, `receipt`, … */
    #[ORM\Column(name: 'owner_type', length: self::OWNER_TYPE_LENGTH)]
    private string $ownerType;

    /** `<owner type>:<the owner's id>` — opaque for financial. */
    #[ORM\Column(name: 'owner_key', length: self::OWNER_KEY_LENGTH)]
    private string $ownerKey;

    /** What the list «Bewegungen» shows — the owner's words, escaped on output. */
    #[ORM\Column(name: 'owner_label', length: self::LABEL_LENGTH)]
    private string $ownerLabel;

    /** How much of the movement this link takes — today always the movement's full amount. */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    /** {@see self::BASES}. */
    #[ORM\Column(length: 10)]
    private string $basis;

    #[ORM\Column(name: 'linked_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $linkedBy;

    #[ORM\Column(name: 'linked_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $linkedAt;

    public function __construct(BankMovement $movement, string $ownerType, string $ownerKey, string $ownerLabel, Money $amount, string $basis, string $linkedBy, \DateTimeImmutable $linkedAt)
    {
        if (!in_array($basis, self::BASES, true)) {
            throw new \LogicException("Unknown link basis '{$basis}'");
        }
        if (!self::isOwnerKey($ownerType, $ownerKey)) {
            throw new \LogicException("An owner key reads '<type>:<id>' and starts with its owner type — got '{$ownerKey}' for '{$ownerType}'");
        }
        if (!$amount->isPositive()) {
            throw new \LogicException('A link amount is positive');
        }
        $this->movement  = $movement;
        $this->ownerType = $ownerType;
        $this->ownerKey  = $ownerKey;
        $this->amount    = $amount;
        $this->basis     = $basis;
        $this->linkedBy  = mb_substr($linkedBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->linkedAt  = $linkedAt;
        $this->relabel($ownerLabel);
    }

    /** Whether $ownerKey is a key of $ownerType: `<type>:<id>`, both within their lengths. */
    public static function isOwnerKey(string $ownerType, string $ownerKey): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{1,' . (self::OWNER_TYPE_LENGTH - 1) . '}$/', $ownerType) === 1
            && str_starts_with($ownerKey, $ownerType . ':')
            && strlen($ownerKey) > strlen($ownerType) + 1
            && strlen($ownerKey) <= self::OWNER_KEY_LENGTH;
    }

    /** The owner's words changed (an invoice number corrected). */
    public function relabel(string $ownerLabel): void
    {
        $this->ownerLabel = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $ownerLabel)), 0, self::LABEL_LENGTH);
    }

    public function getId(): ?int { return $this->id; }
    public function getMovement(): BankMovement { return $this->movement; }
    public function getOwnerType(): string { return $this->ownerType; }
    public function getOwnerKey(): string { return $this->ownerKey; }
    public function getOwnerLabel(): string { return $this->ownerLabel; }
    public function getAmount(): Money { return $this->amount; }
    public function getBasis(): string { return $this->basis; }
    public function getLinkedBy(): string { return $this->linkedBy; }
    public function getLinkedAt(): \DateTimeImmutable { return $this->linkedAt; }
}
