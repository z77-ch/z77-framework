<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Order\Validators\OrderStatusValidator;

/**
 * An order status failed its validator inside {@see OrderMasterData} — the validator carries the
 * field errors (the invariants land on the flag that breaks them), so the screen re-renders the
 * form instead of a bare message. NOT the row: a file row is never managed, the object the caller
 * handed in is still the draft to re-render (the debtor model, `InvalidMasterDataException`).
 */
final class InvalidOrderStatusException extends OrderException
{
    public function __construct(public readonly OrderStatusValidator $validator)
    {
        parent::__construct('Order status refused by its validator');
    }
}
