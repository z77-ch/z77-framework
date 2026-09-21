<?php

namespace Z77\Module\Contact\Services;

use Z77\Module\Contact\Entities\AddressType;
use Z77\Module\Contact\Repositories\AddressTypeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «What is address type X?» — the read side of the file-based master data,
 * the reference rule (ADR-043 decision 19) in lookup form:
 *
 *   - {@see labelOf()} answers for ANY existing code, deactivated or not — a
 *     `contact_address` row created years ago still names its type — and
 *     never throws, because a list of contacts must render even when a
 *     `data/` restore lost a type row: the raw code shows, and the write
 *     path (the validator) refuses it;
 *   - {@see active()} is what a NEW link may choose from;
 *   - {@see all()} is every row keyed by code, for a screen that must show
 *     a link's current, possibly deactivated, type.
 *
 * A `resolve(code)` that throws arrives with the first consumer that needs
 * the whole row (the address snapshot in P3), not before.
 */
final class AddressTypes
{
    /** @var array<string, AddressType>|null code → type, loaded once per instance */
    private ?array $byCode = null;

    public function __construct(private readonly AddressTypeRepository $types) {}

    public static function from(UnifiedEntityManager $em): self
    {
        return new self($em->getRepository(AddressType::class));
    }

    /** The label for a screen — the raw code when the row is gone (never throws). */
    public function labelOf(string $code): string
    {
        return ($this->all()[AddressType::normalizeCode($code)] ?? null)?->getLabel() ?? $code;
    }

    /** @return list<AddressType> what a new link may use, in file order */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn(AddressType $t) => $t->isActive()));
    }

    /** @return array<string, AddressType> every row, keyed by code, in file order */
    public function all(): array
    {
        if ($this->byCode === null) {
            $this->byCode = [];
            foreach ($this->types->allInOrder() as $type) {
                $this->byCode[$type->getCode()] = $type;
            }
        }

        return $this->byCode;
    }
}
