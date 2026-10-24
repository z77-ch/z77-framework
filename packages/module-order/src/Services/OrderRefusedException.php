<?php

namespace Z77\Module\Order\Services;

/**
 * A write the order's STATE does not allow (the debtor model, `InvoiceRefusedException`): a stable
 * `reason` the screen and the harness switch on, and the German message for the user. Nothing was
 * written — inside a unit of work the whole of it rolled back.
 */
final class OrderRefusedException extends OrderException
{
    /** No active status exists — a new order has nowhere to start. */
    public const NO_STATUS = 'no-status';

    /** The code is not (or no longer) in the status master data. */
    public const STATUS_UNKNOWN = 'status-unknown';

    /** The create form picked a status an order may not start in (booked, final, inactive). */
    public const NOT_STARTABLE = 'not-startable';

    /** D7: the order has a booked invoice or sits in a locked phase (closed, cancelled) — read-only. */
    public const NOT_EDITABLE = 'not-editable';

    // Transition refusals (plan §6) — the constants of OrderStatuses.
    public const SAME_STATUS     = OrderStatuses::SAME;
    public const INACTIVE_TARGET = OrderStatuses::INACTIVE_TARGET;
    public const BOOKED_TARGET   = OrderStatuses::BOOKED_TARGET;
    public const INVOICED        = OrderStatuses::INVOICED;

    /** A document the order's phase does not print (a delivery note for a quote, anything for a cancelled order). */
    public const NOT_PRINTABLE = 'not-printable';

    // The booking print (plan §7, step 9).
    /** The booking print was asked for no order at all. */
    public const NOTHING_SELECTED = 'nothing-selected';

    /** The phase `closed` has no ACTIVE status — the booking print has no status to set. */
    public const NO_BOOKED_STATUS = 'no-booked-status';

    /** The order is already linked to an invoice — the service never books twice (idempotency is the screen's). */
    public const ALREADY_INVOICED = 'already-invoiced';

    /** The order's phase is not bookable (`confirmed`, `delivered`). */
    public const NOT_INVOICEABLE = 'not-invoiceable';

    /** Debtor refused the invoice drafted from the order — the message names the order and debtor's reason. */
    public const INVOICE_REFUSED = 'invoice-refused';

    /** The order totals 0 — no invoice (owner 2026-10-20): it is closed by the status «Erledigt», nothing printed. */
    public const ZERO_TOTAL = 'zero-total';

    /** «Neu fakturieren» needs an invoice still in the Faktura-Journal (ADR-054 decision 12). */
    public const NOT_REINVOICEABLE = 'not-reinvoiceable';

    /** The order is not deleted: its invoice is POSTED (a credit note corrects it) — ADR-054 decision 14. */
    public const NOT_DELETABLE = 'not-deletable';

    /** The order was deleted — nothing is written to it any more. */
    public const DELETED = 'deleted';

    /** A deletion needs its reason (ADR-054 decision 14). */
    public const REASON_REQUIRED = 'reason-required';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
