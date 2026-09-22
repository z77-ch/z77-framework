<?php
namespace Z77\Module\Financial\Ui;

use Doctrine\DBAL\Exception\DeadlockException,
    Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException,
    Z77\Module\Financial\Entities\JournalEntry
;

/**
 * Recognises the two races a financial screen answers with a sentence
 * instead of a 500 (orchestrator decision 2026-09-22: no screen ever 500s on
 * a race). Both look for the DBAL exception anywhere in the `getPrevious()`
 * chain: a failure inside Doctrine's `flush()` can surface as another
 * exception with the real one further down (DOCTRINE-TX-005).
 *
 * Deliberately narrow and kept in module-financial: no framework API
 * without a second caller.
 *
 * @internal for the financial screens
 */
final class RaceFailure
{
    /**
     * Two openings of the FIRST fiscal year on an empty table deadlock on
     * the `lockAll()` gap locks (FIN-FY-003); the loser rolled back.
     */
    public static function isDeadlock(\Throwable $e): bool
    {
        return self::find($e, DeadlockException::class) !== null;
    }

    /**
     * A journal entry whose fiscal year was deleted between the ledger's
     * checks and the commit: the insert fails on the foreign key
     * `journal_entry.fiscal_year_id` — recognised by that constraint's name
     * only, never any foreign-key violation.
     */
    public static function isFiscalYearGone(\Throwable $e): bool
    {
        $fk = self::find($e, ForeignKeyConstraintViolationException::class);

        return $fk !== null && str_contains($fk->getMessage(), JournalEntry::FK_FISCAL_YEAR);
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return ?T
     */
    private static function find(\Throwable $e, string $class): ?\Throwable
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }
}
