<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\PostingRequest;

/**
 * What «Eröffnung erstellen» would write into a fiscal year now — computed
 * by {@see YearOpeningService::plan()} without a lock or a write; the
 * confirm dialog shows it, the fiscal-year list reads its status from it.
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
        /** The entry as it would be posted; null when the predecessor has no balance at all. */
        public readonly ?PostingRequest $request,
        /** The generated opening that stands in the year now, or null. */
        public readonly ?EntryRef $existing,
        /** An opening exists and equals what would be written now — a re-run changes nothing. */
        public readonly bool $upToDate,
    ) {}
}
