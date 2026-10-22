<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingRequest;

/**
 * What «Eröffnung erstellen» would write into a fiscal year now — computed
 * by {@see YearOpeningService::plan()} without a lock or a write; the
 * confirm dialog shows it, the fiscal-year list reads its status from it.
 *
 * The opening is a SET of two-line entries, one per account against the
 * contra account (owner 2026-10-22); the plan compares the set as it should
 * be with the set that stands and names what a re-run does to each entry.
 */
final class YearOpeningPlan
{
    public function __construct(
        /** The year the opening goes into. */
        public readonly string $targetCode,
        /** The year whose closing balances it carries. */
        public readonly string $predecessorCode,
        /** The predecessor is still OPEN — the opening is provisional and must be re-created after a change there. */
        public readonly bool $provisional,
        /** @var array<string, PostingRequest> account number → its entry as it would be posted, in chart order; [] = the predecessor has no balance */
        public readonly array $requests,
        /** @var array<string, EntryRef> idempotency key → the generated opening entries that stand in the year now, in number order */
        public readonly array $existing,
        /** @var list<string> the account numbers whose entry is posted anew */
        public readonly array $toPost,
        /** @var list<string> the account numbers whose standing entry is amended in place (same number) */
        public readonly array $toAmend,
        /** @var array<string, list<array<string, mixed>>> idempotency key → the snapshot lines of a standing entry that is removed (no balance any more) */
        public readonly array $toRetract,
    ) {}

    /** Whether a generated opening stands in the year. */
    public function hasOpening(): bool
    {
        return $this->existing !== [];
    }

    /** An opening stands and equals what would be written now — a re-run changes nothing. */
    public function isUpToDate(): bool
    {
        return $this->existing !== [] && $this->toPost === [] && $this->toAmend === [] && $this->toRetract === [];
    }

    /** How many standing entries a re-run leaves as they are. */
    public function unchangedCount(): int
    {
        return count($this->requests) - count($this->toPost) - count($this->toAmend);
    }
}
