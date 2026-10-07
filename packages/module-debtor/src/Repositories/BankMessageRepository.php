<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\BankMessage;
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
     * @return list<BankMessage>
     */
    public function newestFirst(int $limit): array
    {
        return $this->em()->createQueryBuilder()
            ->select('m')
            ->from(BankMessage::class, 'm')
            ->orderBy('m.importedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
