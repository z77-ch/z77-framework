<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Ledger\EntryRef;

/** What {@see YearOpeningService::create()} did to the year's set of opening entries. */
final class YearOpeningResult
{
    /** A first opening was posted (no opening stood before). */
    public const CREATED   = 'created';
    /** The standing opening was brought up to date: entries amended in place (same number, a change row each), posted for new accounts, retracted for accounts without a balance. */
    public const UPDATED   = 'updated';
    /** The standing opening already said what the predecessor says — nothing written. */
    public const UNCHANGED = 'unchanged';
    /** The predecessor no longer has any balance — every opening entry was removed (their numbers documented gaps). */
    public const REMOVED   = 'removed';

    public function __construct(
        /** One of the constants above. */
        public readonly string $action,
        /** @var list<EntryRef> the opening entries that stand now, in number order (for REMOVED: the ones removed) */
        public readonly array $refs,
        /** The predecessor was still open — the opening is provisional. */
        public readonly bool $provisional,
        public readonly string $predecessorCode,
        public readonly int $posted = 0,
        public readonly int $amended = 0,
        public readonly int $retracted = 0,
    ) {}
}
