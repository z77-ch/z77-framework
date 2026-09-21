<?php

namespace Z77\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Connection,
    Doctrine\ORM\EntityManagerInterface,
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
 */
class DoctrineRepository implements RepositoryInterface
{
    public function __construct(
        protected string $class,
        private EntityManagerInterface $em
    ) {}

    public function find(int|string $id): ?object
    {
        return $this->em->find($this->class, $id);
    }

    public function findAll(): array
    {
        return $this->em->getRepository($this->class)->findAll();
    }

    /** @param array<string, mixed> $criteria field name → value, as on the File driver */
    public function findBy(array $criteria): array
    {
        return $this->em->getRepository($this->class)->findBy($criteria);
    }

    public function findOneBy(array $criteria): ?object
    {
        return $this->em->getRepository($this->class)->findOneBy($criteria);
    }

    /**
     * The DBAL connection for report SQL in a subclass — never handed further
     * up: a service or controller that needs a report calls the repository
     * method that runs it.
     */
    protected function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
