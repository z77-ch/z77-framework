<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Module\Financial\VatReturn\ExactAmount,
    Z77\Module\Vat\CountryPack\FieldKind,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One DECLARED figure of a posted {@see VatReturn} (ADR-042 addendum 2026-11-24, point 4: «the
 * declared figures per field are stored on every posted return»): one row per form field of the
 * edition — every field, zero included, so a correction diffs field by field («recomputed − last
 * declared», stage S3) and the yearly reconciliation sums the declared rate fields in plain SQL.
 *
 *   - `value_exact` — the field's {@see ExactAmount} as a decimal string (ten-thousandths of a
 *     Rappen, `DECIMAL(21,6)`), never through float;
 *   - `value_rounded` — to the Rappen, as shown and typed into the portal;
 *   - `base` — the base of a rate or acquisition field, NULL for every other kind;
 *   - `line_count` — the coded journal lines behind the field.
 *
 * A table, not JSON on the return: ADR-042 decision 3 wants amounts readable with a query tool.
 * Written once with its return, never changed. Table `vat_return_figure` (`Version20261124182954`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'vat_return_figure')]
#[ORM\UniqueConstraint(name: VatReturnFigure::UNIQUE_FIELD, columns: ['vat_return_id', 'field'])]
class VatReturnFigure
{
    public const UNIQUE_FIELD = 'uniq_vat_return_figure_field';

    public const FIELD_LENGTH = 8;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VatReturn::class)]
    #[ORM\JoinColumn(name: 'vat_return_id', nullable: false)]
    private VatReturn $vatReturn;

    /** The form field number — «200», «302», … */
    #[ORM\Column(length: self::FIELD_LENGTH)]
    private string $field;

    /** A {@see FieldKind} value. */
    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(name: 'value_exact', type: Types::DECIMAL, precision: 21, scale: 6)]
    private string $valueExact;

    #[ORM\Column(name: 'value_rounded', type: MoneyType::NAME)]
    private Money $valueRounded;

    #[ORM\Column(type: MoneyType::NAME, nullable: true)]
    private ?Money $base;

    #[ORM\Column(name: 'line_count')]
    private int $lineCount;

    public function __construct(VatReturn $vatReturn, string $field, FieldKind $kind, ExactAmount $value, ?Money $base, int $lineCount)
    {
        if ($field === '' || strlen($field) > self::FIELD_LENGTH) {
            throw new \LogicException('A declared figure names its form field (at most ' . self::FIELD_LENGTH . ' characters)');
        }
        if ($value->currency !== $vatReturn->getCurrency() || ($base !== null && $base->currency !== $vatReturn->getCurrency())) {
            throw new \LogicException("A declared figure is in its return's currency ({$vatReturn->getCurrency()})");
        }
        if (($base !== null) !== in_array($kind, [FieldKind::Rate, FieldKind::Acquisition], true)) {
            throw new \LogicException("Field {$field}: a base is declared for a rate or acquisition field, and only there");
        }
        if ($lineCount < 0) {
            throw new \LogicException('A line count is not negative');
        }
        $this->vatReturn    = $vatReturn;
        $this->field        = $field;
        $this->kind         = $kind->value;
        $this->valueExact   = $value->toDecimal();
        $this->valueRounded = $value->rounded();
        $this->base         = $base;
        $this->lineCount    = $lineCount;
    }

    public function getId(): ?int { return $this->id; }
    public function getVatReturn(): VatReturn { return $this->vatReturn; }
    public function getField(): string { return $this->field; }
    public function getKind(): FieldKind { return FieldKind::from($this->kind); }
    public function getValueRounded(): Money { return $this->valueRounded; }
    public function getBase(): ?Money { return $this->base; }
    public function getLineCount(): int { return $this->lineCount; }

    /** The exact value as declared — read back through integers, never float. */
    public function getValueExact(): ExactAmount
    {
        return ExactAmount::fromDecimal($this->valueExact, $this->vatReturn->getCurrency());
    }
}
