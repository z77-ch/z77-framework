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

    /**
     * The terms a NEW debtor profile gets when nobody names others (owner 2026-10-12): the row
     * marked `default` — or null when none is marked or the marked row is INACTIVE (an inactive
     * row is never given to a new reference, ADR-043 decision 19). The caller then keeps the
     * behaviour without a default: terms must be chosen; nothing is invented. The write path
     * keeps the marker on one row; a hand-edited file with two answers the first in file order.
     */
    public function defaultForNewDebtor(): ?PaymentTerms
    {
        foreach ($this->allInOrder() as $terms) {
            if ($terms->isDefault()) {
                return $terms->isActive() ? $terms : null;
            }
        }

        return null;
    }

    /** @return list<PaymentTerms> in file order (the seed puts the net terms first) */
    public function allInOrder(): array
    {
        return array_values($this->findAll());
    }
}
