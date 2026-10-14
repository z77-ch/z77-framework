<?php

namespace Z77\Module\Article\Repositories;

use Doctrine\DBAL\ParameterType;
use Z77\Module\Article\Entities\Product;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Reads of the product stage. The list searches over the product AND its DEFAULT variant (the
 * empty label — the flat article of P6 slim, plan §4.1): one row per product, the number from
 * the default variant. Doctrine-only (SQL + DQL); the WHERE values are bound, the ORDER BY comes
 * from a fixed map.
 */
class ProductRepository extends DoctrineRepository
{
    /**
     * One page of products matching $search, in the asked order. Doctrine-only.
     *
     * @return list<Product>
     */
    public function search(ArticleSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            'name'  => "p.name {$dir}, v.number {$dir}, p.id {$dir}",
            default => "v.number {$dir}, p.id {$dir}",
        };
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT p.id FROM article_product p LEFT JOIN article_variant v ON v.product_id = p.id AND v.label = ''{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        );
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->em()->createQueryBuilder()->select('p')->from(Product::class, 'p')->where('p.id IN (:ids)')->setParameter('ids', array_map('intval', $ids))->getQuery()->getResult() as $product) {
            $byId[$product->getId()] = $product;
        }

        return array_values(array_filter(array_map(static fn($id) => $byId[(int) $id] ?? null, $ids)));
    }

    /** How many products match $search — the list's pager and badge. Doctrine-only (SQL). */
    public function countSearch(ArticleSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM article_product p LEFT JOIN article_variant v ON v.product_id = p.id AND v.label = ''{$where}",
            $params,
            $types
        );
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<ParameterType>} */
    private function searchWhere(ArticleSearch $search): array
    {
        $where  = [];
        $params = [];
        $types  = [];
        $like   = static fn(string $text): string => '%' . addcslashes($text, '\\%_') . '%';
        if ($search->number !== null && $search->number !== '') {
            $where[]  = 'v.number LIKE ?';
            $params[] = $like($search->number);
            $types[]  = ParameterType::STRING;
        }
        if ($search->name !== null && $search->name !== '') {
            $where[]  = 'p.name LIKE ?';
            $params[] = $like($search->name);
            $types[]  = ParameterType::STRING;
        }
        if ($search->taxCode !== null && $search->taxCode !== '') {
            $where[]  = 'p.tax_code = ?';
            $params[] = $search->taxCode;
            $types[]  = ParameterType::STRING;
        }
        if ($search->account !== null && $search->account !== '') {
            $where[]  = 'p.revenue_account = ?';
            $params[] = $search->account;
            $types[]  = ParameterType::STRING;
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $types];
    }
}
