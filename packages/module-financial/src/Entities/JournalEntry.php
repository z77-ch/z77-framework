<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * A journal entry (ADR-042 decision 5): ONE header, stored once, with n ≥ 2
 * {@see JournalLine}s that balance — Σ debit = Σ credit is checked in the
 * domain (`PostingRequest`) before anything is persisted.
 *
 * The header:
 *
 *   - `fiscalYear` + `number`: the number is gapless per year, drawn from
 *     the range `journal-entry.{code}` by `LedgerService::post()` — the only
 *     way in (ADR-040, one write path). Unique per year.
 *   - `date`: the posting date; the period it falls into decides whether
 *     the entry may be posted, edited or deleted (`PeriodState`). The period
 *     is not stored: it is derived from the date, and period bounds never
 *     move.
 *   - `kind` ({@see EntryKind}): generated entries are immutable, manual
 *     entries are edited and deleted through `ManualEntryService` until the
 *     close, each change logged in an {@see EntryChange}.
 *   - `sourceType` + `sourceRef`: the OPAQUE origin (ADR-040 decision 2) —
 *     strings financial never interprets. Null on a manual entry.
 *   - `idempotencyKey`: unique; a repeated key returns this entry instead of
 *     posting twice. Generated entries only — null on a manual entry.
 *   - `reversalOf`: the entry this one reverses (plan §1, correction
 *     principle). Unique in the schema: an entry is reversed AT MOST ONCE,
 *     and the database says so even under a race.
 *   - created / changed by and at: the actor's name (`Actor`), never an id —
 *     a backend user can be deleted, the books cannot lose the name.
 *
 * No setters: an entry is built complete by `LedgerService`. The one
 * mutation is {@see amend()} — a manual edit — and it refuses a generated
 * entry itself, not only in the service.
 *
 * Table `journal_entry`. The date column is `entry_date`: `date` is a
 * MariaDB function name and a column should not lean on that being allowed.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'journal_entry')]
#[ORM\UniqueConstraint(name: JournalEntry::UNIQUE_NUMBER, columns: ['fiscal_year_id', 'number'])]
#[ORM\UniqueConstraint(name: JournalEntry::UNIQUE_IDEMPOTENCY, columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: JournalEntry::UNIQUE_REVERSAL_OF, columns: ['reversal_of_id'])]
#[ORM\Index(name: 'idx_journal_entry_date', columns: ['fiscal_year_id', 'entry_date'])]
class JournalEntry
{
    public const TEXT_LENGTH        = 255;
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;
    public const KEY_LENGTH         = 128;
    public const ACTOR_LENGTH       = 80;

