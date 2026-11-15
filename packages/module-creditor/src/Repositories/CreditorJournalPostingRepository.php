<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorJournalPosting;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see CreditorJournalPosting}: the protocols of the tab «Verbuchte
 * Journale», one page at a time. Doctrine-only (DQL / SQL).
 */
class CreditorJournalPostingRepository extends DoctrineRepository
{
    /** @return list<CreditorJournalPosting> */
    public function page(int $offset, int $limit, bool $newestFirst = true): array
    {
        $dir = $newestFirst ? 'DESC' : 'ASC';

        return array_values($this->em()->createQueryBuilder()->select('p')->from(CreditorJournalPosting::class, 'p')
            ->orderBy('p.postedAt', $dir)->addOrderBy('p.id', $dir)
            ->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit))->getQuery()->getResult());
    }

    public function countAll(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM creditor_journal_posting');
    }
}
