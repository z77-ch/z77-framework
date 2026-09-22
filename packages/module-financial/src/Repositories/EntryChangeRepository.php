<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\ChangeAction;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see EntryChange}. Plain criteria reads —
 * the rows reference the entry by plain columns (no association), so
 * `findBy()` on `entryId` / `fiscalYearId` is all it takes; the order is
 * put on in PHP (a change log is a handful of rows).
 */
class EntryChangeRepository extends DoctrineRepository
{
    /**
     * The change log of one entry, oldest first.
     *
     * @return list<EntryChange>
     */
    public function forEntry(int $entryId): array
    {
        $rows = $this->findBy(['entryId' => $entryId]);
        usort($rows, static fn(EntryChange $a, EntryChange $b) => $a->getId() <=> $b->getId());

        return array_values($rows);
    }

    /**
     * The deletions of a fiscal year — every number that is a GAP in the
     * journal, with who deleted it and when (ADR-042 decision 9). Highest
     * number first, like the journal screen.
     *
     * @return list<EntryChange>
     */
    public function deletionsForYear(FiscalYear $year): array
    {
        $rows = $this->findBy(['fiscalYearId' => (int) $year->getId(), 'action' => ChangeAction::Delete->value]);
        usort($rows, static fn(EntryChange $a, EntryChange $b) => $b->getEntryNumber() <=> $a->getEntryNumber());

        return array_values($rows);
    }
}
