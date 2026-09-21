<?php

namespace Z77\Persistence\Doctrine;

use Doctrine\ORM\EntityManager,
    Z77\Persistence\Doctrine\Transaction\DoctrineTransaction
;

/**
 * The one place that knows WHICH Doctrine EntityManager is current, and the
 * transaction port that goes with it.
 *
 * Doctrine closes its EntityManager on a failed flush, and a closed one refuses
 * every further operation. ADR-039 decision 10 wants the request to go on
 * reading after a rollback (error page, flash message), so the driver replaces
 * the EntityManager — and everything that holds one (the driver, its
 * repositories, the transaction port) holds THIS object instead and asks it
 * for the current one at every use.
 *
 * A replacement is a new EntityManager over the SAME DBAL connection and the
 * same Configuration (metadata cache included): no second connection, no
 * re-read of the credentials. Its Identity Map is empty — entities loaded
 * before are detached and MUST NOT be written again.
 *
 * The port is created here so that a repository, which holds the holder,
 * can mark the open unit of work rollback-only when one of its statements
 * failed (`DoctrineRepository::markTransactionRollbackOnly()`).
 *
 * @internal to the package — never handed to a consumer (decision 6)
 */
final class EntityManagerHolder
{
    private DoctrineTransaction $transaction;

    public function __construct(private EntityManager $em)
    {
        $this->transaction = new DoctrineTransaction($this);
    }

    public function current(): EntityManager
    {
        return $this->em;
    }

    public function transaction(): DoctrineTransaction
    {
        return $this->transaction;
    }

    /** Replaces the EntityManager after a rollback, whether Doctrine closed it or not. */
    public function replace(): void
    {
        $old = $this->em;
        if ($old->isOpen()) {
            $old->clear();
        }
        $this->em = new EntityManager($old->getConnection(), $old->getConfiguration(), $old->getEventManager());
    }
}
