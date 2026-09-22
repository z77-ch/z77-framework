<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Ledger\EntryRef;

/**
 * A repeated idempotency key arrived with DIFFERENT content than the entry
 * it posted the first time (ADR-040 decision 2, ADR-042 decision 6). Returning
 * the old entry silently would hide that the source and the books disagree;
 * posting again would post twice. So: refused, naming the entry that holds
 * the key. The caller decides — usually a bug in how it builds the key.
 */
final class IdempotencyConflictException extends PostingRefusedException
{
    public function __construct(public readonly string $idempotencyKey, public readonly EntryRef $existing)
    {
        parent::__construct(
            self::IDEMPOTENCY_CONFLICT,
            "Idempotency key '{$idempotencyKey}' already posted entry {$existing->fiscalYear}/{$existing->number} with different content — "
            . 'a repeated key must repeat the same posting (ADR-040 decision 2)'
        );
    }
}
