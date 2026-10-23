<?php

namespace Z77\Module\Debtor\Services;

/**
 * «Journal verbuchen» refused (`JournalPostingService::post()`, ADR-054 decision 6). `reason` is a
 * stable code, `getMessage()` German and says what to do. Nothing was posted.
 *
 *   - `empty`   — the Faktura-Journal holds no document;
 *   - `blocked` — a document is flagged «Neu fakturieren nötig» (`reissue_needed`, decision 9);
 *   - `changed` — the journal is not what the confirmation showed (a document issued, revised,
 *                 posted or replaced since): reload and confirm again.
 */
final class JournalRefusedException extends DebtorException
{
    public const EMPTY   = 'empty';
    public const BLOCKED = 'blocked';
    public const CHANGED = 'changed';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
