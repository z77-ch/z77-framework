<?php

namespace Z77\Persistence\Doctrine\Mapping;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Makes every write of a `#[ORM\Version]`-ed entity reach the row — and with it the version
 * bump and the version check of Doctrine's optimistic lock (DOCTRINE-VERSION-001).
 *
 * Why: since doctrine/orm 3.8 the change set compares OBJECT values by their database value.
 * A `changed_at` re-stamped within the same second (DATETIME has second precision), an equal
 * `Money`, a date with only another time — none of them is a change any more. A write whose
 * only header change was such a stamp (only the lines / child rows changed, the same actor
 * saved again) then gets NO `UPDATE`: the version stays, the conflict check of the next writer
 * misses it, and Doctrine's `OptimisticLockException` at flush is never raised for it.
 *
 * The counter is a SCALAR that changes on every counted write, so the change set never comes
 * out empty — deterministic, independent of the clock and of how the ORM compares objects.
 * The entity calls {@see countWrite()} where it stamps `changed_by` / `changed_at`; Doctrine
 * keeps owning the version (`SET version = version + 1 … WHERE version = ?`). The column is
 * technical: nothing reads it. Needs `write_count INT DEFAULT 0 NOT NULL` in the table's
 * migration.
 */
trait WriteCounter
{
    /** Protected, not private: a mapped superclass (`AbstractProduct`) hands it to its entity. */
    #[ORM\Column(name: 'write_count', type: Types::INTEGER, options: ['default' => 0])]
    protected int $writeCount = 0;

    /** One more write of this row — call it wherever the entity stamps who changed it and when. */
    private function countWrite(): void
    {
        $this->writeCount++;
    }
}
