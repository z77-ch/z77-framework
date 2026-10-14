<?php

namespace Z77\Module\Order\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One status an order can be in (ADR-043 decision 1 with the addendum 2026-10-14, plan §4.2,
 * owner O4: «Status als DATEN»): a German label, a LEVEL (the progress number the transition rule
 * compares — forward = a level at or above the current one) and the behaviour FLAGS code reacts
 * to. Code never asks «is the status X», it asks «does this status consume stock», «is the booking
 * print offered here» — so an installation adds «Abholbereit» with its own stock behaviour without
 * a line of code.
 *
 *   - `offer`              — the order is a quote: the PDF says «Offerte», nothing is invoiceable;
 *   - `reservesStock`      — the stock target RESERVED = the line quantity;
 *   - `consumesStock`      — the stock target CONSUMED = the line quantity;
 *   - `invoiceable`        — the booking print is offered;
 *   - `invoiceBooked`      — the status the booking print SETS (reached by it only);
 *   - `deliveryNoteTarget` — the status the delivery-note print sets (plan D6);
 *   - `final`              — the order is closed.
 *
 * The invariants between the flags and across the rows are the validator's
 * ({@see \Z77\Module\Order\Validators\OrderStatusValidator}); the selection of a status by a flag
 * is {@see \Z77\Module\Order\Services\OrderStatuses}.
 *
 * Installation master data, file-based (a handful of rows), seeded once on first install
 * (`data/framework/order/order_statuses.default.json`, ADR-024 seed-once) and maintained in the
 * backend (Stammdaten › Aufträge › Auftragsstatus). The reference rule (ADR-043 decision 19): an
 * order references its status BY `code` (`sales_order.status_code`); a row is DEACTIVATED, never
 * deleted, and the code is immutable once created ({@see \Z77\Module\Order\Services\OrderMasterData}).
 * The label stays German like every master-data label (addendum 2026-10-14 (3)); a customer-facing
 * label comes with its first consumer (shop / portal).
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

    /** The progress number: forward = a target level at or above the current one (plan §6). Not unique — equal level = lateral. */
    #[Clean('int')]
    private int $level = 0;

    #[Clean('bool')]
    private bool $offer = false;

    #[Clean('bool')]
    private bool $reservesStock = false;

    #[Clean('bool')]
    private bool $consumesStock = false;

    #[Clean('bool')]
    private bool $invoiceable = false;

    #[Clean('bool')]
    private bool $invoiceBooked = false;

    #[Clean('bool')]
    private bool $deliveryNoteTarget = false;

    #[Clean('bool')]
    private bool $final = false;

    /** False = not offered as a target any more; an order that sits in it keeps it. */
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
    public function isOffer(): bool { return $this->offer; }
    public function isReservesStock(): bool { return $this->reservesStock; }
    public function isConsumesStock(): bool { return $this->consumesStock; }
    public function isInvoiceable(): bool { return $this->invoiceable; }
    public function isInvoiceBooked(): bool { return $this->invoiceBooked; }
    public function isDeliveryNoteTarget(): bool { return $this->deliveryNoteTarget; }
    public function isFinal(): bool { return $this->final; }
    public function isActive(): bool { return $this->active; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = $label; }
    public function setLevel(mixed $level): void { $this->level = (int) $level; }
    public function setOffer(mixed $on): void { $this->offer = (bool) $on; }
    public function setReservesStock(mixed $on): void { $this->reservesStock = (bool) $on; }
    public function setConsumesStock(mixed $on): void { $this->consumesStock = (bool) $on; }
    public function setInvoiceable(mixed $on): void { $this->invoiceable = (bool) $on; }
    public function setInvoiceBooked(mixed $on): void { $this->invoiceBooked = (bool) $on; }
    public function setDeliveryNoteTarget(mixed $on): void { $this->deliveryNoteTarget = (bool) $on; }
    public function setFinal(mixed $on): void { $this->final = (bool) $on; }
    public function setActive(mixed $active): void { $this->active = (bool) $active; }

    /** The flags in the form's / the file's key order — the list's badges and the validator read them. @return array<string, bool> */
    public function flags(): array
    {
        return [
            'offer'                => $this->offer,
            'reserves_stock'       => $this->reservesStock,
            'consumes_stock'       => $this->consumesStock,
            'invoiceable'          => $this->invoiceable,
            'invoice_booked'       => $this->invoiceBooked,
            'delivery_note_target' => $this->deliveryNoteTarget,
            'final'                => $this->final,
        ];
    }
}
