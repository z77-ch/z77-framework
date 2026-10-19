<?php

namespace Z77\Module\Article\Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * The VARIANT stage of an article (plan §4b A3) — the stock, price and invoicing unit: the
 * article NUMBER (A2 — free, typed, never derived from a placement; unique), the unit, active.
 * Every product has AT LEAST ONE variant: the DEFAULT variant (empty `label`) is created with
 * the product and stays hidden in the UI while it is the only one — «no second stage» is a UI
 * fact, never a schema fact. An order line references the VARIANT, never the product; the
 * stock service (P7b) keys its journal by variant id.
 *
 * Every standard field, its mapping and its logic live HERE; the entity {@see Variant} is EMPTY
 * (ADR-039 addendum 2026-10-08 (b)) — a project adds per-variant columns (EAN, offer price) by
 * overriding `Variant` alone.
 *
 * The variant OPTIONS (vintage, size, colour) are data, not code (plan §4b) — not built in P6
 * slim; `label` tells a second variant apart until they are.
 */
#[ORM\MappedSuperclass]
abstract class AbstractVariant
{
    public const NUMBER_LENGTH = 32;
    public const LABEL_LENGTH  = 80;
    public const UNIT_LENGTH   = 16;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    protected Product $product;

    /** The article number — unique over every variant (`uniq_article_variant_number`). */
    #[ORM\Column(length: self::NUMBER_LENGTH)]
    protected string $number = '';

    /** '' = the default variant of its product. */
    #[ORM\Column(length: self::LABEL_LENGTH)]
    protected string $label = '';

    /** «Stk», «h», «Fl.» — '' = no unit (a lump sum). The order line snapshots it. */
    #[ORM\Column(length: self::UNIT_LENGTH)]
    protected string $unit = '';

    #[ORM\Column]
    protected bool $active = true;

    public function __construct(Product $product, string $label = '')
    {
        $this->product = $product;
        $this->label   = $label;
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getNumber(): string { return $this->number; }
    public function getLabel(): string { return $this->label; }
    public function getUnit(): string { return $this->unit; }
    public function isActive(): bool { return $this->active; }
    public function isDefault(): bool { return $this->label === ''; }

    /** The name a line or a picker shows: the product's name, the label of a non-default variant behind it. */
    public function displayName(): string
    {
        return trim($this->product->getName() . ($this->label !== '' ? ' ' . $this->label : ''));
    }

    /**
     * THE text a document line of this article starts with (owner 2026-10-18) — the one place
     * every source builds it (module-order's fill and save): the {@see displayName()} on the
     * FIRST line, the product's description from the second line on (none = the name alone).
     * A copy — the line keeps it editable; a PDF prints the first line bold.
     */
    public function lineText(): string
    {
        $description = trim(str_replace(["\r\n", "\r"], "\n", $this->product->getDescription()));

        return $this->displayName() . ($description !== '' ? "\n" . $description : '');
    }

    /** Number and unit as the one save writes them — after the draft passed its validator. */
    public function identify(string $number, string $unit): void
    {
        $this->number = $number;
        $this->unit   = $unit;
    }

    public function setActive(bool $active): void { $this->active = $active; }
}
