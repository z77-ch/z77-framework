<?php

namespace Z77\Module\Contact\Services;

use Z77\Module\Contact\Entities\AddressType;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of the address-type master data — the rules that hold no
 * matter which screen or script writes (ADR-043 decision 19):
 *
 *   - a type is never deleted, only deactivated: there is no method for it;
 *   - the `code` of an existing row is immutable — `contact_address` rows
 *     carry it.
 *
 * Field validation is {@see \Z77\Module\Contact\Validators\AddressTypeValidator}'s.
 */
final class AddressTypeMasterData
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * @throws AddressTypeCodeChangedException the code of an existing row differs from what is stored
     */
    public function save(AddressType $type): void
    {
        if ($type->getId() !== null) {
            $stored = $this->em->getRepository(AddressType::class)->find($type->getId());
            if ($stored !== null && $stored->getCode() !== $type->getCode()) {
                throw new AddressTypeCodeChangedException($stored->getCode(), $type->getCode());
            }
        }

        $this->em->persist($type);
        $this->em->flush();
    }

    public function setActive(AddressType $type, bool $active): void
    {
        $type->setActive($active);
        $this->save($type);
    }
}
