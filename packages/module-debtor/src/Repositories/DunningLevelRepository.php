<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see DunningLevel}. `allInOrder()` sorts by
 * `level`, which is the order a dunning run (P4) walks the ladder — the file
 * order is not authoritative for it.
 */
class DunningLevelRepository extends FileRepository
{
    public function findByCode(string $code): ?DunningLevel
    {
        $code = DunningLevel::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<DunningLevel> ascending by level */
    public function allInOrder(): array
    {
        $levels = $this->findAll();
        usort($levels, static fn(DunningLevel $a, DunningLevel $b) => $a->getLevel() <=> $b->getLevel());

        return array_values($levels);
    }
}
