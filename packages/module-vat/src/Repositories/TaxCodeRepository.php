<?php

namespace Z77\Module\Vat\Repositories;

use Z77\Module\Vat\Entities\TaxCode;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see TaxCode} (`…\Entities\TaxCode` →
 * `…\Repositories\TaxCodeRepository`, discovered by `RepositoryConvention`).
 * File driver: every lookup scans the collection — fine for a few dozen rows.
 */
class TaxCodeRepository extends FileRepository
{
    public function findByCode(string $code): ?TaxCode
    {
        $code = TaxCode::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<TaxCode> ordered by code */
    public function allSorted(): array
    {
        $codes = $this->findAll();
        usort($codes, static fn(TaxCode $a, TaxCode $b) => strcmp($a->getCode(), $b->getCode()));

        return array_values($codes);
    }

    /**
     * The codes a form OFFERS, ordered by code: every ACTIVE code, plus the
     * codes in $keep even when deactivated since — the reference rule
     * (ADR-043 decision 19): a new reference takes an active code, an
     * existing document or entry keeps the code it already carries. The
     * picker of the invoice editor (P3 part 3, `partials/taxCodeSelect`) and
     * the journal's forms read this one list.
     *
     * @param list<string> $keep codes an existing record carries
     * @return list<TaxCode>
     */
    public function selectable(array $keep = []): array
    {
        $kept = array_fill_keys(array_map(static fn(string $c) => TaxCode::normalizeCode($c), $keep), true);

        return array_values(array_filter($this->allSorted(), static fn(TaxCode $code) => $code->isActive() || isset($kept[$code->getCode()])));
    }
}
