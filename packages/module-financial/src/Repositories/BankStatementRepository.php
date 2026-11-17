<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\BankStatement;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankStatement} — the dedup lookups of the import and the two
 * ends of an account's chain. Doctrine-only (ADR-039 decision 8).
 */
class BankStatementRepository extends DoctrineRepository
{
    /** A statement read from the file with this SHA-256 (the oldest), or null. */
    public function findBySha256(string $sha256): ?BankStatement
    {
        return $this->em()->getRepository(BankStatement::class)->findOneBy(['fileSha256' => $sha256], ['id' => 'ASC']);
    }

    /** The statement of this bank account under the bank's message and statement id, or null. */
    public function findByMessage(int $bankAccountId, string $messageId, string $statementId): ?BankStatement
    {
        return $this->findOneBy(['bankAccount' => $bankAccountId, 'messageId' => $messageId, 'statementId' => $statementId]);
    }

    /**
     * The NEWEST statement of the bank account (the last one stored), or null — its `to_date` and
     * `closing_balance` are what the account is «eingelesen bis» (ADR-060 decision 18: statements
     * are only ever appended to the chain, so the newest is the end).
     */
    public function latestOf(int $bankAccountId): ?BankStatement
    {
        return $this->em()->getRepository(BankStatement::class)->findOneBy(['bankAccount' => $bankAccountId], ['id' => 'DESC']);
    }

    /** The FIRST statement of the bank account, or null — its `from_date` is where the chain starts. */
    public function firstOf(int $bankAccountId): ?BankStatement
    {
        return $this->em()->getRepository(BankStatement::class)->findOneBy(['bankAccount' => $bankAccountId], ['id' => 'ASC']);
    }

    /** @return list<BankStatement> the statements of the bank account, the newest first */
    public function ofAccount(int $bankAccountId): array
    {
        return array_values($this->em()->getRepository(BankStatement::class)->findBy(['bankAccount' => $bankAccountId], ['id' => 'DESC']));
    }
}
