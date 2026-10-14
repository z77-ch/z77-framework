<?php

namespace Z77\Module\Article\Services;

use Z77\Module\Article\Entities\ProductGroup;
use Z77\Shared\Tree\TreeService;

/**
 * The product-group tree as one read model over ALL groups (a small set — one query): the rows
 * in tree order with their depth, a group's path, its subtree, the defaults a NEW article
 * inherits, the cycle question of a move. Pure — no database, no request — so the harness checks
 * it offline (`tests/module-article.php` G) and the screens, the article list and the service ask
 * the same thing. The mechanics (children sorted by `sortKey`, the descendant walk) are the shared
 * {@see TreeService} (Rule 9); the policy (inheritance, what a move may do) is here.
 */
final class ProductGroupTree
{
    public const PATH_SEPARATOR = ' › ';

    /** @var array<int, ProductGroup> id → group */
    private array $byId = [];

    /** @var list<array{group: ProductGroup, depth: int}>|null */
    private ?array $rows = null;

    private readonly TreeService $tree;

    /** @param list<ProductGroup> $groups every group (saved — with an id) */
    public function __construct(array $groups)
    {
        $this->tree = new TreeService();
        foreach ($groups as $group) {
            if ($group->getId() !== null) {
                $this->byId[$group->getId()] = $group;
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->byId === [];
    }

    public function find(int $id): ?ProductGroup
    {
        return $this->byId[$id] ?? null;
    }

    /** @return list<int> every id — the article list's allowed filter values */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    /**
     * Every group in tree order — roots by `sortKey`, each followed by its subtree — with its
     * depth (root = 0). A group whose parent is missing (cannot happen with the foreign key)
     * would be shown as a root rather than lost.
     *
     * @return list<array{group: ProductGroup, depth: int}>
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $nodes = array_values($this->byId);
        $rows  = [];
        $roots = array_filter($nodes, fn(ProductGroup $g) => $g->getParentId() === null || !isset($this->byId[$g->getParentId()]));
        foreach ($this->tree->sort($roots) as $root) {
            $rows[] = ['group' => $root, 'depth' => 0];
            foreach ($this->tree->descendants($nodes, $root) as $entry) {
                $rows[] = ['group' => $entry['node'], 'depth' => $entry['depth'] + 1];
            }
        }

        return $this->rows = $rows;
    }

    /** How deep $id sits (root = 0); 0 for an unknown id. */
    public function depth(int $id): int
    {
        foreach ($this->rows() as $row) {
            if ($row['group']->getId() === $id) {
                return $row['depth'];
            }
        }

        return 0;
    }

    /** @return list<ProductGroup> $id and its ancestors, the root first ([] for an unknown id) */
    public function chain(int $id): array
    {
        $chain = [];
        for ($group = $this->find($id), $guard = 64; $group !== null && $guard-- > 0; $group = $this->find((int) $group->getParentId())) {
            array_unshift($chain, $group);
            if ($group->getParentId() === null) {
                break;
            }
        }

        return $chain;
    }

    /** «Dienstleistungen › Beratung» — '' for an unknown id. */
    public function path(int $id): string
    {
        return implode(self::PATH_SEPARATOR, array_map(static fn(ProductGroup $g) => $g->getName(), $this->chain($id)));
    }

    /** @return list<int> $id and every group below it — what «show this group's articles» filters by */
    public function subtreeIds(int $id): array
    {
        $root = $this->find($id);
        if ($root === null) {
            return [];
        }
        $ids = [$id];
        foreach ($this->tree->descendants(array_values($this->byId), $root) as $entry) {
            $ids[] = (int) $entry['node']->getId();
        }

        return $ids;
    }

    /** How many groups hang DIRECTLY under $id. */
    public function childCount(int $id): int
    {
        return count($this->tree->children(array_values($this->byId), $id));
    }

    /** Whether $candidate is $root itself or below it — hanging $root under it would make a cycle. */
    public function isWithin(int $candidate, int $root): bool
    {
        $node  = $this->find($candidate);
        $top   = $this->find($root);

        return $node !== null && $top !== null && $this->tree->isDescendantOf($this->byId, $node, $top);
    }

    /**
     * The defaults a NEW article in $id takes: each field from the NEAREST group on the way up
     * that has one (the group itself first) — independently, so a sub-group may set only its own
     * account and inherit the tax code. '' = no group on the way sets it.
     *
     * @return array{taxCode: string, revenueAccount: string}
     */
    public function defaultsFor(int $id): array
    {
        $taxCode = $account = '';
        foreach (array_reverse($this->chain($id)) as $group) {
            $taxCode = $taxCode !== '' ? $taxCode : $group->getDefaultTaxCode();
            $account = $account !== '' ? $account : $group->getDefaultRevenueAccount();
        }

        return ['taxCode' => $taxCode, 'revenueAccount' => $account];
    }

    /**
     * The groups a picker offers, in tree order with their depth: the ACTIVE ones, plus $keep (the
     * group a record already names — kept although it was deactivated since, the reference rule
     * of ADR-043 decision 19), minus the subtree of $without (a group's own parent picker cannot
     * offer the group itself or anything below it).
     *
     * @return list<array{group: ProductGroup, depth: int}>
     */
    public function selectable(?int $keep = null, ?int $without = null): array
    {
        $excluded = $without !== null ? array_flip($this->subtreeIds($without)) : [];

        return array_values(array_filter($this->rows(), static fn(array $row) =>
            !isset($excluded[$row['group']->getId()]) && ($row['group']->isActive() || $row['group']->getId() === $keep)));
    }
}
