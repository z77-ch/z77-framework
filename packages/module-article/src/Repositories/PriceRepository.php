<?php

namespace Z77\Module\Article\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Z77\Module\Article\Entities\Price;
use Z77\Module\Article\Entities\Variant;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Reads of the price history (plan §4b A4): the price valid at a day — what an order line
 * proposes at its order date —, the row of exactly one day (a same-day change replaces it), the
 * history for the dialog. Doctrine-only (DQL).
 */
class PriceRepository extends DoctrineRepository
{
    /** The row valid at $day: the newest `validFrom` on or before it; null = no price yet then. */
    public function validAt(Variant $variant, \DateTimeImmutable $day): ?Price
    {
        $rows = $this->em()->createQueryBuilder()
            ->select('p')->from(Price::class, 'p')
            ->where('p.variant = :variant')->andWhere('p.validFrom <= :day')
            ->setParameter('variant', $variant)->setParameter('day', $day->setTime(0, 0), 'date_immutable')
            ->orderBy('p.validFrom', 'DESC')->setMaxResults(1)
            ->getQuery()->getResult();

        return $rows[0] ?? null;
    }

    /** The row starting exactly on $day, or null. */
    public function onDay(Variant $variant, \DateTimeImmutable $day): ?Price
    {
        $rows = $this->em()->createQueryBuilder()
            ->select('p')->from(Price::class, 'p')
            ->where('p.variant = :variant')->andWhere('p.validFrom = :day')
            ->setParameter('variant', $variant)->setParameter('day', $day->setTime(0, 0), 'date_immutable')
            ->getQuery()->getResult();

        return $rows[0] ?? null;
    }

    /** @return list<Price> the history of $variant, newest `validFrom` first */
    public function historyOf(Variant $variant): array
    {
        return $this->em()->createQueryBuilder()
            ->select('p')->from(Price::class, 'p')
            ->where('p.variant = :variant')->setParameter('variant', $variant)
            ->orderBy('p.validFrom', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Per variant the row valid at $day, else (a price that starts later only) the EARLIEST
     * future row — the list shows «ab …» then. ONE query for a page. Doctrine-only.
     *
     * @param list<Variant> $variants
     * @return array<int, Price> variant id → row
     */
    public function shownAt(array $variants, \DateTimeImmutable $day): array
    {
        $ids = array_values(array_filter(array_map(static fn(Variant $v) => $v->getId(), $variants)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->em()->createQueryBuilder()
            ->select('p')->from(Price::class, 'p')
            ->where('IDENTITY(p.variant) IN (:ids)')->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->orderBy('p.validFrom', 'ASC')
            ->getQuery()->getResult();
        $day   = $day->setTime(0, 0);
        $shown = [];
        foreach ($rows as $row) {   // ascending: the last row on or before $day wins, else the first one after
            $id = $row->getVariant()->getId();
            if ($row->getValidFrom() <= $day || !isset($shown[$id])) {
                $shown[$id] = $row;
            }
        }

        return $shown;
    }
}
