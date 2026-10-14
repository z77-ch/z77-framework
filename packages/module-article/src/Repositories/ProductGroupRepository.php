<?php

namespace Z77\Module\Article\Repositories;

use Z77\Module\Article\Entities\ProductGroup;
use Z77\Module\Article\Services\ProductGroupTree;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Reads of the product-group tree (plan §4b A1). The tree is small (a handful to a few hundred
 * nodes), so it is read WHOLE in one query and asked through {@see ProductGroupTree}; the article
 * counts per group in a second one. Doctrine-only (DQL / SQL).
 */
class ProductGroupRepository extends DoctrineRepository
{
    /** Every group, as one tree. */
    public function tree(): ProductGroupTree
    {
        return new ProductGroupTree($this->em()->createQueryBuilder()
            ->select('g')->from(ProductGroup::class, 'g')
            ->orderBy('g.sortKey', 'ASC')->addOrderBy('g.id', 'ASC')
            ->getQuery()->getResult());
    }

    /**
     * How many products hang DIRECTLY on each group — the tree screen's counts and the delete
     * refusal. Doctrine-only (SQL).
     *
     * @return array<int, int> group id → products
     */
    public function productCounts(): array
    {
        $counts = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT product_group_id AS id, COUNT(*) AS n FROM article_product GROUP BY product_group_id') as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }
}