    /** Unique indexes — the services recognise their violation by name. */
    public const UNIQUE_NUMBER      = 'uniq_journal_entry_number';
    public const UNIQUE_IDEMPOTENCY = 'uniq_journal_entry_idempotency';
    public const UNIQUE_REVERSAL_OF = 'uniq_journal_entry_reversal_of';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FiscalYear::class)]
    #[ORM\JoinColumn(name: 'fiscal_year_id', nullable: false)]
    private FiscalYear $fiscalYear;

    /** Gapless within the fiscal year, from 1 (`NumberRange`). */
    #[ORM\Column]
    private int $number;

    #[ORM\Column(name: 'entry_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: self::TEXT_LENGTH)]
    private string $text;

    /** An {@see EntryKind} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\Column(name: 'source_type', length: self::SOURCE_TYPE_LENGTH, nullable: true)]
    private ?string $sourceType;

    #[ORM\Column(name: 'source_ref', length: self::SOURCE_REF_LENGTH, nullable: true)]
    private ?string $sourceRef;

    #[ORM\Column(name: 'idempotency_key', length: self::KEY_LENGTH, nullable: true)]
    private ?string $idempotencyKey;

    /** ManyToOne plus the unique index above — not OneToOne, so the index carries OUR name and `diff` stays clean. */
    #[ORM\ManyToOne(targetEntity: JournalEntry::class)]
    #[ORM\JoinColumn(name: 'reversal_of_id', nullable: true)]
    private ?JournalEntry $reversalOf;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'changed_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $changedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    /**
     * Optimistic lock (review 2026-09-22, H1/M1–M3): a manual edit or delete
     * names the version it saw; a second writer in between bumps it and the
     * stale write is refused (`EntryConflictException`) instead of doubling
     * the lines or logging a delete twice. Doctrine increments it on every
     * UPDATE of the row — and a manual edit always updates the row, because
     * `amend()` stamps `changed_at`, so a lines-only change bumps it as well.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /**
     * The lines, in position order. `cascade: persist` so one `persist()` of
     * the entry writes them; `cascade: remove` + `orphanRemoval` so a deleted
     * manual entry takes its lines with it and an edit that replaces them
     * deletes the old ones.
     *
     * @var Collection<int, JournalLine>
     */
    #[ORM\OneToMany(targetEntity: JournalLine::class, mappedBy: 'entry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(
        FiscalYear $fiscalYear,
        int $number,
        \DateTimeImmutable $date,
        string $text,
        EntryKind $kind,
        ?string $sourceType,
        ?string $sourceRef,
        ?string $idempotencyKey,
        string $createdBy,
        \DateTimeImmutable $createdAt,
        ?JournalEntry $reversalOf = null,
    ) {
        $this->lines          = new ArrayCollection();
        $this->fiscalYear     = $fiscalYear;
        $this->number         = $number;
        $this->date           = $date;
        $this->text           = $text;
        $this->kind           = $kind->value;
        $this->sourceType     = $sourceType;
        $this->sourceRef      = $sourceRef;
        $this->idempotencyKey = $idempotencyKey;
        $this->reversalOf     = $reversalOf;
        $this->createdBy      = $createdBy;
        $this->createdAt      = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getFiscalYear(): FiscalYear { return $this->fiscalYear; }
    public function getNumber(): int { return $this->number; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getText(): string { return $this->text; }
    public function getKind(): string { return $this->kind; }
    public function getSourceType(): ?string { return $this->sourceType; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getReversalOf(): ?JournalEntry { return $this->reversalOf; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getVersion(): int { return $this->version; }

    public function isManual(): bool
    {
        return $this->kind === EntryKind::Manual->value;
    }

    /** True for a reversal entry — one that corrects another (never reversed itself). */
    public function isReversal(): bool
    {
        return $this->reversalOf !== null;
    }

    /** @return list<JournalLine> in position order */
    public function getLines(): array
    {
        return $this->lines->getValues();
    }

    /**
     * Attach a line built for THIS entry. Called by `LedgerService` while the
     * entry is being built, and by {@see amend()}.
     */
    public function addLine(JournalLine $line): void
    {
        if ($line->getEntry() !== $this) {
            throw new \LogicException('Journal line belongs to another entry');
        }
        $this->lines->add($line);
    }

    /** Σ debit (= Σ credit) — the entry's amount as every list shows it. */
    public function total(): Money
    {
        $lines = $this->getLines();
        if ($lines === []) {
            throw new \LogicException('A journal entry without lines has no total');
        }
        $sum = Money::zero($lines[0]->getDebit()->currency);
        foreach ($lines as $line) {
            $sum = $sum->add($line->getDebit());
        }

        return $sum;
    }

    /** Whether any line carries a tax code — what a `vat-settled` period freezes (ADR-042 decision 10). */
    public function hasTaxLine(): bool
    {
        foreach ($this->getLines() as $line) {
            if ($line->hasTax()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one mutation, for a MANUAL entry only: new date, text and lines,
     * stamped with who changed it and when. The old lines leave the
     * collection (orphan removal deletes them at flush), the new ones come
     * in. The number stays — it belongs to the fiscal year, which is why the
     * caller (`ManualEntryService`) refuses a date in another year before
     * calling this. A generated entry refuses here as well, so the rule does
     * not depend on the service alone (ADR-042 decision 7).
     *
     * @param list<JournalLine> $lines built for THIS entry, in position order
     */
    public function amend(\DateTimeImmutable $date, string $text, array $lines, string $changedBy, \DateTimeImmutable $changedAt): void
    {
        if (!$this->isManual()) {
            throw new \LogicException('A generated journal entry is never edited — correction is a reversal (ADR-042 decision 7)');
        }
        if (count($lines) < 2) {
            throw new \LogicException('A journal entry has at least two lines');
        }
        $this->date      = $date;
        $this->text      = $text;
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
        $this->lines->clear();
        foreach ($lines as $line) {
            $this->addLine($line);
        }
    }

    /**
     * What the entry looks like now — stored in an {@see EntryChange} before
     * and after a manual edit (and before a delete), and the shape the
     * idempotency comparison reads (`PostingRequest::fingerprintOf()` picks
     * its keys — the audit fields below are not part of the comparison).
     * snake_case keys (Rule 6), amounts as decimal strings, dates ISO.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'fiscal_year' => $this->fiscalYear->getCode(),
            'number'      => $this->number,
            'date'        => $this->date->format('Y-m-d'),
            'text'        => $this->text,
            'kind'        => $this->kind,
            'source_type' => $this->sourceType,
            'source_ref'  => $this->sourceRef,
            'created_by'  => $this->createdBy,
            'created_at'  => $this->createdAt->format(\DateTimeInterface::ATOM),
            'changed_by'  => $this->changedBy,
            'changed_at'  => $this->changedAt?->format(\DateTimeInterface::ATOM),
            'lines'       => array_map(static fn(JournalLine $line) => $line->snapshot(), $this->getLines()),
        ];
    }
}
