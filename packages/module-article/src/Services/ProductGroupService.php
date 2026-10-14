<?php

namespace Z77\Module\Article\Services;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Z77\Module\Article\Entities\ProductGroup;
use Z77\Module\Article\Repositories\ProductGroupRepository;
use Z77\Module\Article\Validators\ProductGroupValidator;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Tree\TreeService;

/**
 * The ONE write path of the product-group tree (plan §4b A1, owner 2026-10-14):
 * {@see create()}, {@see update()} (rename, defaults, MOVE to another parent), {@see setActive()},
 * {@see delete()}. A {@see ProductGroupDraft} in — no request.
 *
 *   - every save is validated FIRST ({@see ProductGroupValidator}: name unique among siblings, no
 *     cycle, defaults like the article's fields), then written in one unit of work;
 *   - a NEW group, and a group that MOVES, goes to the end of its new siblings (the shared
 *     `TreeService`: `nextSortKey()`; the siblings it left are renumbered densely). Its articles
 *     and sub-groups move with it — placement is by id, nothing is rewritten;
 *   - a changed DEFAULT reaches only articles created afterwards — no article is touched here
 *     (ADR-043 addendum 2026-10-14: tax code and revenue account sit on the article);
 *   - deactivate: the group is no longer offered when an article is placed; what hangs there stays;
 *   - delete: only an EMPTY group — no sub-group, no article ({@see ProductGroupInUseException});
 *     the self and product foreign keys decide under a race.
 *
 * Writes nothing of an article: the placement of a product is `ArticleService`'s field.
 */
final class ProductGroupService
{
    public function __construct(private readonly UnifiedEntityManager $em)
    {
    }

    // ── writes ───────────────────────────────────────────────────────────

    /** @throws InvalidProductGroupException */
    public function create(ProductGroupDraft $draft): ProductGroup
    {
        $tree = $this->groups()->tree();
        $this->assertValid($draft, $tree, null);

        $group = new ProductGroup();
        $this->em->getTransaction(ProductGroup::class)->run(function () use ($group, $draft, $tree): void {
            $group->describe($draft->name, $draft->defaultTaxCode, $draft->defaultRevenueAccount);
            $group->moveUnder($draft->parentId > 0 ? $tree->find($draft->parentId) : null);
            $group->setSortKey($this->treeService()->nextSortKey(array_map(static fn($r) => $r['group'], $tree->rows()), $group));
            $this->em->persist($group);
        });

        return $group;
    }

    /**
     * @throws ProductGroupConflictException the group is gone or its version moved on
     * @throws InvalidProductGroupException
     */
    public function update(int $groupId, int $version, ProductGroupDraft $draft): ProductGroup
    {
        $tree  = $this->groups()->tree();
        $group = $tree->find($groupId);
        if ($group === null || $group->getVersion() !== $version) {
            throw new ProductGroupConflictException($groupId, $version);
        }
        $this->assertValid($draft, $tree, $group);

        try {
            $this->em->getTransaction(ProductGroup::class)->run(function () use ($group, $draft, $tree): void {
                $group->describe($draft->name, $draft->defaultTaxCode, $draft->defaultRevenueAccount);
                $from = $group->getParentId();
                $to   = $draft->parentId > 0 ? $draft->parentId : null;
                if ($from !== $to) {
                    $nodes = array_map(static fn($r) => $r['group'], $tree->rows());
                    $group->moveUnder($to !== null ? $tree->find($to) : null);
                    $group->setSortKey($this->treeService()->nextSortKey($nodes, $group));
                    // The siblings it left close the gap (dense 0..n — TreeService::renumber()).
                    $this->treeService()->renumber($this->treeService()->siblingGroup($nodes, $from, null, $group->getId()));
                }
            });
        } catch (OptimisticLockException) {
            throw new ProductGroupConflictException($groupId, $version);
        }

        return $group;
    }

    /** @throws ProductGroupConflictException the group is gone */
    public function setActive(int $groupId, bool $active): ProductGroup
    {
        $group = $this->groups()->find($groupId) ?? throw new ProductGroupConflictException($groupId, 0);
        $this->em->getTransaction(ProductGroup::class)->run(static fn() => $group->setActive($active));

        return $group;
    }

    /**
     * Deletes an EMPTY group.
     *
     * @throws ProductGroupConflictException the group is gone or its version moved on
     * @throws ProductGroupInUseException    it still carries sub-groups or articles
     */
    public function delete(int $groupId, int $version): void
    {
        $tree  = $this->groups()->tree();
        $group = $tree->find($groupId);
        if ($group === null || $group->getVersion() !== $version) {
            throw new ProductGroupConflictException($groupId, $version);
        }
        $children = $tree->childCount($groupId);
        $products = $this->groups()->productCounts()[$groupId] ?? 0;
        if ($children > 0 || $products > 0) {
            throw new ProductGroupInUseException($groupId, $children, $products);
        }

        try {
            $this->em->getTransaction(ProductGroup::class)->run(function () use ($group): void {
                $this->em->remove($group);
            });
        } catch (ForeignKeyConstraintViolationException $e) {
            // A sub-group or an article arrived between the check and the delete.
            throw new ProductGroupInUseException($groupId, 0, 0, $e);
        }
    }

    // ── the rules ────────────────────────────────────────────────────────

    /** The validator of $draft against the data — $existing = the group being edited. */
    public function validator(ProductGroupDraft $draft, ProductGroupTree $tree, ?ProductGroup $existing): ProductGroupValidator
    {
        $check = new LedgerAccountCheck($this->em);
        /** @var TaxCodeRepository $codes */
        $codes = $this->em->getRepository(TaxCode::class);

        return new ProductGroupValidator(
            $draft,
            $tree,
            $existing,
            taxCode: static fn(string $code): ?TaxCode => $codes->findByCode($code),
            accountProblem: static fn(string $number): ?string => ArticleService::accountProblem($check, $number),
        );
    }

    /** @throws InvalidProductGroupException */
    private function assertValid(ProductGroupDraft $draft, ProductGroupTree $tree, ?ProductGroup $existing): void
    {
        $validator = $this->validator($draft, $tree, $existing);
        if (!$validator->isValid()) {
            throw new InvalidProductGroupException($validator, $draft);
        }
    }

    private function treeService(): TreeService
    {
        return new TreeService();
    }

    private function groups(): ProductGroupRepository
    {
        return $this->em->getRepository(ProductGroup::class);
    }
}
