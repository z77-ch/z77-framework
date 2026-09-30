<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * The protocol of closing and reopening fiscal years (owner decisions
 * 2026-09-30, P5 part 1): every close and every reopen writes one row —
 * which year, what, who, when, and why. A reopen carries a MANDATORY
 * reason; a close carries the warnings the closer confirmed (null when the
 * close check found none). This is what keeps «an admin can reopen a
 * closed year» traceable in the sense of the GeBüV.
 *
 * The year is referenced by id AND code as PLAIN COLUMNS — deliberately no
 * foreign key, the {@see EntryChange} model: a reopened, still empty year
 * can be deleted (`FiscalYearService::delete()`), and its protocol must
 * survive that; the code keeps the row readable without the year.
 *
 * Immutable: constructor and getters only. Written only by
 * `FiscalYearCloseService`.
 *
 * Table `fiscal_year_close_log`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'fiscal_year_close_log')]
#[ORM\Index(name: 'idx_fiscal_year_close_log_year', columns: ['fiscal_year_id'])]
class FiscalYearCloseLog
{
    /** Longest reason the column holds — a sentence, not a report. */
    public const REASON_LENGTH = 500;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The year's id — no foreign key, the row outlives a deleted year. */
    #[ORM\Column(name: 'fiscal_year_id')]
    private int $fiscalYearId;

    #[ORM\Column(name: 'fiscal_year_code', length: FiscalYear::CODE_LENGTH)]
    private string $fiscalYearCode;

    /** A {@see CloseAction} value, stored as its string. */
    #[ORM\Column(length: 8)]
    private string $action;

    #[ORM\Column(length: JournalEntry::ACTOR_LENGTH)]
    private string $actor;

    #[ORM\Column(name: 'acted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $actedAt;

    /** Why a year was reopened (mandatory there); null on a close. */
    #[ORM\Column(length: self::REASON_LENGTH, nullable: true)]
    private ?string $reason;

    /** The close-check warnings the closer confirmed, one per line; null when there were none (or on a reopen). */
    #[ORM\Column(name: 'confirmed_warnings', type: Types::TEXT, nullable: true)]
    private ?string $confirmedWarnings;

    /** @param list<string> $confirmedWarnings the messages of the warnings the closer confirmed */
    public function __construct(
        FiscalYear $year,
        CloseAction $action,
        string $actor,
        \DateTimeImmutable $actedAt,
        ?string $reason = null,
        array $confirmedWarnings = [],
    ) {
        if ($year->getId() === null) {
            throw new \LogicException('A close or reopen is logged for a persisted fiscal year only');
        }
        $reason = $reason === null ? null : trim($reason);
        if ($action === CloseAction::Reopen && ($reason === null || $reason === '')) {
            throw new \LogicException('A reopen carries a reason');
        }
        if ($reason !== null && mb_strlen($reason) > self::REASON_LENGTH) {
            throw new \LogicException('A reason is kept whole — at most ' . self::REASON_LENGTH . ' characters (the service refuses a longer one)');
        }
        if ($action === CloseAction::Close && $reason !== null) {
            throw new \LogicException('A close carries no reason — its confirmed warnings are the record');
        }
        if ($action === CloseAction::Reopen && $confirmedWarnings !== []) {
            throw new \LogicException('A reopen confirms no warnings');
        }
        // One warning per line: a line break inside a message would split it on the way back.
        $confirmedWarnings = array_map(static fn(string $w) => trim(preg_replace('/\s+/u', ' ', $w) ?? $w), $confirmedWarnings);
        $this->fiscalYearId      = (int) $year->getId();
        $this->fiscalYearCode    = $year->getCode();
        $this->action            = $action->value;
        $this->actor             = $actor;
        $this->actedAt           = $actedAt;
        $this->reason            = $reason;
        $this->confirmedWarnings = $confirmedWarnings === [] ? null : implode("\n", $confirmedWarnings);
    }

    public function getId(): ?int { return $this->id; }
    public function getFiscalYearId(): int { return $this->fiscalYearId; }
    public function getFiscalYearCode(): string { return $this->fiscalYearCode; }
    public function getAction(): string { return $this->action; }
    public function getActor(): string { return $this->actor; }
    public function getActedAt(): \DateTimeImmutable { return $this->actedAt; }
    public function getReason(): ?string { return $this->reason; }

    /** @return list<string> */
    public function getConfirmedWarnings(): array
    {
        return $this->confirmedWarnings === null ? [] : explode("\n", $this->confirmedWarnings);
    }
}
