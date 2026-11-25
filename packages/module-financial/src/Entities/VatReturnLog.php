<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * The protocol of the VAT returns (ADR-042 addendum 2026-11-24, point 5; owner 2026-11-24): every
 * post and every lift writes one row — which return, what, who, when, why. The change log of the
 * journal cannot hold these events: `EntryChange` is per journal entry and written by edit /
 * amend / retract only, a post or a lift writes a NEW entry, and a nil return has no entry at all.
 *
 * The return is referenced by id as a PLAIN COLUMN, and its period and revision are copied — the
 * {@see FiscalYearCloseLog} model: a row reads without a join.
 *
 *   - `reason` — mandatory on a lift (at most {@see REASON_LENGTH}), none otherwise;
 *   - `detail` — JSON, what else the event names: the confirmed warnings and the voucher (post),
 *     entry references.
 *
 * Immutable: constructor and getters only. Table `vat_return_log` (`Version20261124182954`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'vat_return_log')]
#[ORM\Index(name: 'idx_vat_return_log_return', columns: ['vat_return_id'])]
class VatReturnLog
{
    public const REASON_LENGTH = VatReturn::REASON_LENGTH;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The return's id — no foreign key, the close log's model. */
    #[ORM\Column(name: 'vat_return_id')]
    private int $vatReturnId;

    #[ORM\Column(name: 'period_from', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(name: 'period_to', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column]
    private int $revision;

    /** A {@see VatReturnLogAction} value. */
    #[ORM\Column(length: 8)]
    private string $action;

    #[ORM\Column(length: JournalEntry::ACTOR_LENGTH)]
    private string $actor;

    #[ORM\Column(name: 'acted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $actedAt;

    #[ORM\Column(length: self::REASON_LENGTH, nullable: true)]
    private ?string $reason;

    /** JSON object; NULL = nothing further. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detail;

    /** @param array<string, mixed> $detail what else the event names (see the class docblock) */
    public function __construct(
        VatReturn $return,
        VatReturnLogAction $action,
        string $actor,
        \DateTimeImmutable $actedAt,
        ?string $reason = null,
        array $detail = [],
    ) {
        if ($return->getId() === null) {
            throw new \LogicException('A VAT return event is logged for a persisted return only');
        }
        $reason = $reason === null ? null : trim($reason);
        if ($action === VatReturnLogAction::Lift && ($reason === null || $reason === '')) {
            throw new \LogicException('A lift carries a reason');
        }
        if ($action !== VatReturnLogAction::Lift && $reason !== null) {
            throw new \LogicException('Only a lift carries a reason');
        }
        if ($reason !== null && mb_strlen($reason) > self::REASON_LENGTH) {
            throw new \LogicException('A reason is kept whole — at most ' . self::REASON_LENGTH . ' characters (the service refuses a longer one)');
        }
        $this->vatReturnId = (int) $return->getId();
        $this->periodFrom  = $return->getPeriodFrom();
        $this->periodTo    = $return->getPeriodTo();
        $this->revision    = $return->getRevision();
        $this->action      = $action->value;
        $this->actor       = mb_substr($actor, 0, JournalEntry::ACTOR_LENGTH);
        $this->actedAt     = $actedAt;
        $this->reason      = $reason;
        $this->detail      = $detail === [] ? null : json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getId(): ?int { return $this->id; }
    public function getVatReturnId(): int { return $this->vatReturnId; }
    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function getRevision(): int { return $this->revision; }
    public function getAction(): VatReturnLogAction { return VatReturnLogAction::from($this->action); }
    public function getActor(): string { return $this->actor; }
    public function getActedAt(): \DateTimeImmutable { return $this->actedAt; }
    public function getReason(): ?string { return $this->reason; }

    /** @return array<string, mixed> */
    public function getDetail(): array
    {
        return $this->detail === null ? [] : json_decode($this->detail, true, 16, JSON_THROW_ON_ERROR);
    }
}
