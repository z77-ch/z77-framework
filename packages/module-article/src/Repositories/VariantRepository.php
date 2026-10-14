<?php

namespace Z77\Module\Article\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Z77\Module\Article\Entities\Product;
use Z77\Module\Article\Entities\Variant;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Reads of the variant stage — the article number, the unit, the default variant of a product.
 * Doctrine-only (DQL / SQL).
 */
class VariantRepository extends DoctrineRepository
{
    /** The variant carrying $number, or null. */
    public function findByNumber(string $number): ?Variant
    {
        return $this->findOneBy(['number' => $number]);
    }

    /** The DEFAULT variant (empty label) of $product — every saved product has one. */
    public function defaultOf(Product $product): ?Variant
    {
        return $this->findOneBy(['product' => $product, 'label' => '']);
    }

    /**
     * The variants a NEW document line may name: active, of an active product, by number — the
     * article picker of a source (module-order's order window, a `<datalist>`). One query, the
     * products joined. Article knows no source; the source asks.
     *
     * @return list<Variant>
     */
    public function selectable(): array
    {
        return $this->em()->createQueryBuilder()
            ->select('v', 'p')->from(Variant::class, 'v')->join('v.product', 'p')
            ->where('v.active = true')->andWhere('p.active = true')
            ->orderBy('v.number', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Variant> every variant of $product, by id */
    public function allOf(Product $product): array
    {
        return $this->em()->createQueryBuilder()
            ->select('v')->from(Variant::class, 'v')
            ->where('v.product = :product')->setParameter('product', $product)
            ->orderBy('v.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * The default variants of $products in ONE query — the list's rows (no N+1). Doctrine-only.
     *
     * @param list<Product> $products
     * @return array<int, Variant> product id → default variant
     */
    public function defaultsFor(array $products): array
    {
        $ids = array_values(array_filter(array_map(static fn(Product $p) => $p->getId(), $products)));
        if ($ids === []) {
            return [];
        }
        $byProduct = [];
        $variants  = $this->em()->createQueryBuilder()
            ->select('v')->from(Variant::class, 'v')
            ->where('IDENTITY(v.product) IN (:ids)')->andWhere("v.label = ''")
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->getQuery()->getResult();
        foreach ($variants as $variant) {
            $byProduct[$variant->getProduct()->getId()] = $variant;
        }

        return $byProduct;
    }
}
