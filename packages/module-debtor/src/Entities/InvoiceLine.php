<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One line of an {@see Invoice} (plan §6.2, §13 requirement 2 — A-Pos):
 * typed ({@see LineType}), optionally BENEATH another line (`parentLine`,
 * one level — how a package prints with its contents at 0.00), with an
 * editable text snapshot, a fractional quantity (negative allowed: a
 * deduction), unit, unit price, a discount in hundredths of a percent, the
 * computed line amount, the tax code with the rate AND label that applied
 * (the `vat.md` rule for a document line — it is printed and must read the
 * same for ever) and the revenue account BY NUMBER.
 *
 * The amount is STORED (quantity × unit price − discount, rounded once per
 * line to 0.01), not recomputed: it is what the document printed. The tax
 * per line is deliberately NOT stored — tax is computed on the sum per code
 * and rounded once ({@see InvoiceTax}, ADR-041 decision 5); the posting
 * allocates each code's tax over its lines with `Money::allocate()` when it
 * needs a per-line share.
 *
 * `quantity` is a `DECIMAL(12,3)` STRING (three decimals — hours, kilos,
 * metres), never a float: `Money::multiply()` takes it as it is.
 *
 * Immutable: a re-issue replaces the lines of its document. `position` is
 * NOT unique in the schema on purpose — Doctrine inserts before it deletes,
 * and a re-issue would collide with itself (the `journal_line` precedent).
 *
 * Table `invoice_line`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'invoice_line')]
#[ORM\Index(name: 'idx_invoice_line_account', columns: ['revenue_account'])]
class InvoiceLine
{
    public const TAX_CODE_LENGTH    = 8;
    public const TAX_LABEL_LENGTH   = 80;
    public const UNIT_LENGTH        = 16;
    public const ACCOUNT_LENGTH     = 10;
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;

    /** `DECIMAL(12,3)`: up to nine digits before the point, three after, optional sign. */
    public const QUANTITY_PATTERN = '/^-?\d{1,9}(?:\.\d{1,3})?$/';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Invoice $invoice;

    /** Order within the document, from 1 — children directly after their parent. */
    #[ORM\Column]
    private int $position;

    /** The priced line this one prints beneath; null on a top-level line. One level only. */
    #[ORM\ManyToOne(targetEntity: InvoiceLine::class)]
    #[ORM\JoinColumn(name: 'parent_line_id', nullable: true)]
    private ?InvoiceLine $parentLine;

    /** A {@see LineType} value, stored as its string. */
    #[ORM\Column(length: 12)]
    private string $type;

    /** What the line prints — the editable snapshot; may span lines. */
    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    /** Three decimals, as a string; null where the type has no quantity (lump sum, text, rounding). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantity;

    #[ORM\Column(length: self::UNIT_LENGTH, nullable: true)]
    private ?string $unit;

    /** Null where the type has no price (text, rounding). */
    #[ORM\Column(name: 'unit_price', type: MoneyType::NAME, nullable: true)]
    private ?Money $unitPrice;

    /** Hundredths of a percent (2 % = 200), 0 = none. */
    #[ORM\Column(name: 'discount_percent', type: Types::INTEGER)]
    private int $discountPercent;

    /** The line amount as printed — net or gross per the document's price mode; 0.00 on a text line. */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $amount;

    #[ORM\Column(name: 'tax_code', length: self::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode;

    /** The rate that applied on the service date, hundredths of a percent — snapshotted with the code. */
    #[ORM\Column(name: 'tax_rate', type: Types::INTEGER, nullable: true)]
    private ?int $taxRate;

    /** The code's label as it read at issue — a document prints it (`vat.md`). */
    #[ORM\Column(name: 'tax_label', length: self::TAX_LABEL_LENGTH, nullable: true)]
    private ?string $taxLabel;

    /** The ledger account this line's revenue is posted to, BY NUMBER (plan §6.2); the rounding account on the rounding line. */
    #[ORM\Column(name: 'revenue_account', length: self::ACCOUNT_LENGTH, nullable: true)]
    private ?string $revenueAccount;

    #[ORM\Column(name: 'source_type', length: self::SOURCE_TYPE_LENGTH, nullable: true)]
    private ?string $sourceType;

    #[ORM\Column(name: 'source_ref', length: self::SOURCE_REF_LENGTH, nullable: true)]
    private ?string $sourceRef;

