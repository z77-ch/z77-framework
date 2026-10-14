<?php

namespace Z77\Module\Article\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Tree\TreeNode;

/**
 * One node of the PRODUCT-GROUP TREE (plan §4b A1, owner 2026-10-14 «der Baum reicht vorerst, ich
 * muss ja irgendwie Waren gruppieren können») — ONE tree of free depth, the accounting
 * classification of the article master («Warengruppe»), not the customer navigation of a shop.
 * Every product hangs on exactly ONE node ({@see AbstractProduct::getProductGroup()}), on any
 * node, not only a leaf.
 *
 * What a group carries besides its place: two optional DEFAULTS for NEW articles — the tax code
 * (by code into module-vat) and the revenue account (by number into the chart), validated like
 * the article's own fields. A new article inherits each default from the nearest ancestor that
 * has one ({@see \Z77\Module\Article\Services\ProductGroupTree::defaultsFor()}); an existing
 * article never changes when a default changes (ADR-043 addendum 2026-10-14: tax code and
 * revenue account sit on the ARTICLE).
 *
 * Placement is BY ID (`article_product.product_group_id`), so moving a group moves its articles
 * with it — nothing is rewritten. The order among siblings is `sortKey` ({@see TreeNode}, the
 * shared `TreeService` sorts, appends and renumbers); the parent is a self-referencing foreign
 * key, so the database refuses to delete a group that still has children or articles.
 *
 * NOT extendable (no abstract stage, backend-screen.md §7: «built when a module needs it, never
 * in advance») — a shop's own group fields (slug, text, image) are a later decision, and the
 * shop's MULTI-category navigation is a separate structure anyway (article.md, pending).
 * Written only by {@see \Z77\Module\Article\Services\ProductGroupService}.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'article_product_group')]
class ProductGroup implements TreeNode
{
    public const NAME_LENGTH = 80;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** null = a root of the tree. */
    #[ORM\ManyToOne(targetEntity: ProductGroup::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    private ?ProductGroup $parent = null;

    #[ORM\Column(length: self::NAME_LENGTH)]
    private string $name = '';

    /** Order among the siblings (same parent); lower first — set by the service, never from a form. */
    #[ORM\Column(name: 'sort_key')]
    private int $sortKey = 0;

    /** False = not offered when an article is placed; articles that hang here keep their place. */
    #[ORM\Column]
    private bool $active = true;

    /** Default tax code for NEW articles — module-vat's code, '' = none (inherited from above). */
    #[ORM\Column(name: 'default_tax_code', length: AbstractProduct::TAX_CODE_LENGTH)]
    private string $defaultTaxCode = '';

    /** Default revenue account for NEW articles — the chart's number, '' = none (inherited from above). */
    #[ORM\Column(name: 'default_revenue_account', length: AbstractProduct::ACCOUNT_LENGTH)]
    private string $defaultRevenueAccount = '';

    /** Optimistic lock: a save names the version the form was rendered from. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    public function getId(): ?int { return $this->id; }
    public function getParent(): ?ProductGroup { return $this->parent; }
    public function getParentId(): ?int { return $this->parent?->getId(); }
    public function getName(): string { return $this->name; }
    public function getSortKey(): int { return $this->sortKey; }
    public function isActive(): bool { return $this->active; }
    public function getDefaultTaxCode(): string { return $this->defaultTaxCode; }
    public function getDefaultRevenueAccount(): string { return $this->defaultRevenueAccount; }
    public function getVersion(): int { return $this->version; }

    /** Name and defaults as the one save writes them — after the draft passed its validator. */
    public function describe(string $name, string $defaultTaxCode, string $defaultRevenueAccount): void
    {
        $this->name                  = $name;
        $this->defaultTaxCode        = $defaultTaxCode;
        $this->defaultRevenueAccount = $defaultRevenueAccount;
    }

    /**
     * Hangs the group under $parent (null = root). The service checked the cycle and sets the
     * sort key at the end of the new siblings ({@see \Z77\Shared\Tree\TreeService::nextSortKey()}).
     */
    public function moveUnder(?ProductGroup $parent): void
    {
        $this->parent = $parent;
    }

    public function setActive(bool $active): void { $this->active = $active; }

    public function setSortKey(int $sortKey): void { $this->sortKey = $sortKey; }

    /**
     * Part of {@see TreeNode}, never used: the parent is a RELATION (the foreign key keeps the
     * tree whole), and an id alone cannot set it — a move goes through {@see moveUnder()}. The
     * shared `TreeService` only reads the parent id and writes sort keys.
     */
    public function setParentId(?int $parentId): void
    {
        if ($parentId !== $this->getParentId()) {
            throw new \LogicException('A product group moves through moveUnder() — the parent is a relation, not an id');
        }
    }
}
