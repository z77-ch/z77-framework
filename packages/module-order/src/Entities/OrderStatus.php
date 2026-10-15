<?php

namespace Z77\Module\Order\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One status an order can be in — a LABEL the installation orders and finds its orders by
 * («Terminauftrag», «Bestellung», «bereitgestellt», «zu fakturieren»), any number of them (ADR-043
 * addendum 2026-10-15). Every status belongs to exactly ONE fixed {@see OrderPhase}; the phase
 * carries all behaviour (stock, booking print, documents, lock) — a status has none of its own, so
 * no setting of a status can book stock wrongly.
 *
 *   - `phase`        — the phase it belongs to;
 *   - `position`     — its order within the phase (the lists, the choices);
 *   - `phaseDefault` — the status an ACTION sets when it moves an order into this phase (the order
 *                      confirmation, the delivery note, the booking print, a new order); at most
 *                      one ACTIVE default per phase ({@see \Z77\Module\Order\Validators\OrderStatusValidator}).
 *
 * Installation master data, file-based (a handful of rows), seeded once on first install
 * (`data/framework/order/order_statuses.default.json`, ADR-024 seed-once) and maintained in the
 * backend (Stammdaten › Aufträge › Auftragsstatus). The reference rule (ADR-043 decision 19): an
 * order references its status BY `code` (`sales_order.status_code`); a row is DEACTIVATED, never
 * deleted, and the code is immutable once created ({@see \Z77\Module\Order\Services\OrderMasterData}).
 * The label stays German like every master-data label (addendum 2026-10-14 (3)).
 */
#[Entity('file', 'framework/order/order_statuses.json')]
class OrderStatus
{
    use ArrayMappable;

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation — what `sales_order.status_code` holds. */
    #[Clean('ident')]
    private string $code = '';

    /** German, for the backend and the order window. */
    #[Clean('text')]
    private string $label = '';

    /** An {@see OrderPhase} value; '' or an unknown value is refused by the validator. */
    #[Clean('ident')]
    private string $phase = '';

    /** The order within the phase — not unique (equal = by id). */
    #[Clean('int')]
    private int $position = 0;

    /** The status an action sets when it moves an order into this phase. */
    #[Clean('bool')]
    private bool $phaseDefault = false;

    /** False = not offered as a target any more; an order that sits in it keeps it. */
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** The ladder order every list and choice uses: phase, position within it, then id. */
    public static function compare(self $a, self $b): int
    {
        return [$a->getPhase()->rank(), $a->position, $a->id ?? PHP_INT_MAX] <=> [$b->getPhase()->rank(), $b->position, $b->id ?? PHP_INT_MAX];
    }

    /** The one normalization every code comparison relies on. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getPosition(): int { return $this->position; }
    public function isPhaseDefault(): bool { return $this->phaseDefault; }
    public function isActive(): bool { return $this->active; }

    /**
     * The phase. A value the validator would refuse (only a hand-edited file holds one) reads as
     * `new` — the phase that books no stock and offers no booking print, the safe side.
     */
    public function getPhase(): OrderPhase
    {
        return OrderPhase::tryFrom($this->phase) ?? OrderPhase::New;
    }

    /** The stored value as it is — the validator's to judge. */
    public function getPhaseValue(): string
    {
        return $this->phase;
    }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setPhase(mixed $phase): void { $this->phase = $phase instanceof OrderPhase ? $phase->value : trim((string) $phase); }
    public function setPosition(mixed $position): void { $this->position = (int) $position; }
    public function setPhaseDefault(mixed $on): void { $this->phaseDefault = (bool) $on; }
    public function setActive(mixed $active): void { $this->active = (bool) $active; }
}
