<?php

namespace Z77\Module\Order\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;

/**
 * One row of an order's status LOG (plan §4.3 / §6, ADR-043 decision 4, owner O4: «jeder Wechsel
 * protokolliert — wer, wann, von → nach»): written in the same unit of work as the change itself,
 * never edited, never deleted. The timestamps of quote, confirmation, delivery and invoicing are
 * DERIVED from these rows (the window's «Verlauf»), never kept in parallel columns.
 *
 *   - `fromCode` — null at creation; `toCode` — both BY CODE into `OrderStatus` (no foreign key);
 *   - `trigger` — what caused it: {@see TRIGGER_CREATE} · {@see TRIGGER_STATUS} ·
 *     {@see TRIGGER_DELIVERY_NOTE} · {@see TRIGGER_BOOKING_PRINT} (column `change_trigger` —
 *     `TRIGGER` is a reserved word in MariaDB);
 *   - `reason` — the reason a stock REVERSAL was confirmed with (ADR-043 decision 14), else null.
 *
 * Table `sales_order_status_change`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'sales_order_status_change')]
class SalesOrderStatusChange
{
    public const TRIGGER_CREATE        = 'create';
    public const TRIGGER_STATUS        = 'status';
    public const TRIGGER_DELIVERY_NOTE = 'delivery-note';
    public const TRIGGER_BOOKING_PRINT = 'booking-print';

    public const TRIGGERS = [self::TRIGGER_CREATE, self::TRIGGER_STATUS, self::TRIGGER_DELIVERY_NOTE, self::TRIGGER_BOOKING_PRINT];

    public const REASON_LENGTH = 255;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private SalesOrder $order;

    #[ORM\Column(name: 'from_code', length: SalesOrder::STATUS_LENGTH, nullable: true)]
    private ?string $fromCode;

    #[ORM\Column(name: 'to_code', length: SalesOrder::STATUS_LENGTH)]
    private string $toCode;

    #[ORM\Column(name: 'change_trigger', length: 16)]
    private string $trigger;

    #[ORM\Column(length: self::REASON_LENGTH, nullable: true)]
    private ?string $reason;

    #[ORM\Column(name: 'changed_by', length: SalesOrder::ACTOR_LENGTH)]
    private string $changedBy;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    /** @throws \LogicException an unknown trigger, a missing target, or from = to */
    public function __construct(
        SalesOrder $order,
        ?string $fromCode,
        string $toCode,
        string $trigger,
        ?string $reason,
        string $changedBy,
        \DateTimeImmutable $changedAt,
    ) {
        if (!in_array($trigger, self::TRIGGERS, true)) {
            throw new \LogicException("Unknown status-change trigger '{$trigger}'");
        }
        if ($toCode === '' || $fromCode === $toCode) {
            throw new \LogicException('A status change names a target other than its origin');
        }
        $this->order     = $order;
        $this->fromCode  = $fromCode;
        $this->toCode    = $toCode;
        $this->trigger   = $trigger;
        $this->reason    = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, self::REASON_LENGTH);
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function getFromCode(): ?string { return $this->fromCode; }
    public function getToCode(): string { return $this->toCode; }
    public function getTrigger(): string { return $this->trigger; }
    public function getReason(): ?string { return $this->reason; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
