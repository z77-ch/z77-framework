<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * What the office decided for ONE bank movement BY HAND (ADR-061 decisions 4, 8, 19; stage C2) —
 * the part of a movement's state that cannot be derived from its link and its pattern. At most one
 * per movement:
 *
 *   - {@see PRIVATE} — «privat» by hand: this movement only, whatever a pattern says;
 *   - {@see REVIEW} — held on «zu prüfen» although a private pattern matches: after «Buchung
 *     zurücknehmen …» (decision 19) or when the office set this one movement back;
 *   - {@see EXCEPTION} — the movement is business although its pattern is private, and the office
 *     answered «Nein» to «Die restlichen … auf ‹zu prüfen› setzen?» (decision 8): the question is
 *     not asked again for it. It says nothing without a link.
 *
 * A LINK always wins: a linked movement is «geschäftlich» whatever stands here (decision 7).
 * Table `bank_movement_mark` (`Version20261117170000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'bank_movement_mark')]
#[ORM\UniqueConstraint(name: BankMovementMark::UNIQUE_MOVEMENT, columns: ['movement_id'])]
class BankMovementMark
{
    public const UNIQUE_MOVEMENT = 'uniq_bank_movement_mark_movement';

    public const PRIVATE   = 'private';
    public const REVIEW    = 'review';
    public const EXCEPTION = 'exception';
    public const STATES = [self::PRIVATE, self::REVIEW, self::EXCEPTION];

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BankMovement::class)]
    #[ORM\JoinColumn(name: 'movement_id', nullable: false)]
    private BankMovement $movement;

    /** {@see self::STATES}. */
    #[ORM\Column(length: 10)]
    private string $state;

    #[ORM\Column(name: 'marked_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $markedBy;

    #[ORM\Column(name: 'marked_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $markedAt;

    public function __construct(BankMovement $movement, string $state, string $markedBy, \DateTimeImmutable $markedAt)
    {
        $this->movement = $movement;
        $this->set($state, $markedBy, $markedAt);
    }

    public function set(string $state, string $by, \DateTimeImmutable $at): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new \LogicException("Unknown movement mark '{$state}'");
        }
        $this->state    = $state;
        $this->markedBy = mb_substr($by, 0, JournalEntry::ACTOR_LENGTH);
        $this->markedAt = $at;
    }

    public function getId(): ?int { return $this->id; }
    public function getMovement(): BankMovement { return $this->movement; }
    public function getState(): string { return $this->state; }
    public function getMarkedBy(): string { return $this->markedBy; }
    public function getMarkedAt(): \DateTimeImmutable { return $this->markedAt; }
}
