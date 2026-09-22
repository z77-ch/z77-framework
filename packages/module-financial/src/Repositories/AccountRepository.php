<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\Account;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see Account}, extending the Doctrine base
 * (ADR-039 decision 6). Two Doctrine-only seams (decision 8), each
 * documented as the deviation it is:
 *
 *   - the chart in number order is DQL on `em()` — `findAll()` has no
 *     ORDER BY, and the chart's order IS the string order of the numbers;
 *   - «is the chart empty?» is SQL on `connection()` — the KMU adoption asks
 *     it, and hydrating the chart to count it would be the wrong tool.
 */
class AccountRepository extends DoctrineRepository
{
    /**
     * Every account, sorted by number as a string — the chart's own order
     * (`1`, `10`, `100`, `1000`, `1020`, …, `11`). Every parent is in the
     * result as well, so the parents resolve from the Identity Map, not by
     * one query per row. Doctrine-only (DQL).
     *
     * @return list<Account>
     */
    public function allInOrder(): array
    {
        return $this->em()->createQueryBuilder()
            ->select('a')
            ->from(Account::class, 'a')
            ->orderBy('a.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** True while the chart has no account at all, active or not. Doctrine-only (SQL). */
    public function isEmpty(): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM account LIMIT 1') === false;
    }
}
