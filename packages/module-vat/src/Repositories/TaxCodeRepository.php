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
}
