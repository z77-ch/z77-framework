<?php

namespace Z77\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManagerInterface,
    Z77\Persistence\Doctrine\Repository\DoctrineRepository,
    Z77\Persistence\Interface\EntityManagerInterface,
    Z77\Persistence\Interface\RepositoryInterface,
    Z77\Persistence\Resolver\RepositoryConvention,
    Z77\Shared\Attributes\Entity as EntityAttr
;

/**
 * The Doctrine driver behind `UnifiedEntityManager` — the kernel's
 * EntityManagerInterface over Doctrine's EntityManager. Consumers never see
 * the Doctrine object (ADR-039 decision 6); what they get back from
 * `getRepository()` is a `DoctrineRepository` or the entity's convention
 * repository extending it.
 *
 * Where this driver behaves differently from the File driver, and consumer
 * code MUST NOT depend on either side (decision 9):
 *   - `flush()` writes every MANAGED entity that changed, not only what was
 *     `persist()`ed;
 *   - `remove()` deletes at the next `flush()`, not at once;
 *   - the same row is the same object within a request (Identity Map);
 *   - `reorder()` is File-only and refused here.
 */
class DoctrineEntityManager implements EntityManagerInterface
{
    /** @var array<class-string, RepositoryInterface> */
    private array $repositories = [];

    /** @var array<class-string, true> the announced entities, for the guard below */
    private array $announced;

    /** @param list<class-string> $entityClasses the modules' `doctrineEntities` */
    public function __construct(
        private DoctrineEntityManagerInterface $em,
        array $entityClasses
    ) {
        $this->announced = array_fill_keys($entityClasses, true);
    }

    public function getRepository(string $entityClass, EntityAttr $attr): RepositoryInterface
    {
        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }
        $this->requireAnnounced($entityClass);

        $repoClass = RepositoryConvention::specificRepository($entityClass) ?? DoctrineRepository::class;

        return $this->repositories[$entityClass] = new $repoClass($entityClass, $this->em);
    }

    public function persist(object $entity, EntityAttr $attr): void
    {
        $this->requireAnnounced($entity::class);
        $this->em->persist($entity);
    }

    /** One `flush()` is one Doctrine transaction. */
    public function flush(): void
    {
        $this->em->flush();
    }

    public function remove(object $entity, EntityAttr $attr): void
    {
        $this->requireAnnounced($entity::class);
        $this->em->remove($entity);
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
