<?php

namespace Z77\Module\Vat\Repositories;

use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see TaxRate}. The one question that matters is
 * {@see findEffective()}: which row applies to a code on a given day — the
 * latest `validFrom` that is not after the date (ADR-041 decision 4: resolved
 * by the service date).
 */
class TaxRateRepository extends FileRepository
{
    /** @return list<TaxRate> all rows of a code, newest validFrom first */
    public function findByCode(string $code): array
    {
        $rows = $this->findBy(['code' => TaxCode::normalizeCode($code)]);
        usort($rows, static fn(TaxRate $a, TaxRate $b) => strcmp($b->getValidFrom(), $a->getValidFrom()));

        return array_values($rows);
    }

    /** @return array<string, list<TaxRate>> code → rows, newest validFrom first */
    public function allGroupedByCode(): array
    {
        $grouped = [];
        foreach ($this->findAll() as $rate) {
            $grouped[$rate->getCode()][] = $rate;
        }
        foreach ($grouped as &$rows) {
            usort($rows, static fn(TaxRate $a, TaxRate $b) => strcmp($b->getValidFrom(), $a->getValidFrom()));
        }
        unset($rows);
        ksort($grouped);

        return $grouped;
    }

    /** The row in effect on $date, or null when no validity has started yet. */
    public function findEffective(string $code, \DateTimeImmutable $date): ?TaxRate
    {
        foreach ($this->findByCode($code) as $rate) {   // newest first → first hit wins
            if ($rate->isEffectiveOn($date)) {
                return $rate;
            }
        }

        return null;
    }

    public function findByCodeAndValidFrom(string $code, string $validFrom): ?TaxRate
    {
        return $this->findOneBy(['code' => TaxCode::normalizeCode($code), 'valid_from' => trim($validFrom)]);
    }
}
