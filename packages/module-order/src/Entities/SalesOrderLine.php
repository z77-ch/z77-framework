<?php

namespace Z77\Module\Order\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Article\Entities\Variant;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * One position of a {@see SalesOrder} (plan §4.3, owner O3, D2 / D3): typed like the invoice line
 * (debtor's {@see LineType}: `service` / `lump-sum` / `text` — `rounding` is never a position),
 * optionally BENEATH a priced top-level line (`parentLine`, one level — a package with its
 * contents), with SNAPSHOTS of what it prints and posts.
 *
 *   - `variant` — the ARTICLE (an order line references the variant, A3); NULL = a free line (D2).
 *     A text line never references an article. On an article line tax code and revenue account
 *     come FROM THE ARTICLE (D3) — a different account is a free line;
 *   - `articleNumber` — the number as it read at save (printed on quote and delivery note);
 *   - `quantity` — `DECIMAL(12,3)` as a string, NEGATIVE allowed (a return, ADR-043 decision 7);
 *     null on a lump sum and a text line;
 *   - `unitPrice` — money, null on a text line; `discountPercent` in hundredths (2 % = 200).
 *
 * The line's identity is STABLE across saves: `OrderService::update()` rewrites a line in place
 * ({@see write()}) when the draft carries its id, so the stock seam's line reference (the line id —
 * the journal keys by line AND variant) survives an edit; a line left out of the draft is removed
 * (orphan removal). No amount is stored (D11 — the preview computes it).
 *
 * `position` is NOT unique in the schema on purpose — Doctrine inserts before it deletes (the
 * `invoice_line` precedent). Table `sales_order_line`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'sales_order_line')]
class SalesOrderLine
{
    public const ARTICLE_NUMBER_LENGTH = 32;
    public const UNIT_LENGTH           = 16;
    public const TAX_CODE_LENGTH       = 8;
    public const ACCOUNT_LENGTH        = 10;
    public const TEXT_LENGTH           = 2000;

    /** `DECIMAL(12,3)`: up to nine digits before the point, three after, optional sign. */
    public const QUANTITY_PATTERN = '/^-?\d{1,9}(?:\.\d{1,3})?$/';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false)]
    private SalesOrder $order;

    /** Order within the document, from 1 — children directly after their parent. */
    #[ORM\Column]
    private int $position = 1;

    /** The priced top-level line this one prints beneath; null on a top-level line. */
    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)]
    #[ORM\JoinColumn(name: 'parent_line_id', nullable: true)]
    private ?SalesOrderLine $parentLine = null;

    /** A {@see LineType} value (never `rounding`). */
    #[ORM\Column(length: 12)]
    private string $type = 'service';

    /** The article variant (A3); null = a free or a text line (D2). */
    #[ORM\ManyToOne(targetEntity: Variant::class)]
    #[ORM\JoinColumn(name: 'variant_id', nullable: true)]
    private ?Variant $variant = null;

    /** The article number at save — a snapshot; null on a free line. */
    #[ORM\Column(name: 'article_number', length: self::ARTICLE_NUMBER_LENGTH, nullable: true)]
    private ?string $articleNumber = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $text = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantity = null;

    #[ORM\Column(length: self::UNIT_LENGTH, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(name: 'unit_price', type: MoneyType::NAME, nullable: true)]
    private ?Money $unitPrice = null;

    /** Hundredths of a percent (2 % = 200), 0 = none. */
    #[ORM\Column(name: 'discount_percent', type: Types::INTEGER)]
    private int $discountPercent = 0;

    /** module-vat's code, by code (ADR-043 decision 19); null on a text line. */
    #[ORM\Column(name: 'tax_code', length: self::TAX_CODE_LENGTH, nullable: true)]
    private ?string $taxCode = null;

    /** The chart's revenue account, by number; null on a text line. */
    #[ORM\Column(name: 'revenue_account', length: self::ACCOUNT_LENGTH, nullable: true)]
    private ?string $revenueAccount = null;

    public function __construct(SalesOrder $order)
    {
        $this->order = $order;
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function getPosition(): int { return $this->position; }
    public function getParentLine(): ?SalesOrderLine { return $this->parentLine; }
    public function getVariant(): ?Variant { return $this->variant; }
    public function getArticleNumber(): ?string { return $this->articleNumber; }
    public function getText(): string { return $this->text; }
    public function getQuantity(): ?string { return $this->quantity; }
    public function getUnit(): ?string { return $this->unit; }
    public function getUnitPrice(): ?Money { return $this->unitPrice; }
    public function getDiscountPercent(): int { return $this->discountPercent; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function getRevenueAccount(): ?string { return $this->revenueAccount; }

    public function type(): LineType
    {
        return LineType::from($this->type);
    }

    /** An article line — the only kind the stock seam is told about. */
    public function isArticleLine(): bool
    {
        return $this->variant !== null;
    }

    /**
     * The whole content of the line as the one write path sets it — after the draft passed its
     * rules. The invariants per type are guarded here once more (the `InvoiceLine` model).
     *
     * @throws \LogicException a rounding line; a nested child or a child under an unpriced / foreign line;
     *                         a text line with an article, amount, tax or account; a priced line without
     *                         price, tax code or account; a quantity on a lump sum or none on a service
     */
    public function write(
        int $position,
        ?SalesOrderLine $parentLine,
        LineType $type,
        ?Variant $variant,
        ?string $articleNumber,
        string $text,
        ?string $quantity,
        ?string $unit,
        ?Money $unitPrice,
        int $discountPercent,
        ?string $taxCode,
        ?string $revenueAccount,
    ): void {
        if ($position < 1) {
            throw new \LogicException('A line position starts at 1');
        }
        if ($type === LineType::Rounding) {
            throw new \LogicException('The rounding line is debtor\'s — never an order position');
        }
        if ($parentLine !== null && ($parentLine === $this || $parentLine->getOrder() !== $this->order || $parentLine->getParentLine() !== null || !$parentLine->type()->isPriced())) {
            throw new \LogicException('A child line sits beneath a PRICED top-level line of the same order — one level only');
        }
        if ($quantity !== null && !preg_match(self::QUANTITY_PATTERN, $quantity)) {
            throw new \LogicException("Quantity must be a decimal string with at most three decimals, got '{$quantity}'");
        }
        if ($type->isPriced()) {
            if ($unitPrice === null || $taxCode === null || $revenueAccount === null) {
                throw new \LogicException("A {$type->value} line carries a unit price, a tax code and a revenue account");
            }
            if (($type === LineType::Service) !== ($quantity !== null)) {
                throw new \LogicException('A service line carries a quantity, a lump sum does not');
            }
        } elseif ($variant !== null || $quantity !== null || $unitPrice !== null || $discountPercent !== 0 || $taxCode !== null || $revenueAccount !== null) {
            throw new \LogicException('A text line carries no article, quantity, price, discount, tax code or account');
        }
        if ($variant === null && $articleNumber !== null) {
            throw new \LogicException('An article number belongs to an article line');
        }
        $this->position        = $position;
        $this->parentLine      = $parentLine;
        $this->type            = $type->value;
        $this->variant         = $variant;
        $this->articleNumber   = $articleNumber;
        $this->text            = $text;
        $this->quantity        = $quantity;
        $this->unit            = $unit;
        $this->unitPrice       = $unitPrice;
        $this->discountPercent = $discountPercent;
        $this->taxCode         = $taxCode;
        $this->revenueAccount  = $revenueAccount;
    }
}
