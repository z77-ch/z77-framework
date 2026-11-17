<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;
use Z77\Shared\Payment\Iban;

/**
 * Convention repository for {@see BankAccount} — the lookups of the master data «Bankkonten», of
 * the statement import (by IBAN) and of a payment method (by code). Doctrine-only (ADR-039
 * decision 8).
 */
class BankAccountRepository extends DoctrineRepository
{
    public function findByCode(string $code): ?BankAccount
    {
        $code = BankAccount::normalizeCode($code);

        return $code === '' ? null : $this->findOneBy(['code' => $code]);
    }

    /** The bank account with this IBAN (compared normalised), or null — `uniq_bank_account_iban` makes it one row. */
    public function findByIban(string $iban): ?BankAccount
    {
        $iban = Iban::normalize($iban);

        return $iban === '' ? null : $this->findOneBy(['iban' => $iban]);
    }

    /** @return list<BankAccount> every bank account by label, the inactive ones included */
    public function allInOrder(): array
    {
        return array_values($this->em()->getRepository(BankAccount::class)->findBy([], ['label' => 'ASC', 'id' => 'ASC']));
    }

    /**
     * Locks the bank account row EXCLUSIVELY (`SELECT id … FOR UPDATE`) until the caller commits
     * and answers whether it exists — the FIRST lock of a statement import, so two imports for
     * the same account serialise and the second sees the movements the first stored. A locking
     * read takes no read view. Inside an open unit of work only. Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open unit of work
     */
    public function lockRow(int $id): bool
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockRow() needs an open unit of work — the row lock holds until commit');
        }

        return $this->connection()->fetchOne('SELECT id FROM bank_account WHERE id = ? FOR UPDATE', [$id]) !== false;
    }
}
