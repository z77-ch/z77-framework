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

    /** D7: the order has a booked invoice or a final status — read-only. */
    public const NOT_EDITABLE = 'not-editable';

    // Transition refusals (plan §6) — the constants of OrderStatuses.
    public const SAME_STATUS     = OrderStatuses::SAME;
    public const INACTIVE_TARGET = OrderStatuses::INACTIVE_TARGET;
    public const BOOKED_TARGET   = OrderStatuses::BOOKED_TARGET;
    public const BACKWARD_BARRED = OrderStatuses::BACKWARD_BARRED;

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
