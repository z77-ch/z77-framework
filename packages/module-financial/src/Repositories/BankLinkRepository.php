<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankLink} — the reads of `BankLinkService` (by owner key, by
 * movement) and the lock of the movement row a link is set under. Doctrine-only (ADR-039
 * decision 8).
 */
class BankLinkRepository extends DoctrineRepository
{
    /** Keys per `IN (…)` — far below the driver's placeholder limit. */
    private const CHUNK = 500;

    public function findByOwner(string $ownerKey): ?BankLink
    {
        return $this->findOneBy(['ownerKey' => $ownerKey]);
    }

    /**
     * @param list<string> $ownerKeys
     * @return array<string, BankLink> owner key → link, only the linked ones
     */
    public function findByOwners(array $ownerKeys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ownerKeys)), self::CHUNK) as $chunk) {
            foreach ($this->findBy(['ownerKey' => $chunk]) as $link) {
                $out[$link->getOwnerKey()] = $link;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $movementIds
     * @return array<int, BankLink> movement id → its link, only the linked ones
     */
    public function findByMovements(array $movementIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $movementIds))), self::CHUNK) as $chunk) {
            foreach ($this->findBy(['movement' => $chunk]) as $link) {
                $out[(int) $link->getMovement()->getId()] = $link;
            }
        }

        return $out;
    }

    /**
     * Locks the movement row EXCLUSIVELY (`SELECT … FOR UPDATE`) until the caller commits and
     * answers the owner keys linked to it — read AFTER the lock, on the connection of the open
     * unit of work, so two owners taking the same movement serialise and the second sees the
     * first. Null when the movement is gone. Doctrine-only (SQL).
     *
     * @return list<string>|null
     * @throws \LogicException outside an open unit of work
     */
    public function lockMovement(int $movementId): ?array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockMovement() needs an open unit of work — the row lock holds until commit');
        }
        if ($this->connection()->fetchOne('SELECT id FROM bank_movement WHERE id = ? FOR UPDATE', [$movementId], [ParameterType::INTEGER]) === false) {
            return null;
        }

        return array_map('strval', $this->connection()->fetchFirstColumn('SELECT owner_key FROM bank_link WHERE movement_id = ?', [$movementId], [ParameterType::INTEGER]));
    }

    /** Whether $ownerKey holds a link — asked on the connection of the open unit of work. Doctrine-only (SQL). */
    public function ownerLinked(string $ownerKey): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM bank_link WHERE owner_key = ?', [$ownerKey]) !== false;
    }

    /**
     * Which of $ownerKeys hold a link — one read for a list. Doctrine-only (SQL).
     *
     * @param list<string> $ownerKeys
     * @return array<string, true>
     */
    public function linkedOwners(array $ownerKeys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ownerKeys)), self::CHUNK) as $chunk) {
            foreach ($this->connection()->fetchFirstColumn('SELECT owner_key FROM bank_link WHERE owner_key IN (?)', [$chunk], [ArrayParameterType::STRING]) as $key) {
                $out[(string) $key] = true;
            }
        }

        return $out;
    }

    /** Removes the link(s) of $ownerKey and answers how many — inside the owner's unit of work. Doctrine-only (SQL). */
    public function deleteByOwner(string $ownerKey): int
    {
        return (int) $this->connection()->executeStatement('DELETE FROM bank_link WHERE owner_key = ?', [$ownerKey]);
    }
}
