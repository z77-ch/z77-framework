<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\BankMessage;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Entities\TransactionState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankMessage} — the dedup lookup of the
 * import and the list of the screen. Doctrine-only (ADR-039 decision 8).
 */
class BankMessageRepository extends DoctrineRepository
{
    /** The message under the bank's id, or null — `uniq_bank_message_id` makes it one row. */
    public function findByMessageId(string $messageId): ?BankMessage
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }

    /**
     * The imports, newest first. The transactions load lazily per message
     * (the list counts them per state) — $limit messages, one query each,
     * fine for the handful of imports a month; a fetch-join with a row
     * limit would cut messages (Doctrine applies the limit to joined rows).
     *
     * `$done` splits the list into the tabs of the screen (owner 2026-11-15): `false` = the
     * messages with OPEN WORK (at least one transaction `unmatched` or `matched`), `true` = the
     * done ones (every transaction `booked` or `ignored` — a message without a transaction
     * too), `null` = all of them.
     *
     * @return list<BankMessage>
     */
    public function newestFirst(int $limit, ?bool $done = null): array
    {
        $query = $this->em()->createQueryBuilder()
            ->select('m')
            ->from(BankMessage::class, 'm')
            ->orderBy('m.importedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit);
        if ($done !== null) {
            $openWork = $this->em()->createQueryBuilder()
                ->select('t.id')
                ->from(BankTransaction::class, 't')
                ->where('t.message = m')
                ->andWhere('t.state IN (:openStates)');
            $query->where(($done ? 'NOT ' : '') . 'EXISTS (' . $openWork->getDQL() . ')')
                ->setParameter('openStates', [TransactionState::Unmatched->value, TransactionState::Matched->value]);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * Locks the message row EXCLUSIVELY (`SELECT id … FOR UPDATE`) until the
     * caller commits and answers whether it exists — the FIRST lock of every
     * write on a message's transactions (book, assign, ignore), so two of
     * them serialise and the second re-reads what the first committed
     * (review 2026-10-13 H4: a double «Verbuchen» must not book twice). A
     * locking read takes no read view. Inside an open unit of work only.
     * Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open unit of work
     */
    public function lockRow(int $id): bool
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockRow() needs an open unit of work — the row lock holds until commit');
        }

        return $this->connection()->fetchOne('SELECT id FROM bank_message WHERE id = ? FOR UPDATE', [$id]) !== false;
    }
}
