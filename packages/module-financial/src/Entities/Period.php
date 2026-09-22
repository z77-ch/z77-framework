<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * One period of a {@see FiscalYear} — a calendar month, clipped to the
 * year's bounds (owner, 2026-09-22) — with its close state
 * ({@see PeriodState}, ADR-042 decision 10).
 *
 * Created only by `FiscalYearService::open()`, always `open`. P2 part 1 has
 * no transition: the VAT return and the close (P5) move the state, and the
 * posting service (part 2) refuses by it. There is therefore no setter for
 * the state yet (CLAUDE.md «no just-in-case»).
 *
 * Table `fiscal_period`, unique on (fiscal year, start date) — not
 * `period`, which MariaDB knows as a keyword (`PERIOD FOR` of its
 * system-versioned tables); non-reserved today, but a table name should not
 * depend on that.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'fiscal_period')]
#[ORM\UniqueConstraint(name: 'uniq_fiscal_period_start', columns: ['fiscal_year_id', 'start_date'])]
class Period
{
    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FiscalYear::class, inversedBy: 'periods')]
    #[ORM\JoinColumn(name: 'fiscal_year_id', nullable: false)]
    private FiscalYear $fiscalYear;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    /** A {@see PeriodState} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $state = PeriodState::Open->value;

    public function __construct(FiscalYear $fiscalYear, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate)
    {
        if ($endDate < $startDate) {
            throw new \LogicException('A period ends on or after its start');
        }
        $this->fiscalYear = $fiscalYear;
        $this->startDate  = $startDate;
        $this->endDate    = $endDate;
    }

    public function getId(): ?int { return $this->id; }
    public function getFiscalYear(): FiscalYear { return $this->fiscalYear; }
    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): \DateTimeImmutable { return $this->endDate; }
    public function getState(): string { return $this->state; }
}
