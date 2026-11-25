<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Module\Financial\VatReturn\ExactAmount,
    Z77\Module\Financial\VatReturn\VatPeriod,
    Z77\Module\Vat\CountryPack\FieldKind,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One row of the block «Korrekturen früherer Perioden — separat im ePortal melden» of a posted
 * {@see VatReturn} (ADR-042 addendum 2 of 2026-11-25, points 1 and 2; addendum 3, points 5 and 6):
 * per CORRECTED period and form field, what the correction entries the return carries change —
 * stored with the return at «MWST buchen», so the posted return re-renders its block from these rows
 * only, nothing recomputed (owner decision 6 of 2026-11-25).
 *
 *   - `vat_return_id` — the CARRYING return: the ordinary return of the period the correction
 *     entries are dated in;
 *   - `corrected_return_id`, `corrected_from` / `corrected_to` — the corrected period and its
 *     return as live at posting (whose stored figures are «ursprünglich»);
 *   - `field` / `kind` — the form field and its {@see FieldKind};
 *   - `original_exact` — the field's figure as the corrected return DECLARED it
 *     (`vat_return_figure.value_exact`; for the payable field the exact tax total − input total);
 *   - `earlier_exact` — Σ of the correction rows of the same period and field carried by EARLIER
 *     live returns (the column «frühere Korrekturen», addendum 3 point 6);
 *   - `correction_exact` — this return's correction;
 *   - `original_base` / `earlier_base` / `correction_base` — the same for the base of a rate or
 *     acquisition field (the turnover beside the tax), NULL for every other kind;
 *   - `entries` — JSON list of the correction entries behind the period (`{ref: «2027/214», date}`), the same on
 *     every row of one corrected period;
 *   - `position` — the order of the block: corrected period, then form order.
 *
 * «neu» is not stored: original + earlier + correction, exact, rounded ONCE ({@see newValue()}).
 * Every exact value is `DECIMAL(21,6)` like `vat_return_figure.value_exact`, never float. Written
 * once with its return, never changed; a lifted return keeps its rows. Table
 * `vat_return_correction` (`Version20261127100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'vat_return_correction')]
#[ORM\UniqueConstraint(name: VatReturnCorrection::UNIQUE_FIELD, columns: ['vat_return_id', 'corrected_from', 'field'])]
#[ORM\Index(name: VatReturnCorrection::INDEX_CORRECTED, columns: ['corrected_from', 'corrected_to'])]
class VatReturnCorrection
{
    public const UNIQUE_FIELD    = 'uniq_vat_return_correction_field';
    public const INDEX_CORRECTED = 'idx_vat_return_correction_period';

    public const ENTRIES_MAX = 2000;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The carrying return. */
    #[ORM\ManyToOne(targetEntity: VatReturn::class)]
    #[ORM\JoinColumn(name: 'vat_return_id', nullable: false)]
    private VatReturn $vatReturn;

    /** The corrected period's return as live when the carrying return was posted. */
    #[ORM\ManyToOne(targetEntity: VatReturn::class)]
    #[ORM\JoinColumn(name: 'corrected_return_id', nullable: false)]
    private VatReturn $correctedReturn;

    #[ORM\Column(name: 'corrected_from', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $correctedFrom;

    #[ORM\Column(name: 'corrected_to', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $correctedTo;

    #[ORM\Column]
    private int $position;

    /** The form field number — «200», «303», … */
    #[ORM\Column(length: VatReturnFigure::FIELD_LENGTH)]
    private string $field;

    /** A {@see FieldKind} value. */
    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(name: 'original_exact', type: Types::DECIMAL, precision: 21, scale: 6)]
    private string $originalExact;

    #[ORM\Column(name: 'earlier_exact', type: Types::DECIMAL, precision: 21, scale: 6)]
    private string $earlierExact;

    #[ORM\Column(name: 'correction_exact', type: Types::DECIMAL, precision: 21, scale: 6)]
    private string $correctionExact;

    #[ORM\Column(name: 'original_base', type: MoneyType::NAME, nullable: true)]
    private ?Money $originalBase;

    #[ORM\Column(name: 'earlier_base', type: MoneyType::NAME, nullable: true)]
    private ?Money $earlierBase;

    #[ORM\Column(name: 'correction_base', type: MoneyType::NAME, nullable: true)]
    private ?Money $correctionBase;

    /** JSON list of the correction entries of the corrected period — `[{"ref": "2027/214", "date": "2027-02-22"}]`. */
    #[ORM\Column(type: Types::TEXT)]
    private string $entries;

    /**
     * @param array{original: ?Money, earlier: ?Money, correction: ?Money} $bases a rate or acquisition field's bases — all three set, else all three null
     * @param list<array{ref: string, date: \DateTimeImmutable}> $entries the correction entries behind the corrected period
     */
    public function __construct(
        VatReturn $vatReturn,
        VatReturn $correctedReturn,
        int $position,
        string $field,
        FieldKind $kind,
        ExactAmount $original,
        ExactAmount $earlier,
        ExactAmount $correction,
        array $bases,
        array $entries,
    ) {
        if ($field === '' || strlen($field) > VatReturnFigure::FIELD_LENGTH) {
            throw new \LogicException('A correction row names its form field (at most ' . VatReturnFigure::FIELD_LENGTH . ' characters)');
        }
        $currency = $vatReturn->getCurrency();
        foreach ([$original, $earlier, $correction, $bases['original'] ?? null, $bases['earlier'] ?? null, $bases['correction'] ?? null] as $amount) {
            if ($amount !== null && $amount->currency !== $currency) {
                throw new \LogicException("A correction row is in its return's currency ({$currency})");
            }
        }
        $withBase = in_array($kind, [FieldKind::Rate, FieldKind::Acquisition], true);
        $given    = array_filter([$bases['original'] ?? null, $bases['earlier'] ?? null, $bases['correction'] ?? null], static fn(?Money $m): bool => $m !== null);
        if ($withBase ? count($given) !== 3 : $given !== []) {
            throw new \LogicException("Field {$field}: the three bases are stored for a rate or acquisition field, and only there");
        }
        if ($correctedReturn->getPeriodTo() >= $vatReturn->getPeriodFrom()) {
            throw new \LogicException('A correction row corrects a period that ends before the carrying return\'s period begins');
        }
        $json = json_encode(array_values(array_map(static fn(array $e): array => ['ref' => (string) $e['ref'], 'date' => $e['date']->format('Y-m-d')], $entries)), JSON_THROW_ON_ERROR);
        if ($entries === [] || strlen($json) > self::ENTRIES_MAX) {
            throw new \LogicException('A correction row names the correction entries behind it (1 or more)');
        }
        $this->vatReturn       = $vatReturn;
        $this->correctedReturn = $correctedReturn;
        $this->correctedFrom   = $correctedReturn->getPeriodFrom();
        $this->correctedTo     = $correctedReturn->getPeriodTo();
        $this->position        = $position;
        $this->field           = $field;
        $this->kind            = $kind->value;
        $this->originalExact   = $original->toDecimal();
        $this->earlierExact    = $earlier->toDecimal();
        $this->correctionExact = $correction->toDecimal();
        $this->originalBase    = $bases['original'] ?? null;
        $this->earlierBase     = $bases['earlier'] ?? null;
        $this->correctionBase  = $bases['correction'] ?? null;
        $this->entries         = $json;
    }

    public function getId(): ?int { return $this->id; }
    public function getVatReturn(): VatReturn { return $this->vatReturn; }
    public function getCorrectedReturn(): VatReturn { return $this->correctedReturn; }
    public function getCorrectedFrom(): \DateTimeImmutable { return $this->correctedFrom; }
    public function getCorrectedTo(): \DateTimeImmutable { return $this->correctedTo; }
    public function getPosition(): int { return $this->position; }
    public function getField(): string { return $this->field; }
    public function getKind(): FieldKind { return FieldKind::from($this->kind); }
    public function getOriginalBase(): ?Money { return $this->originalBase; }
    public function getEarlierBase(): ?Money { return $this->earlierBase; }
    public function getCorrectionBase(): ?Money { return $this->correctionBase; }

    /** The corrected period, named as its return stored it. */
    public function correctedPeriod(): VatPeriod
    {
        return $this->correctedReturn->period();
    }

    public function getOriginal(): ExactAmount
    {
        return ExactAmount::fromDecimal($this->originalExact, $this->vatReturn->getCurrency());
    }

    public function getEarlier(): ExactAmount
    {
        return ExactAmount::fromDecimal($this->earlierExact, $this->vatReturn->getCurrency());
    }

    public function getCorrection(): ExactAmount
    {
        return ExactAmount::fromDecimal($this->correctionExact, $this->vatReturn->getCurrency());
    }

    /** «neu» — original + earlier + correction, exact (rounded once by the reader: `->rounded()`). */
    public function newValue(): ExactAmount
    {
        return $this->getOriginal()->add($this->getEarlier())->add($this->getCorrection());
    }

    /** The new base of a rate or acquisition field — null for every other kind. */
    public function newBase(): ?Money
    {
        if ($this->originalBase === null || $this->earlierBase === null || $this->correctionBase === null) {
            return null;
        }

        return $this->originalBase->add($this->earlierBase)->add($this->correctionBase);
    }

    /** @return list<array{ref: string, date: \DateTimeImmutable}> the correction entries behind the corrected period */
    public function getEntries(): array
    {
        $decoded = json_decode($this->entries, true);
        $entries = [];
        foreach (is_array($decoded) ? $decoded : [] as $e) {
            if (is_array($e) && isset($e['ref'], $e['date'])) {
                $entries[] = ['ref' => (string) $e['ref'], 'date' => new \DateTimeImmutable((string) $e['date'])];
            }
        }

        return $entries;
    }
}
