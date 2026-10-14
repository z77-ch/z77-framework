<?php

namespace Z77\Module\Order\Services;

/**
 * The order was changed since the caller's screen was rendered (its version moved on), it is
 * gone, or the draft names a line that is not (or no longer) the order's — the screen answers
 * «neu laden». Nothing was written.
 */
final class OrderConflictException extends OrderException
{
    public const MESSAGE = 'Der Auftrag wurde inzwischen geändert — nichts gespeichert. Bitte neu laden und die Änderung nochmals anbringen.';

    public function __construct(public readonly int $orderId, public readonly int $expectedVersion)
    {
        parent::__construct("Order {$orderId} was changed or removed in the meantime (expected version {$expectedVersion}) — reload it");
    }
}
