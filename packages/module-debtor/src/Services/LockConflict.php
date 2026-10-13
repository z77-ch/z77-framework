<?php

namespace Z77\Module\Debtor\Services;

use Doctrine\DBAL\Exception\RetryableException;

/**
 * Tells a lock conflict of the database (deadlock 1213, lock wait timeout
 * 1205) apart from any other failure, so a screen answers it with a German
 * «try again» instead of a 500 (review 2026-10-13 H4).
 *
 * The lock order of the module (debtor.md «Lock order») keeps two of its
 * own writes from deadlocking each other; a conflict can still come from a
 * lock wait that runs out or from a writer outside the module. Nothing was
 * written then — the unit of work rolled back whole — so repeating the
 * action is safe. There is no automatic retry (ADR-039 decision 10).
 *
 * The exception is searched along `getPrevious()`: a deadlock inside
 * Doctrine's `flush()` surfaces as DBAL's «SAVEPOINT … does not exist» with
 * the `DeadlockException` further down the chain (DOCTRINE-TX-005).
 */
final class LockConflict
{
    public const MESSAGE = 'Jemand anderes hat gleichzeitig an denselben Belegen gearbeitet — nichts gespeichert. Bitte die Seite neu laden und nochmals versuchen.';

    public static function is(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryableException) {
                return true;
            }
        }

        return false;
    }
}
