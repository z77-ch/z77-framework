<?php

namespace Z77\Module\Article\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The PRODUCT stage of an article (plan §4b A3) — the marketing and accounting unit: its name,
 * the tax code and the revenue account (owner 2026-10-14: on the article; a product-group tree
 * later only supplies DEFAULTS), whether stock is kept («führt Bestand», A7), active.
 *
 * Every standard field, its mapping and its logic live HERE, in a `#[ORM\MappedSuperclass]`;
 * the entity {@see Product} is EMPTY (ADR-039 addendum 2026-10-08 (b)). A project that needs a
 * column of its own (a grape variety, a producer text) overrides only `Product` under
 * `override/z77/module/article/src/Entities/Product.php` — same FQCN, extends this class, repeats
 * the class-level mapping of `Product` — and migrates the column in
 * `override/z77/project/res/migrations/`. A framework update reaches the project through this
 * class with no hand merge.
 *
 * References into file-based master data are BY CODE / NUMBER, never a foreign key (ADR-043
 * decision 19): `taxCode` → module-vat's `TaxCode::$code`, `revenueAccount` → the chart's account
 * number (financial is only suggested — the check is soft, `LedgerAccountCheck`).
 *
 * No variant collection here: a mapped superclass may not own a one-to-many, and nothing needs
 * one — the variants are read through `VariantRepository` (the default variant is the one with
 * the empty label). Written only by {@see \Z77\Module\Article\Services\ArticleService}.
 */
#[ORM\MappedSuperclass]
abstract class AbstractProduct
{
    public const NAME_LENGTH     = 120;
    public const TAX_CODE_LENGTH = 8;
    public const ACCOUNT_LENGTH  = 10;
    public const ACTOR_LENGTH    = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    protected ?int $id = null;

    #[ORM\Column(length: self::NAME_LENGTH)]
    protected string $name = '';

    /** module-vat's `TaxCode::$code` — by code, no foreign key (ADR-043 decision 19). */
    #[ORM\Column(name: 'tax_code', length: self::TAX_CODE_LENGTH)]
    protected string $taxCode = '';

    /** The chart's account number a line of this article earns on — by number, no foreign key. */
    #[ORM\Column(name: 'revenue_account', length: self::ACCOUNT_LENGTH)]
    protected string $revenueAccount = '';

    /** «führt Bestand» — the stock service (P7b) keeps stock only for such articles (plan §8.1). */
    #[ORM\Column(name: 'stock_relevant')]
    protected bool $stockRelevant = false;

    /** False = no longer offered for new lines; lines that reference it keep resolving. */
    #[ORM\Column]
    protected bool $active = true;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    protected string $createdBy = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'changed_by', length: self::ACTOR_LENGTH, nullable: true)]
    protected ?string $changedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?\DateTimeImmutable $changedAt = null;

    /** Optimistic lock: a save names the version the form was rendered from (`ArticleService::update()`). */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    protected int $version = 1;

    public function __construct(string $createdBy, \DateTimeImmutable $createdAt)
    {
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getTaxCode(): string { return $this->taxCode; }
    public function getRevenueAccount(): string { return $this->revenueAccount; }
    public function isStockRelevant(): bool { return $this->stockRelevant; }
    public function isActive(): bool { return $this->active; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getVersion(): int { return $this->version; }

    /**
     * The master fields as the one save writes them — called by `ArticleService` AFTER the
     * draft passed its validator (ADR-039 decision 9: a managed entity is never mutated before
     * validation).
     */
    public function describe(string $name, string $taxCode, string $revenueAccount, bool $stockRelevant): void
    {
        $this->name           = $name;
        $this->taxCode        = $taxCode;
        $this->revenueAccount = $revenueAccount;
        $this->stockRelevant  = $stockRelevant;
    }

    public function setActive(bool $active): void { $this->active = $active; }

    /** Stamps who changed the article last — the service, on every write of an existing row. */
    public function touch(string $changedBy, \DateTimeImmutable $changedAt): void
    {
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }
}
