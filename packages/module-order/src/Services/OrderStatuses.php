<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Order\Entities\OrderStatus;

/**
 * The status RULES of plan §6 over one snapshot of the status rows — pure, no database, no file
 * (the harness checks them offline, section T). Built from `OrderStatusRepository::allInOrder()`.
 *
 * **No code path selects a status by its `code`** (ADR-043 consequence, harness N1): a step that
 * needs «the» status asks a FLAG —
 *
 *   - {@see initial()}            — a new order: the ACTIVE status with the LOWEST level;
 *   - {@see deliveryNoteTarget()} — the delivery-note print (D6): the active `deliveryNoteTarget` row;
 *   - {@see invoiceBooked()}      — the booking print: the active `invoiceBooked` row.
 *
 * A code only comes in from OUTSIDE — the status an order already holds, the target a user picked.
 *
 * Transitions ({@see refusal()}): forward = target level ≥ current level (equal = lateral, the two
 * «erledigt»), always allowed; backward = a lower level, allowed only while the order has NO
 * invoice (ADR-043 decision 4 — the bar comes from the invoice, not from the status model). Only
 * ACTIVE statuses are targets; an order keeps a deactivated one. The `invoiceBooked` row is
 * reached ONLY by the booking print — never a target of a status change (otherwise «gebucht»
 * without an invoice).
 */
final class OrderStatuses
{
    public const SAME            = 'same-status';
    public const INACTIVE_TARGET = 'inactive-target';
    public const BOOKED_TARGET   = 'booked-by-print-only';
    public const BACKWARD_BARRED = 'backward-barred';

    /** @var list<OrderStatus> ascending by level, then id */
    private readonly array $rows;

    /** @param list<OrderStatus> $rows every status row, active or not */
    public function __construct(array $rows)
    {
        usort($rows, static fn(OrderStatus $a, OrderStatus $b) => [$a->getLevel(), $a->getId() ?? PHP_INT_MAX] <=> [$b->getLevel(), $b->getId() ?? PHP_INT_MAX]);
        $this->rows = array_values($rows);
    }

    /** @return list<OrderStatus> */
    public function all(): array
    {
        return $this->rows;
    }

    /** The row carrying $code — an order's own status or a target a user picked — or null. */
    public function byCode(string $code): ?OrderStatus
    {
        $code = OrderStatus::normalizeCode($code);
        foreach ($this->rows as $row) {
            if ($row->getCode() === $code) {
                return $row;
            }
        }

        return null;
    }

    /** A new order's status: the active one with the lowest level (then the lowest id); null = no active status. */
    public function initial(): ?OrderStatus
    {
        foreach ($this->rows as $row) {
            if ($row->isActive()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The statuses a NEW order may start in when the create form picks one: every active one
     * except the booked one (reached by the booking print only) and the final ones (an order is
     * not created closed).
     *
     * @return list<OrderStatus>
     */
    public function startable(): array
    {
        return array_values(array_filter($this->rows, static fn(OrderStatus $s) => $s->isActive() && !$s->isInvoiceBooked() && !$s->isFinal()));
    }

    /** The active status the delivery-note print sets (D6), or null. */
    public function deliveryNoteTarget(): ?OrderStatus
    {
        foreach ($this->rows as $row) {
            if ($row->isActive() && $row->isDeliveryNoteTarget()) {
                return $row;
            }
        }

        return null;
    }

    /** The active status the booking print sets, or null — the booking print is refused then, naming what to mark. */
    public function invoiceBooked(): ?OrderStatus
    {
        foreach ($this->rows as $row) {
            if ($row->isActive() && $row->isInvoiceBooked()) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Why an order in $from may NOT move to $to by a status change — null = allowed.
     *
     * @return array{0: string, 1: string}|null the reason constant and the German message
     */
    public static function refusal(OrderStatus $from, OrderStatus $to, bool $hasInvoice): ?array
    {
        if ($to->getCode() === $from->getCode()) {
            return [self::SAME, 'Der Auftrag ist bereits im Status «' . $to->getLabel() . '».'];
        }
        if (!$to->isActive()) {
            return [self::INACTIVE_TARGET, 'Der Status «' . $to->getLabel() . '» ist deaktiviert und wird nicht mehr vergeben.'];
        }
        if ($to->isInvoiceBooked()) {
            return [self::BOOKED_TARGET, '«' . $to->getLabel() . '» setzt nur der Buchungsdruck — er verbucht die Rechnung und setzt den Status in einem Schritt.'];
        }
        if ($to->getLevel() < $from->getLevel() && $hasInvoice) {
            return [self::BACKWARD_BARRED, 'Zum Auftrag ist eine Rechnung verbucht — zurück auf «' . $to->getLabel() . '» geht nicht mehr. Eine Korrektur ist eine Gutschrift auf der Rechnung.'];
        }

        return null;
    }

    /**
     * The targets a status change offers an order in $from (the confirm list of the screen): every
     * row {@see refusal()} allows, in level order.
     *
     * @return list<OrderStatus>
     */
    public function targets(OrderStatus $from, bool $hasInvoice): array
    {
        return array_values(array_filter($this->rows, static fn(OrderStatus $to) => self::refusal($from, $to, $hasInvoice) === null));
    }

    /** Whether moving from $from to $to is forward (or lateral) — the delivery-note print moves only so. */
    public static function isForward(OrderStatus $from, OrderStatus $to): bool
    {
        return $to->getLevel() >= $from->getLevel();
    }
}
