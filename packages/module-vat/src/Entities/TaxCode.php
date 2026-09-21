<?php

namespace Z77\Module\Vat\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A tax code says WHAT KIND of tax applies (ADR-041 decision 2): `UN` is the
 * standard rate on sales, `VM` the input tax on material. How much, and from
 * when, is a separate {@see TaxRate} row per validity — this entity never
 * carries a percentage.
 *
 * Installation master data, file-based (a few dozen rows), seeded once on
 * first install with the Swiss set (`data/framework/vat/tax_codes.default.json`)
 * and maintained in the backend afterwards.
 *
 * The reference rule (ADR-043 decision 19): documents and journal lines
 * reference a tax code BY `code`, snapshot what it shows at the moment of use,
 * and a code is DEACTIVATED, never deleted — nothing in this module removes a
 * row, and the code itself is immutable once created (it is the key ten-year-old
 * invoices carry). `code` is normalized at the setter (trimmed, upper-cased) so
 * every path — form, seed, lookup — compares equal.
 */
#[Entity('file', 'framework/vat/tax_codes.json')]
class TaxCode
{
    use ArrayMappable;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[A-Z0-9]{2,8}`, immutable after creation. */
    #[Clean('ident')]
    private string $code = '';

    /** ISO 3166-1 alpha-2, upper-case — the country pack the code belongs to. */
    #[Clean('ident')]
    private string $country = '';

    /** A {@see TaxCategory} value. Stored as its string so an unknown value is visible, not lost. */
    #[Clean('ident')]
    private string $category = '';

    #[Clean('text')]
    private string $label = '';

    /** False = no longer offered for new documents; history still resolves. */
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
        return mb_strtoupper(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getCountry(): string { return $this->country; }
    public function getCategory(): string { return $this->category; }
    public function getLabel(): string { return $this->label; }
    public function isActive(): bool { return $this->active; }

    /** The typed category, null when the stored string is not one of the model's. */
    public function category(): ?TaxCategory
    {
        return TaxCategory::tryFrom($this->category);
    }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setCountry(string $country): void { $this->country = mb_strtoupper(trim($country)); }
    public function setCategory(string $category): void { $this->category = trim($category); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setActive(bool $active): void { $this->active = $active; }
}
