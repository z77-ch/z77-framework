<?php

namespace Z77\Module\Receipt\Repositories;

use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see ReceiptTemplate}. The collection file does not exist until the
 * first row is written (nothing is seeded — the accounts depend on the chart), and `findAll()` on a
 * missing file is an empty list.
 */
class ReceiptTemplateRepository extends FileRepository
{
    public function findByCode(string $code): ?ReceiptTemplate
    {
        $code = ReceiptTemplate::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<ReceiptTemplate> every row in tile order (position, id) */
    public function allInOrder(): array
    {
        $rows = array_values($this->findAll());
        usort($rows, [ReceiptTemplate::class, 'compare']);

        return $rows;
    }

    /** @return list<ReceiptTemplate> the active rows in tile order — what the phone shows */
    public function activeInOrder(): array
    {
        return array_values(array_filter($this->allInOrder(), static fn(ReceiptTemplate $t) => $t->isActive()));
    }
}
