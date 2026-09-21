<?php

namespace Z77\Module\Contact\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * What an address IS for a contact — main, invoice, delivery, regional, …
 * (plan §4a, «managed data, like wdv AddressType»). Installation master
 * data, file-based (a handful of rows), seeded once on first install
 * (`data/framework/contact/address_types.default.json`) and maintained in
 * the backend.
 *
 * The reference rule (ADR-043 decision 19): {@see ContactAddress} rows
 * reference a type BY `code`; a type is DEACTIVATED, never deleted — nothing
 * in this module removes a row — and the code is immutable once created (it
 * is the key the database rows carry). `code` is normalized at the setter
 * (trimmed, lower-cased) so every path — form, seed, lookup — compares equal.
 */
#[Entity('file', 'framework/contact/address_types.json')]
class AddressType
{
    use ArrayMappable;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation. */
    #[Clean('ident')]
    private string $code = '';

    #[Clean('text')]
    private string $label = '';

    /** False = not offered for a NEW link; existing links still resolve. */
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** The one normalization every code comparison relies on. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function isActive(): bool { return $this->active; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setActive(bool $active): void { $this->active = $active; }
}
