<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Z77\Module\Financial\Entities\Account;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see Account}, extending the Doctrine base
 * (ADR-039 decision 6). Three Doctrine-only seams (decision 8), each
 * documented as the deviation it is:
 *
 *   - the chart in number order is DQL on `em()` — `findAll()` has no
 *     ORDER BY, and the chart's order IS the string order of the numbers;
 *   - «is the chart empty?» is SQL on `connection()` — the KMU adoption asks
 *     it, and hydrating the chart to count it would be the wrong tool;
 *   - the two row locks of FIN-TYPE-001 are SQL on `connection()`: an
 *     account's type / postable change locks its row EXCLUSIVELY
 *     ({@see lockForUpdate()}), a posting locks the rows of its accounts
 *     SHARED and reads their flags fresh ({@see lockForPosting()}) — so a
 *     posting and «becomes a group» never both commit.
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

    /**
     * Locks the account's row EXCLUSIVELY until the caller's commit and
     * returns its CURRENT `type` and `postable` (a locking read — the
     * latest committed row, not the transaction's snapshot), or null when
     * the row does not exist. `AccountService::update()` takes it as the
     * FIRST statement of its unit of work before it re-checks a type or
     * postable change against the journal (FIN-TYPE-001): a posting on
     * this account either holds the row shared already (this lock waits
     * for its commit, the re-check then sees its line) or waits in
     * {@see lockForPosting()} and then reads the new flags. Takes no other
     * lock after it — no cycle with a posting (range, then accounts).
     * Refused outside a unit of work. Doctrine-only (SQL).
     *
     * @return array{type: string, postable: bool}|null
     * @throws \LogicException outside an open transaction
     */
    public function lockForUpdate(int $id): ?array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockForUpdate() needs an open unit of work — the row lock holds until commit');
        }
        $row = $this->connection()->fetchAssociative('SELECT type, postable FROM account WHERE id = ? FOR UPDATE', [$id]);

        return $row === false ? null : ['type' => (string) $row['type'], 'postable' => (bool) $row['postable']];
    }

    /**
     * Locks the rows of the given account numbers SHARED until the caller's
     * commit and returns their CURRENT `postable` and `active` flags — a
     * locking read, so the flags are the latest committed ones, never an
     * older snapshot of the caller's transaction or a stale object in the
     * Identity Map. `PostingRules::lockAccounts()` re-checks on these flags
     * (FIN-TYPE-001): an account turned into a group while a posting was
     * being validated is seen as a group here, or its change waits for the
     * posting's commit and then finds the line. Parallel postings do not
     * block each other (shared locks). `LedgerService::post()` takes it
     * AFTER the `NumberRange` draw (lock order `NumberRange` first, ADR-039
     * decision 10). The rows are locked in no particular order, and need
     * none: every poster takes SHARED locks, which never wait on each
     * other, and the only exclusive taker, `AccountService::update()`,
     * takes exactly one row lock as its first statement and nothing after
     * — no cycle is possible. A number that does not exist is simply
     * missing from the result. Refused outside a unit of work. Doctrine-only (SQL).
     *
     * @param list<string> $numbers
     * @return array<string, array{postable: bool, active: bool}> number → flags
     * @throws \LogicException outside an open transaction
     */
    public function lockForPosting(array $numbers): array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockForPosting() needs an open unit of work — the row locks hold until commit');
        }
        if ($numbers === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT number, postable, active FROM account WHERE number IN (?) LOCK IN SHARE MODE',
            [array_values(array_unique($numbers))],
            [ArrayParameterType::STRING]
        );
        $flags = [];
        foreach ($rows as $r) {
            $flags[(string) $r['number']] = ['postable' => (bool) $r['postable'], 'active' => (bool) $r['active']];
        }

        return $flags;
    }
}
