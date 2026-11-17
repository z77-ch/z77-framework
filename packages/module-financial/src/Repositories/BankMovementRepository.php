<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankMovement} — the dedup read of the import. Doctrine-only
 * (ADR-039 decision 8).
 */
class BankMovementRepository extends DoctrineRepository
{
    /** Keys per `IN (…)` — far below the driver's placeholder limit. */
    private const CHUNK = 500;

    /**
     * Which of $dedupKeys the bank account already holds — read on the connection of the open
     * unit of work, after the account's row lock, so it sees every committed import. Doctrine-only
     * (SQL).
     *
     * @param list<string> $dedupKeys
     * @return array<string, true> the known keys
     */
    public function knownKeys(int $bankAccountId, array $dedupKeys): array
    {
        $known = [];
        foreach (array_chunk($dedupKeys, self::CHUNK) as $chunk) {
            $rows = $this->connection()->fetchFirstColumn(
                'SELECT dedup_key FROM bank_movement WHERE bank_account_id = ? AND dedup_key IN (?)',
                [$bankAccountId, $chunk],
                [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::STRING]
            );
            foreach ($rows as $key) {
                $known[(string) $key] = true;
            }
        }

        return $known;
    }
}
