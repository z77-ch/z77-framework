<?php

namespace Z77\Persistence\Doctrine;

use Doctrine\ORM\EntityManager,
    Z77\Persistence\Doctrine\Repository\DoctrineRepository,
    Z77\Persistence\Interface\EntityManagerInterface,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Persistence\Interface\TransactionInterface,
    Z77\Persistence\Resolver\RepositoryConvention,
    Z77\Shared\Attributes\Entity as EntityAttr
;

/**
 * The Doctrine driver behind `UnifiedEntityManager` — the kernel's
 * EntityManagerInterface over Doctrine's EntityManager. Consumers never see
 * the Doctrine object (ADR-039 decision 6); what they get back from
 * `getRepository()` is a `DoctrineRepository` or the entity's convention
 * repository extending it, and from `getTransaction()` the driver's port.
 *
 * Where this driver behaves differently from the File driver, and consumer
 * code MUST NOT depend on either side (decision 9):
 *   - `flush()` writes every MANAGED entity that changed, not only what was
 *     `persist()`ed;
 *   - `remove()` deletes at the next `flush()`, not at once;
 *   - the same row is the same object within a request (Identity Map);
 *   - `reorder()` is File-only and refused here.
 *
 * The Doctrine EntityManager is held through `EntityManagerHolder` because a
 * rollback replaces it (decision 10): everything here asks the holder at
 * every use, and a repository handed out before the rollback keeps working.
 */
class DoctrineEntityManager implements EntityManagerInterface
{
    /** @var array<class-string, RepositoryInterface> */
    private array $repositories = [];

    /** @var array<class-string, true> the mapped entities, for the guard below */
    private array $announced;

    private EntityManagerHolder $holder;

    /**
     * The announced entities are read from the metadata driver — the same
     * explicit list `EntityManagerFactory` built it from (the modules'
     * `doctrineEntities` plus the package's own), never a second copy.
     */
    public function __construct(EntityManager $em)
    {
        $this->holder    = new EntityManagerHolder($em);
        $this->announced = array_fill_keys(
            $em->getConfiguration()->getMetadataDriverImpl()->getAllClassNames(),
            true
        );
    }

    public function getRepository(string $entityClass, EntityAttr $attr): RepositoryInterface
    {
        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }
        $this->requireAnnounced($entityClass);

        $repoClass = RepositoryConvention::specificRepository($entityClass) ?? DoctrineRepository::class;

        return $this->repositories[$entityClass] = new $repoClass($entityClass, $this->holder);
    }

    public function persist(object $entity, EntityAttr $attr): void
    {
        $this->requireAnnounced($entity::class);
        $this->holder->current()->persist($entity);
    }

    /**
     * One `flush()` is one Doctrine transaction (a savepoint inside an open
     * unit of work). When it fails, Doctrine has rolled back and closed the
     * EntityManager; the port decides what follows — see
     * `DoctrineTransaction::onFailedFlush()`.
     */
    public function flush(): void
    {
        try {
            $this->holder->current()->flush();
        } catch (\Throwable $e) {
            $this->holder->transaction()->onFailedFlush();
            throw $e;
        }
    }

    public function remove(object $entity, EntityAttr $attr): void
    {
        $this->requireAnnounced($entity::class);
        $this->holder->current()->remove($entity);
    }

    /** The transaction port (ADR-039 decision 10), one per driver. */
    public function getTransaction(): TransactionInterface
    {
        return $this->holder->transaction();
    }

    /**
     * ADR-039 decision 5: an entity is a Doctrine entity because its module
     * says so. Doctrine's attribute driver would happily map any class with
     * `#[ORM\Entity]` on the spot, so a class missing from `doctrineEntities`
     * would work at runtime and be missing from the migrations — the schema
     * drift nobody notices until production. Refuse it here, at every entry.
     */
    private function requireAnnounced(string $entityClass): void
    {
        if (!isset($this->announced[$entityClass])) {
            throw new \RuntimeException(
                "{$entityClass} is not announced as a Doctrine entity — list it under 'doctrineEntities' "
                . "in its module config (ADR-039 decision 5); the metadata and the migrations work on that list only."
            );
        }
    }

    public function reorder(array $entities, EntityAttr $attr): void
    {
        throw new \LogicException(
            'reorder() is File-only — it rewrites a JSON collection in the given order (ADR-039 decision 9). '
            . 'A Doctrine entity carries its order in a mapped column; persist it like any other change.'
        );
    }
}