    /**
     * Built complete by `InvoicingService` for THIS document. The invariants
     * per type are guarded here once more; the service refuses them with a
     * message for the caller first.
     *
     * @throws \LogicException a priced line without tax code or account, a text line with an amount, a nested child
     */
    public function __construct(
        Invoice $invoice,
        int $position,
        LineType $type,
        ?InvoiceLine $parentLine,
        string $text,
        ?string $quantity,
        ?string $unit,
        ?Money $unitPrice,
        int $discountPercent,
        Money $amount,
        ?string $taxCode,
        ?int $taxRate,
        ?string $taxLabel,
        ?string $revenueAccount,
        ?string $sourceType,
        ?string $sourceRef,
    ) {
        if ($position < 1) {
            throw new \LogicException('A line position starts at 1');
        }
        if ($parentLine !== null && ($parentLine->getInvoice() !== $invoice || $parentLine->getParentLine() !== null || !$parentLine->type()->isPriced())) {
            throw new \LogicException('A child line sits beneath a PRICED top-level line of the same document — one level only');
        }
        if ($quantity !== null && !preg_match(self::QUANTITY_PATTERN, $quantity)) {
            throw new \LogicException("Quantity must be a decimal string with at most three decimals, got '{$quantity}'");
        }
        $withTax = $taxCode !== null;
        if ($withTax !== ($taxRate !== null) || $withTax !== ($taxLabel !== null)) {
            throw new \LogicException('Tax data on an invoice line is all-or-none: code, rate and label');
        }
        if ($type->isPriced()) {
            if (!$withTax || $revenueAccount === null || $unitPrice === null) {
                throw new \LogicException("A {$type->value} line carries a unit price, a tax code and a revenue account");
            }
            if (($type === LineType::Service) !== ($quantity !== null)) {
                throw new \LogicException('A service line carries a quantity, a lump sum does not');
            }
        } else {
            if ($withTax || $quantity !== null || $unitPrice !== null || $discountPercent !== 0) {
                throw new \LogicException("A {$type->value} line carries no quantity, price, discount or tax code");
            }
            if ($type === LineType::Text && (!$amount->isZero() || $revenueAccount !== null)) {
                throw new \LogicException('A text line has no amount and no account');
            }
            if ($type === LineType::Rounding && ($revenueAccount === null || $parentLine !== null)) {
                throw new \LogicException('The rounding line names the rounding account and sits at the top level');
            }
        }
        if ($amount->currency !== ($unitPrice?->currency ?? $amount->currency)) {
            throw new \LogicException('Line amount and unit price in different currencies');
        }
        $this->invoice         = $invoice;
        $this->position        = $position;
        $this->type            = $type->value;
        $this->parentLine      = $parentLine;
        $this->text            = $text;
        $this->quantity        = $quantity;
        $this->unit            = $unit;
        $this->unitPrice       = $unitPrice;
        $this->discountPercent = $discountPercent;
        $this->amount          = $amount;
        $this->taxCode         = $taxCode;
        $this->taxRate         = $taxRate;
        $this->taxLabel        = $taxLabel;
        $this->revenueAccount  = $revenueAccount;
        $this->sourceType      = $sourceType;
        $this->sourceRef       = $sourceRef;
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getPosition(): int { return $this->position; }
    public function getParentLine(): ?InvoiceLine { return $this->parentLine; }
    public function getText(): string { return $this->text; }
    public function getQuantity(): ?string { return $this->quantity; }
    public function getUnit(): ?string { return $this->unit; }
    public function getUnitPrice(): ?Money { return $this->unitPrice; }
    public function getDiscountPercent(): int { return $this->discountPercent; }
    public function getAmount(): Money { return $this->amount; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function getTaxRate(): ?int { return $this->taxRate; }
    public function getTaxLabel(): ?string { return $this->taxLabel; }
    public function getRevenueAccount(): ?string { return $this->revenueAccount; }
    public function getSourceType(): ?string { return $this->sourceType; }
    public function getSourceRef(): ?string { return $this->sourceRef; }

    public function type(): LineType
    {
        return LineType::from($this->type);
    }

    /** Whether this line reaches the ledger: a priced or rounding line with an amount other than 0.00. */
    public function posts(): bool
    {
        return $this->type !== LineType::Text->value && !$this->amount->isZero();
    }
}
