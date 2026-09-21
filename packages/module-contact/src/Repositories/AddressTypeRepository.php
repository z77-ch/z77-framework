<?php

namespace Z77\Module\Contact\Repositories;

use Z77\Module\Contact\Entities\AddressType;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see AddressType} (`…\Entities\AddressType` →
 * `…\Repositories\AddressTypeRepository`, discovered by `RepositoryConvention`).
 * File driver: every lookup scans the collection — fine for a handful of rows.
 */
class AddressTypeRepository extends FileRepository
{
    public function findByCode(string $code): ?AddressType
    {
        $code = AddressType::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<AddressType> in file order (the seed puts `main` first) */
    public function allInOrder(): array
    {
        return array_values($this->findAll());
    }
}
