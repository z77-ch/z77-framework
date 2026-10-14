<?php

namespace Z77\Module\Order\Services;

/**
 * A write tried to change the `code` of an existing order status. The code is what
 * `sales_order.status_code` holds (ADR-043 decision 19), so it is immutable once created: for
 * another meaning, a new row.
 */
final class OrderStatusCodeChangedException extends OrderException
{
    public function __construct(
        public readonly string $storedCode,
        public readonly string $submittedCode,
    ) {
        parent::__construct("The code of an existing order status is immutable: stored '{$storedCode}', submitted '{$submittedCode}'");
    }
}
