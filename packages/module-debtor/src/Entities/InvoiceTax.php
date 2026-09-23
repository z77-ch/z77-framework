<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * One row of an {@see Invoice}'s tax summary — per tax code: the rate that
 * applied, the base (Σ of its lines, net) and the tax on that base, rounded
 * ONCE (ADR-041 decision 5). This is `TaxSummaryEntry` of module-vat
 * STORED (the «snapshot serialisation» `vat.md` deferred to P3): the
 * document prints it, the posting posts VAT per code from it, a discount
 * (P4) corrects proportionally over it — nothing recomputes it from the tax
 * tables (decision 6).
 *
 * The code is referenced BY CODE (ADR-043 decision 19); `category` and
 * `label` are snapshotted with it — the category is what the accounting
 * adapter resolves the VAT account from (financial's `vatAccounts` maps
 * category → account), the label is what the document prints.
 *
 * A table rather than a JSON column so that base and tax are `DECIMAL`
 * columns a person can sum with a query tool (ADR-042 decision 3). One row
 * per code per document; not unique in the schema (a re-issue inserts before
 * it deletes — the `journal_line.position` argument).
 *
 * Table `invoice_tax`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'invoice_tax')]
#[ORM\Index(name: 'idx_invoice_tax_code', columns: ['tax_code'])]
class InvoiceTax
{
    public const CATEGORY_LENGTH = 16;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'taxes')]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false)]
    private Invoice $invoice;

    /** Order of the summary as printed (by code), from 1. */
    #[ORM\Column]
    private int $position;

    #[ORM\Column(name: 'tax_code', length: InvoiceLine::TAX_CODE_LENGTH)]
    private string $taxCode;

    /** The code's `TaxCategory` value at issue (standard, reduced, zero, exempt, …). */
    #[ORM\Column(name: 'tax_category', length: self::CATEGORY_LENGTH)]
    private string $taxCategory;

    #[ORM\Column(name: 'tax_label', length: InvoiceLine::TAX_LABEL_LENGTH)]
    private string $taxLabel;

    /** Hundredths of a percent. */
    #[ORM\Column(name: 'tax_rate', type: Types::INTEGER)]
    private int $taxRate;

    /** Σ of the code's line amounts, NET (in gross mode: gross − tax). Signed as the lines are. */
    #[ORM\Column(name: 'tax_base', type: MoneyType::NAME)]
    private Money $base;

    /** The tax on the base, rounded once, half away from zero. */
    #[ORM\Column(name: 'tax_amount', type: MoneyType::NAME)]
    private Money $tax;

    public function __construct(Invoice $invoice, int $position, string $taxCode, string $taxCategory, string $taxLabel, int $taxRate, Money $base, Money $tax)
    {
        if ($position < 1 || $taxCode === '' || $taxRate < 0) {
            throw new \LogicException('A tax row has a position from 1, a code and a rate >= 0');
        }
        if ($base->currency !== $tax->currency) {
            throw new \LogicException('Tax base and tax in different currencies');
        }
        $this->invoice     = $invoice;
        $this->position    = $position;
        $this->taxCode     = $taxCode;
        $this->taxCategory = $taxCategory;
        $this->taxLabel    = mb_substr($taxLabel, 0, InvoiceLine::TAX_LABEL_LENGTH);
        $this->taxRate     = $taxRate;
        $this->base        = $base;
        $this->tax         = $tax;
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function getPosition(): int { return $this->position; }
    public function getTaxCode(): string { return $this->taxCode; }
    public function getTaxCategory(): string { return $this->taxCategory; }
    public function getTaxLabel(): string { return $this->taxLabel; }
    public function getTaxRate(): int { return $this->taxRate; }
    public function getBase(): Money { return $this->base; }
    public function getTax(): Money { return $this->tax; }
}
