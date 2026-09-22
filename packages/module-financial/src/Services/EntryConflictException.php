<?php

namespace Z77\Module\Financial\Services;

/**
 * A manual edit or delete named a version of the entry that is no longer
 * current — somebody else changed or deleted it in between (review
 * 2026-09-22, H1/M1–M3: two parallel edits doubled the lines, a stale delete
 * logged twice, a stale edit after a delete ended in a raw FK error). Raised
 * by `ManualEntryService` from the version check on the fresh re-read inside
 * the unit of work, or mapped from Doctrine's `OptimisticLockException` at
 * flush when the other writer committed between the re-read and the commit.
 * The screen says «Die Buchung wurde inzwischen geändert oder gelöscht —
 * bitte neu laden» and reloads.
 */
final class EntryConflictException extends FinancialException
{
    public function __construct(int $entryId, int $expectedVersion, ?int $currentVersion)
    {
        parent::__construct(
            $currentVersion === null
                ? "Journal entry #{$entryId} no longer exists (expected version {$expectedVersion}) — it was deleted in the meantime"
                : "Journal entry #{$entryId} is at version {$currentVersion}, the edit expected {$expectedVersion} — it was changed in the meantime; reload and redo"
        );
    }
}
