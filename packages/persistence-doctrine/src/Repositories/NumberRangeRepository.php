<?php

namespace Z77\Persistence\Doctrine\Repositories;

use Z77\Persistence\Doctrine\Entities\NumberRange,
    Z77\Persistence\Doctrine\Repository\DoctrineRepository
;

/**
 * Gapless numbering under a row lock (ADR-039 decisions 10 and 15) — the
 * convention repository of `NumberRange`, obtained like any other:
 *
 *   $uem->getTransaction(Invoice::class)->run(function () use ($uem) {
 *       $number = $uem->getRepository(NumberRange::class)->next('invoice');   // FIRST
 *       …
 *   });
 *
 * `next()` is a driver-specific method outside `RepositoryInterface` — SQL on
 * the driver's own connection, the documented deviation of decision 8:
 *
 *   1. `INSERT … ON DUPLICATE KEY UPDATE` creates the row on the first draw
 *      and takes an EXCLUSIVE lock on an existing one. Insert-first, because a
 *      `SELECT … FOR UPDATE` that finds no row leaves a gap lock, and two
 *      transactions that then both insert deadlock; and ON DUPLICATE KEY
 *      UPDATE rather than INSERT IGNORE, because the latter takes only a
 *      SHARED lock on the duplicate — two holders that both want the
 *      exclusive lock next would deadlock as well.
 *   2. `SELECT … FOR UPDATE` reads the last number under the lock — a locking
 *      read sees the latest COMMITTED value, not the snapshot.
 *   3. `UPDATE` writes the next one.
 *
 * The lock lives until the caller's transaction ends, which is why the
 * method refuses to run outside one: released early, the number would be
 * handed out twice. A rolled-back unit of work releases the lock with the
 * old value — the number was never consumed, the next caller gets it. So the
 * sequence is gapless under concurrency AND on rollback, at the price of
 * serialising every writer of one range for the length of its transaction:
 * draw the number FIRST and keep the unit of work short (decision 10, lock
 * order — `NumberRange` is always locked first, so two use cases never hold
 * their locks in opposite order; several ranges in one unit of work are
 * drawn in a fixed order, sorted by name).
 *
 * One case is NOT deadlock-free, verified against MariaDB 10.6 (DOCTRINE-NR-003):
 * the very first draw of a NEW range under contention. While the creating
 * transaction holds its fresh row, waiters queue in the INSERT's duplicate
 * check; if the creator then ROLLS BACK, the row vanishes and the waiters
 * race to insert it — all but one get a deadlock (1213). Gaplessness holds
 * (nothing was consumed), the losers see the driver's exception and the
 * unit of work is marked rollback-only. An existing row never deadlocks
 * here. Hence a range is CREATED ahead of concurrent use with `create()` —
 * for the ledger at the opening of a fiscal year (P2, module-financial) —
 * which inserts the row without consuming a number.
 *
 * Any failure of one of the statements — a deadlock, a lock wait timeout —
 * marks the open unit of work rollback-only before the exception leaves:
 * even a caller that swallows it cannot commit half a unit of work.
 *
 * The number is a bare integer starting at 1. Prefixes, year segments or
 * zero padding belong to the DOCUMENT (a column there, if stored at all),
 * never to `number_range`.
 */
class NumberRangeRepository extends DoctrineRepository
{
    /**
     * The next number of the range, consumed only if the caller commits.
     *
     * Doctrine-only (ADR-039 decision 8): SQL on the driver's connection,
     * inside the caller's open transaction.
     *
     * @throws \InvalidArgumentException for an empty, padded or over-long name
     * @throws \LogicException           outside an open transaction
     */
    public function next(string $range): int
    {
        $this->assertName($range);

        $connection = $this->connection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException(
                "NumberRange '{$range}': a number can only be drawn inside an open transaction — the row lock "
                . 'holds until commit and is what keeps the sequence gapless. Run the unit of work through '
                . 'UnifiedEntityManager::getTransaction() (ADR-039 decision 10) and draw the number first.'
            );
        }

        $table = NumberRange::TABLE;
        try {
            $connection->executeStatement(
                "INSERT INTO {$table} (name, last_number) VALUES (?, 0) ON DUPLICATE KEY UPDATE last_number = last_number",
                [$range]
            );
            $last = $connection->fetchOne("SELECT last_number FROM {$table} WHERE name = ? FOR UPDATE", [$range]);
            if ($last === false) {
                throw new \RuntimeException("NumberRange '{$range}': row vanished between insert and lock.");
            }

            $next = (int)$last + 1;
            $connection->executeStatement("UPDATE {$table} SET last_number = ? WHERE name = ?", [$next, $range]);
        } catch (\Throwable $e) {
            $this->markTransactionRollbackOnly();
            throw $e;
        }

        return $next;
    }

    /**
     * Creates the range if it does not exist yet — idempotent, consumes no
     * number (the first `next()` still returns 1). The explicit creation step
     * against DOCTRINE-NR-003: a range created and COMMITTED before anyone
     * draws from it never takes the new-row path under contention. Called by
     * the module that owns the range when the range becomes valid (the ledger:
     * at the opening of a fiscal year), not before every draw.
     *
     * Works inside or outside an open transaction. Outside, the row is
     * committed at once; inside, it is committed (or rolled back) with the
     * caller's unit of work — and until then an existing row stays locked,
     * exactly as after `next()`. `ON DUPLICATE KEY UPDATE` rather than
     * `INSERT IGNORE` for the reason given in the class docblock: an exclusive
     * lock, so a later `next()` in the same unit of work does not have to
     * upgrade a shared one.
     *
     * Answers whether the row was NEW: `true` = inserted at 0, `false` = the
     * range already existed (left untouched, its `last_number` unchanged). A
     * caller for whom an existing range means a conflict — the ledger opening
     * a fiscal year whose range is left over — refuses on `false` instead of
     * silently continuing an old sequence. Read from the affected rows of the
     * statement: MariaDB reports 1 for an insert and 0 for a duplicate whose
     * update changed nothing (the connection does not set `FOUND_ROWS`).
     *
     * Doctrine-only (ADR-039 decision 8): SQL on the driver's connection.
     *
     * @return bool true = the range was created now, false = it existed already
     * @throws \InvalidArgumentException for an empty, padded or over-long name
     */
    public function create(string $range): bool
    {
        $this->assertName($range);

        $table = NumberRange::TABLE;
        try {
            $affected = $this->connection()->executeStatement(
                "INSERT INTO {$table} (name, last_number) VALUES (?, 0) ON DUPLICATE KEY UPDATE last_number = last_number",
                [$range]
            );
        } catch (\Throwable $e) {
            $this->markTransactionRollbackOnly();
            throw $e;
        }

        return (int) $affected === 1;
    }

    private function assertName(string $range): void
    {
        if ($range === '' || $range !== trim($range) || strlen($range) > NumberRange::NAME_LENGTH) {
            throw new \InvalidArgumentException(
                "Number range name must be 1-" . NumberRange::NAME_LENGTH . " bytes without surrounding whitespace, got "
                . var_export($range, true) . '.'
            );
        }
    }
}
