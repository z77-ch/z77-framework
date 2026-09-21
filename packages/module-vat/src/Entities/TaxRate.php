<?php

namespace Z77\Module\Vat\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * How much a {@see TaxCode} charges FROM WHEN (ADR-041 decision 2): one row
 * per validity. A rate is valid from `validFrom` until the day before the next
 * `validFrom` of the same code — open-ended for the latest row. A rate change
 * is a NEW row; the old ones stay, so a document dated 2023 still resolves the
 * 7.7 % that applied then.
 *
 * `rate` is an integer in hundredths of a percent — 8.1 % is `810` (ADR-042
 * decision 2). A form posts a percent string ("8.1"); the controller removes
 * `rate` from the body, converts the string through {@see percentToHundredths()}
 * without a float in between and calls {@see setRate()} with the int. Note
 * that `BodyCleaner` passes a property WITHOUT `#[Clean]` through raw, so the
 * controller's `unset()` is what keeps a body value away from the setter — and
 * the setter refuses anything but an int, so a raw string could never land.
 *
 * Immutable in substance: the backend adds rows and removes only a row that
 * is not yet in effect — or one entered TODAY for today, a same-day typo
 * ({@see \Z77\Module\Vat\Services\VatMasterData}, owner 2026-09-21); a rate
 * that applied is history. `createdOn` exists for that one rule and is set by
 * the write service, never by a form (the controller drops it from the body).
 */
#[Entity('file', 'framework/vat/tax_rates.json')]
class TaxRate
{
    use ArrayMappable;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** The {@see TaxCode::$code} this rate belongs to (referenced by code, ADR-043 decision 19). */
    #[Clean('ident')]
    private string $code = '';

    /** `YYYY-MM-DD` — ISO strings compare correctly as strings. */
    #[Clean('text')]
    private string $validFrom = '';

    /** Hundredths of a percent, non-negative. */
    private int $rate = 0;

    /** `YYYY-MM-DD` the row was created, set by {@see \Z77\Module\Vat\Services\VatMasterData::addRate()}; '' on seed rows. */
    private string $createdOn = '';

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getValidFrom(): string { return $this->validFrom; }
    public function getRate(): int { return $this->rate; }
    public function getCreatedOn(): string { return $this->createdOn; }

    public function setCode(string $code): void { $this->code = TaxCode::normalizeCode($code); }
    public function setValidFrom(string $validFrom): void { $this->validFrom = trim($validFrom); }
    public function setCreatedOn(string $createdOn): void { $this->createdOn = trim($createdOn); }

    /** Int only — a float rate (8.1) is exactly the wdv mistake this module exists to end. */
    public function setRate(mixed $rate): void
    {
        if (!is_int($rate)) {
            throw new \TypeError('TaxRate: rate must be int (hundredths of a percent), ' . get_debug_type($rate) . ' given');
        }
        $this->rate = $rate;
    }

    /** True when this row's validity has started on $date (it may have been superseded since). */
    public function isEffectiveOn(\DateTimeImmutable $date): bool
    {
        return $this->validFrom !== '' && $this->validFrom <= $date->format('Y-m-d');
    }

    /** `YYYY-MM-DD` that names a real calendar day. */
    public static function isValidDate(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * "8.1", "8,10", "0", "2.6 %" → hundredths of a percent, in integer
     * arithmetic. Surrounding whitespace and a trailing percent sign (with or
     * without a space before it) are tolerated; whitespace INSIDE the number
     * ("8 1") is refused — silently dropping it would turn 8.1 into 81. More
     * than two decimals is refused — a third decimal is not a VAT rate. Comma
     * is accepted because Swiss keyboards produce it.
     */
    public static function percentToHundredths(string $percent): int
    {
        $normalized = str_replace(',', '.', preg_replace('/\s*%\z/u', '', trim($percent)));
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $normalized, $m)) {
            throw new \InvalidArgumentException("Not a VAT rate in percent with at most two decimals: '{$percent}'");
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 810 → "8.1", 260 → "2.6", 0 → "0", 1000 → "10", 825 → "8.25". */
    public static function formatPercent(int $rate): string
    {
        $whole    = intdiv($rate, 100);
        $fraction = $rate % 100;
        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole . '.' . rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }
}
