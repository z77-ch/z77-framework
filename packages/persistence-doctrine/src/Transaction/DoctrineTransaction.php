<?php

namespace Z77\Persistence\Doctrine\Transaction;

use Doctrine\DBAL\Connection,
    Z77\Persistence\Doctrine\EntityManagerHolder,
    Z77\Persistence\Exception\TransactionRolledBackException,
    Z77\Persistence\Interface\TransactionInterface
;

/**
 * The Doctrine driver's transaction port (ADR-039 decision 10), one instance
 * per driver.
 *
 * What it does, and deliberately nothing more:
 *
 *   - `run()` opens ONE database transaction at depth 0, flushes the
 *     EntityManager and commits when the outermost unit of work returns, and
 *     rolls back and rethrows on any exception. The flush before the commit
 *     writes EVERYTHING the EntityManager manages — also an entity loaded and
 *     changed before `run()` (ARCH-A004); a File entity persisted inside
 *     still needs an explicit `UnifiedEntityManager::flush()`;
 *   - a nested `run()` JOINS: no BEGIN, no savepoint, no inner commit. The
 *     port counts its own depth instead of using DBAL's nesting — DBAL 4.4
 *     nests with savepoints (`SAVEPOINT DOCTRINE_n`), and an inner rollback
 *     there would roll back to the savepoint only, so the outer could still
 *     commit half a unit of work. That is exactly what decision 10 forbids;
 *   - an exception in a nested unit of work marks the whole ROLLBACK-ONLY,
 *     even when the outer code catches it. The outermost `run()` then rolls
 *     back and throws `TransactionRolledBackException` instead of committing.
 *     A failed `flush()` or a failed statement in `NumberRangeRepository`
 *     marks it the same way (`markRollbackOnly()`), swallowed or not;
 *   - after every rollback the EntityManager is replaced, so the request can
 *     still read (error page, flash message); entities loaded before are
 *     detached;
 *   - `isOpen()` answers from the port's own depth — true from the first
 *     statement to the end of the outermost commit — not from the savepoint
 *     Doctrine's own `flush()` briefly holds;
 *   - `run()` at depth 0 refuses to start when the connection already has a
 *     transaction the port does not know about: it would silently nest into
 *     a savepoint and «commit» with RELEASE SAVEPOINT.
 *
 * What a DEADLOCK looks like from here (verified against MariaDB 10.6):
 * InnoDB rolls back the whole server transaction and discards every
 * savepoint. Inside `NumberRangeRepository::next()` the `DeadlockException`
 * surfaces as is. Inside `flush()` Doctrine's UnitOfWork first tries to roll
 * back to ITS savepoint, which no longer exists, so what surfaces is DBAL's
 * exception for that (1305, «SAVEPOINT … does not exist») with the
 * `DeadlockException` further DOWN its `getPrevious()` chain (PHP appends the
 * exception a `finally` block replaced). In both cases DBAL's nesting level
 * may be left above zero while the server has nothing open; `rollBack()`
 * below detects that and CLOSES the connection — DBAL reconnects lazily on
 * the next statement, with the same parameters and init command — and the
 * ORIGINAL exception is rethrown. The failed rollback itself is not
 * surfaced: it is a consequence of the original failure, closing the
 * connection is its complete remedy, and PHP cannot attach a `previous` to
 * an exception that already exists without wrapping it in another type.
 *
 * No retry, no sleep, no persister class. A deadlock is an error the caller
 * sees; the fix is lock order (`NumberRange` first), not a loop.
 */
final class DoctrineTransaction implements TransactionInterface
{
    private int $depth = 0;
    private bool $rollbackOnly = false;

    public function __construct(private EntityManagerHolder $holder) {}

    public function run(callable $unitOfWork): mixed
    {
        $connection = $this->connection();
        if ($this->depth === 0) {
            if ($connection->isTransactionActive()) {
                throw new \LogicException(
                    'A transaction is already open on the connection that the port did not start '
                    . '(DBAL nesting level ' . $connection->getTransactionNestingLevel() . '). Nothing but the port '
                    . 'may call beginTransaction() on the driver\'s connection (ADR-039 decision 10).'
                );
            }
            $connection->beginTransaction();
        }
        ++$this->depth;

        try {
            $result = $unitOfWork();

            if ($this->depth === 1) {
                if ($this->rollbackOnly) {
                    throw new TransactionRolledBackException(
                        'The transaction was rolled back: a nested unit of work, a flush() or a NumberRange draw failed '
                        . 'and its exception was caught by the outer code. An exception anywhere rolls back the whole '
                        . '(ADR-039 decision 10) — let it propagate, or run the fallible part outside the transaction.'
                    );
                }
                $this->holder->current()->flush();
                $connection->commit();
            }
        } catch (\Throwable $e) {
            $this->rollbackOnly = true;
            if ($this->depth === 1) {
                $this->depth = 0;
                $this->rollBack($connection);
            } else {
                --$this->depth;
            }
            throw $e;
        }

        --$this->depth;

        return $result;
    }

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Marks the open unit of work rollback-only: whatever the outer code does
     * from here on, the outermost `run()` rolls back. Called by the driver's
     * `flush()` and by `NumberRangeRepository::next()` when a statement
     * failed, BEFORE they rethrow — so a swallowed exception cannot end in a
     * half-committed unit of work. A no-op outside a unit of work.
     *
     * @internal package use only
     */
    public function markRollbackOnly(): void
    {
        if ($this->depth > 0) {
            $this->rollbackOnly = true;
        }
    }

    /**
     * Called by the driver when `flush()` threw OUTSIDE a unit of work:
     * Doctrine has rolled back its own transaction and CLOSED the
     * EntityManager, so it is replaced at once and the request can go on
     * reading. Inside a unit of work `markRollbackOnly()` is what applies.
     *
     * @internal package use only
     */
    public function onFailedFlush(): void
    {
        if ($this->depth > 0) {
            $this->rollbackOnly = true;
            return;
        }
        $this->holder->replace();
    }

    /**
     * Rolls back what the server still has, then makes sure DBAL and the
     * server agree that nothing is open. When they do not — the rollback
     * threw (savepoint gone after a server-side rollback), or DBAL's level is
     * still above zero — the connection is closed: DBAL resets its level, the
     * server discards the session, the next statement reconnects. Never
     * throws; the caller rethrows the original exception.
     */
    private function rollBack(Connection $connection): void
    {
        $this->rollbackOnly = false;
        try {
            try {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
                $connection->close();
            }
            if ($connection->getTransactionNestingLevel() > 0) {
                $connection->close();
            }
        } finally {
            $this->holder->replace();
        }
    }

    private function connection(): Connection
    {
        return $this->holder->current()->getConnection();
    }
}
