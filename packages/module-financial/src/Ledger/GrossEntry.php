<?php

namespace Z77\Module\Financial\Ledger;

/**
 * What {@see \Z77\Module\Financial\Services\GrossEntryBuilder::build()} answers: the balanced
 * posting lines (hand them to `PostingRequest::generated()` / `::manual()`) and, per part in the
 * order given, its VAT split — null for a part «ohne MWST».
 */
final class GrossEntry
{
    /**
     * @param list<PostingLine>     $lines
     * @param list<GrossSplit|null> $splits
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $splits,
    ) {}
}
