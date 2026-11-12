<?php

namespace Z77\Module\Receipt\Repositories;

use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see PaymentMethod}. The collection file does not exist until the
 * first row is written (nothing is seeded), and `findAll()` on a missing file is an empty list.
 */
class PaymentMethodRepository extends FileRepository
{
    public function findByCode(string $code): ?PaymentMethod
    {
        $code = PaymentMethod::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<PaymentMethod> every row in choice order (position, id) */
    public function allInOrder(): array
    {
        $rows = array_values($this->findAll());
        usort($rows, [PaymentMethod::class, 'compare']);

        return $rows;
    }

    /** @return list<PaymentMethod> the active rows in choice order — what the phone offers */
    public function activeInOrder(): array
    {
        return array_values(array_filter($this->allInOrder(), static fn(PaymentMethod $m) => $m->isActive()));
    }
}
