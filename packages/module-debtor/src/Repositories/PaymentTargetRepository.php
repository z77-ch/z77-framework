<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see PaymentTarget}. The collection file does
 * not exist until the first row is written (nothing is seeded — an IBAN
 * cannot be guessed), and `findAll()` on a missing file is an empty list.
 */
class PaymentTargetRepository extends FileRepository
{
    public function findByCode(string $code): ?PaymentTarget
    {
        $code = PaymentTarget::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<PaymentTarget> in file order */
    public function allInOrder(): array
    {
        return array_values($this->findAll());
    }
}
