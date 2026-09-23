<?php

namespace Z77\Module\Debtor\Entities;

use Z77\Shared\Money\Money;
use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One step of the dunning ladder (plan §6.5): «Zahlungserinnerung» after n
 * days, «1. Mahnung» with a fee after more, and so on. A dunning run (P4)
 * walks these rows in `level` order.
 *
 * Installation master data, file-based (a handful of rows), seeded once on
 * first install (`data/framework/debtor/dunning_levels.default.json`,
 * ADR-024 seed-once) and maintained in the backend.
 *
 * The reference rule (ADR-043 decision 19): a dunning notice references its
 * level BY `code`; a row is DEACTIVATED, never deleted, and the code is
 * immutable once created.
 *
 * **No VAT on a dunning fee** (plan §6.5): the fee compensates the effort of
 * chasing a debt, it is not a supply — so this entity carries NO tax code
 * and never will, and the fee is posted to the mandator's dunning-fee
 * account (`DebtorAccounts::number('dunningFee')`) without a tax line.
 *
 * The fee is stored as INTEGER MINOR UNITS (Rappen), the way every
 * file-based entity stores money (plan §3) — `Money` itself is not
 * serialisable to a JSON row, and the currency is the installation's base
 * currency, held once in `systemConfig → baseCurrency`. {@see fee()} builds
 * the `Money` from a currency the caller passes; the entity reads no config.
 */
#[Entity('file', 'framework/debtor/dunning_levels.json')]
class DunningLevel
{
    use ArrayMappable;
    use HasDocumentText;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation. */
    #[Clean('ident')]
    private string $code = '';

    /** German, for the backend list — NOT what prints on the notice. */
    #[Clean('text')]
    private string $label = '';

    /** The step number, 1 upwards, unique across the rows — the order a dunning run walks. */
    #[Clean('int')]
    private int $level = 0;

    /** Days after the due date at which this level becomes applicable. */
    #[Clean('int')]
    private int $daysAfterDue = 0;

    /** The fee in MINOR UNITS of the base currency (plan §3); 0 = no fee. */
    #[Clean('int')]
    private int $fee = 0;

    /** False = not offered for a NEW notice; existing ones still resolve. */
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
    public function getLevel(): int { return $this->level; }
    public function getDaysAfterDue(): int { return $this->daysAfterDue; }
    public function isActive(): bool { return $this->active; }

    /** The stored minor units — the form's field and what the JSON row holds. */
    public function getFee(): int { return $this->fee; }

    /** The fee as `Money` in $currency — the installation's base currency, passed by the caller. */
    public function fee(string $currency): Money
    {
        return Money::of($this->fee, $currency);
    }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setLevel(mixed $level): void { $this->level = (int) $level; }
    public function setDaysAfterDue(mixed $days): void { $this->daysAfterDue = (int) $days; }
    public function setFee(mixed $minor): void { $this->fee = (int) $minor; }
    public function setActive(bool $active): void { $this->active = $active; }
}
