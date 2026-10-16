<?php

namespace Z77\Module\Order\Entities;

/**
 * The PHASE of an order — fixed in code, the ladder every behaviour hangs on (ADR-043 addendum
 * 2026-10-15, owner: «die Phase war bisher mein Level in wdv-630»). A status is only a label the
 * installation sorts its orders by; it belongs to exactly one phase ({@see OrderStatus::getPhase()}),
 * and code asks the PHASE — never a status code, never a flag.
 *
 *   new       — «Eröffnet»: a quote or a captured order: no stock, no booking print, the PDF says «Offerte»;
 *   confirmed — «Termin»: the order is confirmed: stock RESERVED, the booking print is offered;
 *   delivered — the goods left the shelf («bereitgestellt», «geliefert», «zu fakturieren»): stock
 *               CONSUMED, the booking print is offered;
 *   closed    — booked or done: stock consumed, the order is locked;
 *   cancelled — the order does not happen: no stock, locked.
 *
 * Whether a line moves stock at all is the article's (`stock_relevant`, decided by the stock ledger) —
 * a service or a free line never does, in any phase.
 */
enum OrderPhase: string
{
    case New       = 'new';
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    case Closed    = 'closed';
    case Cancelled = 'cancelled';

    /** German, for the backend — the status screen's choice and the order list's tabs. */
    public function label(): string
    {
        return match ($this) {
            self::New       => 'Eröffnet',     // owner 2026-10-16 (was «Neu / Offerte»)
            self::Confirmed => 'Termin',       // owner 2026-10-16 (was «Bestätigt»)
            self::Delivered => 'Geliefert',
            self::Closed    => 'Abgeschlossen',
            self::Cancelled => 'Storniert',
        };
    }

    /**
     * The ACTION that moves an order into this phase and sets its default status — for the status
     * list's column «Wird gesetzt durch» (owner 2026-10-15: «Vorgabe der Phase» was understood by
     * no one); null = no action moves an order here: cancelling is by hand, and a NEW order takes
     * the start status (`OrderStatus::isStart()`, owner 2026-10-16), not a default of `new`.
     */
    public function action(): ?string
    {
        return match ($this) {
            self::New       => null,
            self::Confirmed => 'Auftragsbestätigung',
            self::Delivered => 'Lieferschein',
            self::Closed    => 'Buchungsdruck',
            self::Cancelled => null,
        };
    }

    /** What the default switch of a status in this phase says — the same fact as {@see action()}, as a sentence. */
    public function defaultSwitchLabel(): ?string
    {
        return match ($this) {
            self::New       => null,
            self::Confirmed => 'Wird gesetzt beim Druck der Auftragsbestätigung',
            self::Delivered => 'Wird gesetzt beim Druck des Lieferscheins',
            self::Closed    => 'Wird gesetzt beim Buchungsdruck',
            self::Cancelled => null,
        };
    }

    /** The place on the ladder: forward = a rank at or above the current one. */
    public function rank(): int
    {
        return match ($this) {
            self::New       => 1,
            self::Confirmed => 2,
            self::Delivered => 3,
            self::Closed    => 4,
            self::Cancelled => 5,
        };
    }

    /** A line's stock target in this phase: RESERVED = its quantity. */
    public function reservesStock(): bool
    {
        return $this === self::Confirmed;
    }

    /** A line's stock target in this phase: CONSUMED = its quantity. */
    public function consumesStock(): bool
    {
        return $this === self::Delivered || $this === self::Closed;
    }

    /** The order is still a quote — the PDF says «Offerte», the order confirmation moves it on. */
    public function isQuote(): bool
    {
        return $this === self::New;
    }

    /** The booking print is offered (every confirmed order — a service order may never see a delivery note). */
    public function isBookable(): bool
    {
        return $this === self::Confirmed || $this === self::Delivered;
    }

    /** No further edit: booked or done, or cancelled. */
    public function isLocked(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    /** The delivery note may be printed (from a confirmed order on). */
    public function allowsDeliveryNote(): bool
    {
        return $this === self::Confirmed || $this === self::Delivered;
    }

    /**
     * A status of this phase may be THE start status of new orders (owner 2026-10-16: «Eröffnet»
     * and «Termin» — a service order may start as «Terminauftrag»).
     */
    public function allowsStart(): bool
    {
        return $this === self::New || $this === self::Confirmed;
    }

    /** A new order may START here (the create form's choice) — not closed or cancelled. */
    public function isStartable(): bool
    {
        return !$this->isLocked();
    }

    /** @return array<string, string> value → label, in ladder order */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $phase) {
            $choices[$phase->value] = $phase->label();
        }

        return $choices;
    }
}
