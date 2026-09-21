<?php

namespace Z77\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Connection,
    Doctrine\ORM\EntityManager,
    Z77\Persistence\Doctrine\EntityManagerHolder,
    Z77\Persistence\Interface\RepositoryInterface
;

/**
 * Read repository for Doctrine-backed entities — the counterpart of
 * `FileRepository`. Delegates the four reads of `RepositoryInterface` to
 * Doctrine; writes go through `UnifiedEntityManager::persist()` / `flush()` /
 * `remove()` like everywhere else.
 *
 * An entity-specific repository (`{Module}\Repositories\{Entity}Repository`)
 * extends this class, as file repositories extend `FileRepository`. Report
 * methods — balance sheet, aged receivables, anything that is SQL rather than
 * a criteria lookup — run on the DBAL connection of the SAME EntityManager,
 * reachable only through {@see connection()} (ADR-039 decision 8): they see
 * the uncommitted writes of the current unit of work and use the one set of
 * credentials. Such a method is driver-specific by design and sits OUTSIDE
 * `RepositoryInterface`; document it as the deviation it is.
 *
 * The EntityManager is asked from the holder at every call, never kept: a
 * rollback replaces it (decision 10), and a repository a service obtained
 * before the rollback must keep reading afterwards.
 */
class DoctrineRepository implements RepositoryInterface
{
    public function __construct(
        protected string $class,
        private EntityManagerHolder $holder
    ) {}

    public function find(int|string $id): ?object
    {
        return $this->em()->find($this->class, $id);
    }

    public function findAll(): array
    {
        return $this->em()->getRepository($this->class)->findAll();
    }

    /** @param array<string, mixed> $criteria field name → value, as on the File driver */
    public function findBy(array $criteria): array
    {
        return $this->em()->getRepository($this->class)->findBy($criteria);
    }

    public function findOneBy(array $criteria): ?object
    {
        return $this->em()->getRepository($this->class)->findOneBy($criteria);
    }

    /**
     * The DBAL connection for report SQL in a subclass — never handed further
     * up: a service or controller that needs a report calls the repository
     * method that runs it.
     *
     * Known limit: a statement that fails here inside an open unit of work
     * and whose exception the CALLER swallows leaves the unit of work
     * committable (DOCTRINE-TX-006) — a report is a read, and the rule is to
     * let exceptions propagate. A subclass that WRITES on this connection
     * calls {@see markTransactionRollbackOnly()} in its catch before
     * rethrowing, as `NumberRangeRepository::next()` does.
     */
    protected function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    /**
     * After a failed statement on {@see connection()}: whatever the caller
     * does with the exception, the open unit of work (if any) ends in a
     * rollback (ADR-039 decision 10). Nothing happens outside one.
     */
    protected function markTransactionRollbackOnly(): void
    {
        $this->holder->transaction()->markRollbackOnly();
    }

    /**
     * Doctrine's EntityManager for a DQL query in a subclass — the second
     * driver-specific seam next to {@see connection()}, for a read that
     * must HYDRATE entities SQL cannot deliver: a fetch-join that loads a
     * list with its associations in one query instead of a lazy proxy per
     * row (the N+1 of a list screen). Reads only, marked Doctrine-only in
     * the method's docblock, never handed further up (ADR-039 decision 6):
     * a service or controller still sees the unified API only.
     */
    protected function em(): EntityManager
    {
        return $this->holder->current();
    }
}
