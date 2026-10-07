<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * One dunning run (plan §6.5, P4 part 3): the day the office walked the
 * due list and sent notices — who, when, dated which day (the notice
 * date printed on every notice of the run, the value the due days were
 * judged against). Its {@see DunningNotice}s are the history per invoice.
 *
 * Immutable: a run is what happened; a notice sent in error is answered
 * by the next level or by hand, never by deleting the run. Table
 * `dunning_run` (`Version20261007150000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'dunning_run')]
#[ORM\Index(name: 'idx_dunning_run_date', columns: ['run_date'])]
class DunningRun
{
    public const ACTOR_LENGTH = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The notice date — printed, and the day the due days were judged against. */
    #[ORM\Column(name: 'run_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $runDate;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, DunningNotice> in position order */
    #[ORM\OneToMany(targetEntity: DunningNotice::class, mappedBy: 'run', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $notices;

    public function __construct(\DateTimeImmutable $runDate, string $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->runDate   = $runDate;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->notices   = new ArrayCollection();
    }

    /** Adds a notice — by the dunning service, before the run is persisted. */
    public function add(DunningNotice $notice): void
    {
        $this->notices->add($notice);
    }

    public function getId(): ?int { return $this->id; }
    public function getRunDate(): \DateTimeImmutable { return $this->runDate; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return list<DunningNotice> */
    public function getNotices(): array
    {
        return array_values($this->notices->toArray());
    }
}
