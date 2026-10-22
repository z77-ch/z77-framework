<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Ledger\EntryRef;

/** What {@see YearOpeningService::create()} did. */
final class YearOpeningResult
{
    /** A first opening was posted. */
    public const CREATED   = 'created';
    /** The existing opening was replaced in place (same number, a change row in the log). */
    public const UPDATED   = 'updated';
    /** The existing opening already said what the predecessor says — nothing written. */
    public const UNCHANGED = 'unchanged';
    /** The predecessor no longer has any balance — the existing opening was removed (its number a documented gap). */
    public const REMOVED   = 'removed';

    public function __construct(
        /** One of the constants above. */
        public readonly string $action,
        /** Where the opening is (or was, for REMOVED). */
        public readonly EntryRef $ref,
        /** The predecessor was still open — the opening is provisional. */
        public readonly bool $provisional,
        public readonly string $predecessorCode,
    ) {}
}
