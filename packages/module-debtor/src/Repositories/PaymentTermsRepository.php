<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see PaymentTerms} (`…\Entities\PaymentTerms` →
 * `…\Repositories\PaymentTermsRepository`, discovered by `RepositoryConvention`).
 * File driver: every lookup scans the collection — fine for a handful of rows.
 */
class PaymentTermsRepository extends FileRepository
{
    public function findByCode(string $code): ?PaymentTerms
    {
        $code = PaymentTerms::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<PaymentTerms> in file order (the seed puts the net terms first) */
    public function allInOrder(): array
    {
        return array_values($this->findAll());
    }
}
