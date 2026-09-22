<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Entity
;

/**
 * A fiscal year (plan §5.1): a `code`, a start and an end date, and its
 * {@see Period}s — one per calendar month, derived when the year is opened
 * (`FiscalYearService::open()`).
 *
 * Owner decisions 2026-09-22:
 *
 *   - FREE start and end dates: a deviating fiscal year (1.7.–30.6.), an
 *     extended or shortened one (a company founded on 15.3.). At most 24
 *     months; years are contiguous — a new year starts the day after the
 *     latest one ends (`FiscalYearValidator`).
 *   - Periods are CALENDAR MONTHS, clipped to the year's bounds: a year from
 *     15.3. has a first period 15.3.–31.3.
 *   - The `code` names the year where a number is not enough — two years
 *     can start in the same calendar year (a short year 1.1.–30.6.2026, then
 *     1.7.2026–30.6.2027) — and it names the year's journal-entry number
 *     range: `journal-entry.{code}` ({@see journalEntryRange()}). Lower-case
 *     kebab (`2026`, `2026-27`), proposed from the dates, and IMMUTABLE: the
 *     range carries it. No edit of a year; a delete only of the latest,
 *     still empty year (`FiscalYearService::delete()`, FIN-FY-002).
 *
 * Table `fiscal_year`; `code` and `start_date` are unique — the second is
 * the guard against two openings of the same next year racing past the
 * contiguity check.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'fiscal_year')]
#[ORM\UniqueConstraint(name: FiscalYear::UNIQUE_CODE, columns: ['code'])]
#[ORM\UniqueConstraint(name: FiscalYear::UNIQUE_START, columns: ['start_date'])]
class FiscalYear
{
    /** Longest code the column holds; `journal-entry.` + code stays far below the 64 bytes of a range name. */
    public const CODE_LENGTH = 16;

    /** Prefix of the year's journal-entry number range (`persistence-doctrine.md` → range names). */
    public const JOURNAL_ENTRY_RANGE_PREFIX = 'journal-entry.';

    /** Unique indexes — `FiscalYearService` recognises their violation by name. */
    public const UNIQUE_CODE  = 'uniq_fiscal_year_code';
    public const UNIQUE_START = 'uniq_fiscal_year_start';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(length: self::CODE_LENGTH)]
    private string $code = '';

    /** Null only on an unfinished form (the validator refuses it); the column is NOT NULL. */
    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $endDate = null;

    /**
     * The monthly periods, in date order. `cascade: persist` so the opening
     * writes the year and its periods with one `persist()`.
     *
     * @var Collection<int, Period>
     */
    #[ORM\OneToMany(targetEntity: Period::class, mappedBy: 'fiscalYear', cascade: ['persist'])]
    #[ORM\OrderBy(['startDate' => 'ASC'])]
    private Collection $periods;

    public function __construct(string $code = '', ?\DateTimeImmutable $startDate = null, ?\DateTimeImmutable $endDate = null)
    {
        $this->periods = new ArrayCollection();
        $this->setCode($code);
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }

    /** @return list<Period> in date order */
    public function getPeriods(): array
    {
        return $this->periods->getValues();
    }

    /** The name of this year's journal-entry number range — the one place it is composed. */
    public function journalEntryRange(): string
    {
        return self::JOURNAL_ENTRY_RANGE_PREFIX . $this->code;
    }

    /** True when $date lies inside this year (both bounds inclusive). */
    public function covers(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->startDate !== null && $this->endDate !== null
            && $day >= $this->startDate->format('Y-m-d') && $day <= $this->endDate->format('Y-m-d');
    }

    /**
     * The period a date falls into — what the ledger's period rules are
     * checked against (`PostingRules`). The periods cover the year without a
     * gap, so null means the date is outside the year (or the year is not
     * opened yet). A dozen rows: walked in PHP, no query.
     */
    public function periodOn(\DateTimeImmutable $date): ?Period
    {
        $day = $date->format('Y-m-d');
        foreach ($this->periods as $period) {
            if ($day >= $period->getStartDate()->format('Y-m-d') && $day <= $period->getEndDate()->format('Y-m-d')) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Only for a year not yet opened — `FiscalYearService::open()` adds the
     * derived periods after validation; nothing adds a period later.
     */
    public function addPeriod(Period $period): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Periods are derived when a fiscal year is opened — an open year gets no new period');
        }
        if ($period->getFiscalYear() !== $this) {
            throw new \LogicException('Period belongs to another fiscal year');
        }
        $this->periods->add($period);
    }

    /**
     * Lower-cased and trimmed; called by the constructor only. The dates have
     * no setter at all: a year gets them when it is built and keeps them
     * (no edit in part 1).
     */
    private function setCode(string $code): void { $this->code = mb_strtolower(trim($code)); }

    /**
     * The validator walks `mapToArray()`; without `ArrayMappable` (no form
     * mapping on this entity — dates are parsed by the controller) it is
     * spelled out here.
     *
     * @return array{code: string, start_date: ?\DateTimeImmutable, end_date: ?\DateTimeImmutable}
     */
    public function mapToArray(): array
    {
        return ['code' => $this->code, 'start_date' => $this->startDate, 'end_date' => $this->endDate];
    }
}
