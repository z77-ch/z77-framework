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
 * Created only by `FiscalYearService::open()`, always `open`. The state
 * moves through {@see transitionTo()}, called by the year close
 * (`FiscalYearCloseService`, P5 part 1 — owner decisions 2026-09-30: the
 * whole YEAR is closed, an admin may reopen it) and nothing else; the
 * posting service refuses by it. The VAT return (P5 part 2) will add
 * `vat-settled`.
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

    /**
     * The one state change. Only `FiscalYearCloseService` calls it — on rows
     * it re-read under `SELECT … FOR UPDATE` —, which owns the rules (order
     * of the years, the close check, the protocol). What this method guards
     * is the transition itself: close = any state → `closed`, reopen =
     * `closed` → `open` (owner 2026-09-30; `vat-settled` does not exist in
     * the running system before P5 part 2, which decides what a reopen
     * restores then).
     *
     * @internal financial's own — call FiscalYearCloseService::close() / reopen()
     */
    public function transitionTo(PeriodState $state): void
    {
        $allowed = match ($state) {
            PeriodState::Closed     => true,
            PeriodState::Open       => $this->state === PeriodState::Closed->value,
            PeriodState::VatSettled => false,   // P5 part 2 (the VAT return) adds this transition
        };
        if (!$allowed) {
            throw new \LogicException("A period does not move from {$this->state} to {$state->value}");
        }
        $this->state = $state->value;
    }
}
