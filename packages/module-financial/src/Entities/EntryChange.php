<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * The change log of MANUAL journal entries (ADR-042 decision 7, plan §5.3,
 * Q4): every edit and every delete of a manual entry writes one row — who,
 * when, what it looked like before, and (for an edit) after. This is what
 * keeps «editable until the close» traceable in the sense of the GeBüV.
 *
 * The entry is referenced by its ID, its fiscal year and its number as
 * PLAIN COLUMNS — deliberately no foreign key: the row of a deleted entry
 * must survive the deletion (the gap in the numbering is documented by
 * exactly this row, decision 9), and a cascading key would take it along.
 *
 * Immutable: constructor and getters only, no setter. The snapshots are
 * `JournalEntry::snapshot()` as JSON text — written once, read for display;
 * the columns are TEXT rather than the platform's JSON type so the schema
 * diff does not depend on how a MariaDB version reports a JSON check.
 *
 * Table `journal_entry_change`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'journal_entry_change')]
#[ORM\Index(name: 'idx_journal_entry_change_entry', columns: ['entry_id'])]
#[ORM\Index(name: 'idx_journal_entry_change_year', columns: ['fiscal_year_id', 'entry_number'])]
class EntryChange
{
    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The entry's id — no foreign key, the row outlives the entry. */
    #[ORM\Column(name: 'entry_id')]
    private int $entryId;

    #[ORM\Column(name: 'fiscal_year_id')]
    private int $fiscalYearId;

    #[ORM\Column(name: 'entry_number')]
    private int $entryNumber;

    /** A {@see ChangeAction} value, stored as its string. */
    #[ORM\Column(length: 8)]
    private string $action;

    #[ORM\Column(name: 'changed_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $changedBy;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    /** `JournalEntry::snapshot()` as JSON, before the change. */
    #[ORM\Column(name: 'before_snapshot', type: Types::TEXT)]
    private string $before;

    /** After the change; null for a delete. */
    #[ORM\Column(name: 'after_snapshot', type: Types::TEXT, nullable: true)]
    private ?string $after;

    /**
     * @param array<string, mixed>      $before `JournalEntry::snapshot()` before the change
     * @param array<string, mixed>|null $after  after it — null for a delete
     */
    public function __construct(
        JournalEntry $entry,
        ChangeAction $action,
        string $changedBy,
        \DateTimeImmutable $changedAt,
        array $before,
        ?array $after,
    ) {
        if ($entry->getId() === null) {
            throw new \LogicException('A change is logged for a persisted entry only');
        }
        if (($action === ChangeAction::Delete) !== ($after === null)) {
            throw new \LogicException('A delete has no after-snapshot; an update has one');
        }
        $this->entryId      = $entry->getId();
        $this->fiscalYearId = (int) $entry->getFiscalYear()->getId();
        $this->entryNumber  = $entry->getNumber();
        $this->action       = $action->value;
        $this->changedBy    = $changedBy;
        $this->changedAt    = $changedAt;
        $this->before       = self::encode($before);
        $this->after        = $after === null ? null : self::encode($after);
    }

    public function getId(): ?int { return $this->id; }
    public function getEntryNumber(): int { return $this->entryNumber; }
    public function getAction(): string { return $this->action; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }

    /** @return array<string, mixed> */
    public function before(): array
    {
        return self::decode($this->before);
    }

    /** @return array<string, mixed>|null null for a delete */
    public function after(): ?array
    {
        return $this->after === null ? null : self::decode($this->after);
    }

    /** @param array<string, mixed> $snapshot */
    private static function encode(array $snapshot): string
    {
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }
}
