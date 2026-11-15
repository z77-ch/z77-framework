<?php

namespace Z77\Module\Creditor\Repositories;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query;
use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see CreditorInvoice}. Doctrine-only seams (ADR-039 decision 8): the
 * list search in SQL, the row lock of the optimistic write, the duplicate candidates, the counts
 * the band, the creditor list and the role guard read.
 */
class CreditorInvoiceRepository extends DoctrineRepository
{
    /** Sort key → ORDER BY (a fixed map — never input). */
    private const ORDER = [
        'date'     => 'i.invoice_date %s, i.id %s',
        'amount'   => 'i.amount %s, i.id %s',
        'received' => 'i.received_at %s, i.id %s',
    ];

    /**
     * One page of the journal list: the invoices matching every criterion of $search, in the SQL
     * order. SQL for the ids, DQL for the rows.
     *
     * @return list<CreditorInvoice>
     */
    public function search(CreditorInvoiceSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = sprintf(self::ORDER[$search->sort], $dir, $dir);
        $ids   = array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT i.id FROM creditor_invoice i LEFT JOIN creditor_profile p ON p.id = i.creditor_profile_id LEFT JOIN contact c ON c.id = p.contact_id{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        ));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->em()->createQueryBuilder()->select('i')->from(CreditorInvoice::class, 'i')
                     ->where('i.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $invoice) {
            $byId[(int) $invoice->getId()] = $invoice;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /** How many invoices match $search — the list's pager and badge. SQL. */
    public function countSearch(CreditorInvoiceSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM creditor_invoice i LEFT JOIN creditor_profile p ON p.id = i.creditor_profile_id LEFT JOIN contact c ON c.id = p.contact_id{$where}",
            $params,
            $types
        );
    }

    /**
     * Count and gross total per state — the band of the journal. SQL.
     *
     * @return array<string, array{count: int, gross: string}> state → count, gross total (decimal, '0' when none has an amount)
     */
    public function totalsPerState(): array
    {
        $out = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT state, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS gross FROM creditor_invoice GROUP BY state') as $row) {
            $out[(string) $row['state']] = ['count' => (int) $row['n'], 'gross' => (string) $row['gross']];
        }

        return $out;
    }

    /** How many invoices (any state) name the creditor — the role guard «only deactivate». SQL. */
    public function countForProfile(int $profileId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM creditor_invoice WHERE creditor_profile_id = ?', [$profileId], [ParameterType::INTEGER]);
    }

    /**
     * Invoices per creditor (any state) for the «Kreditoren» list page. SQL.
     *
     * @param list<int> $profileIds
     * @return array<int, int> profile id → count, only creditors with invoices
     */
    public function countByProfiles(array $profileIds): array
    {
        $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $id) => $id > 0));
        if ($profileIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT creditor_profile_id, COUNT(*) FROM creditor_invoice WHERE creditor_profile_id IN (' . implode(',', array_fill(0, count($profileIds), '?')) . ') GROUP BY creditor_profile_id',
            $profileIds,
            array_fill(0, count($profileIds), ParameterType::INTEGER)
        );

        return array_map('intval', array_combine(array_map('intval', array_keys($rows)), array_values($rows)));
    }

    /** The invoice an intake produced (one per intake), or null — e.g. deleted since. */
    public function findByIntake(CreditorIntake $intake): ?CreditorInvoice
    {
        return $intake->getId() === null ? null : $this->findOneBy(['intake' => $intake->getId()]);
    }

    /**
     * The OTHER invoice $id may duplicate (owner answer 4, plan §3): the same creditor and invoice
     * number, or the same IBAN and reference (QRR / SCOR) — any state, the lowest id first. Pairs
     * the owner settled are skipped both ways: the invoice $clearedOf this one was released
     * against, and any invoice already marked as (or released as) a duplicate of $id. SQL.
     *
     * @return array{id: int, by: 'number'|'reference'}|null
     */
    public function findDuplicate(int $id, ?int $creditorId, ?string $number, ?string $iban, ?string $reference, ?int $clearedOf): ?array
    {
        $match  = [];
        $params = [$id, $id, $id];
        $types  = [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER];
        if ($creditorId !== null && $number !== null && $number !== '') {
            $match[] = '(creditor_profile_id = ? AND invoice_number = ?)';
            array_push($params, $creditorId, $number);
            array_push($types, ParameterType::INTEGER, ParameterType::STRING);
        }
        if ($iban !== null && $iban !== '' && $reference !== null && $reference !== '') {
            $match[] = '(iban = ? AND reference = ?)';
            array_push($params, $iban, $reference);
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }
        if ($match === []) {
            return null;
        }
        $skip = '';
        if ($clearedOf !== null) {
            $skip     = ' AND id <> ?';
            $params[] = $clearedOf;
            $types[]  = ParameterType::INTEGER;
        }
        $row = $this->connection()->fetchAssociative(
            'SELECT id, creditor_profile_id, invoice_number FROM creditor_invoice WHERE id <> ?'
            . ' AND NOT (duplicate_of <=> ?) AND NOT (duplicate_cleared_of <=> ?)'
            . ' AND (' . implode(' OR ', $match) . ')' . $skip . ' ORDER BY id LIMIT 1',
            $params,
            $types
        );
        if ($row === false) {
            return null;
        }
        $byNumber = $creditorId !== null && $number !== null && $number !== ''
            && (int) $row['creditor_profile_id'] === $creditorId && (string) $row['invoice_number'] === $number;

        return ['id' => (int) $row['id'], 'by' => $byNumber ? 'number' : 'reference'];
    }

    /**
     * Locks the invoice row EXCLUSIVELY (`SELECT … FOR UPDATE`) and answers its state and version,
     * or null when it is gone. Inside an open unit of work only.
     *
     * @return array{state: string, version: int}|null
     * @throws \LogicException outside an open unit of work
     */
    public function lockRow(int $id): ?array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockRow() needs an open unit of work — the row lock holds until commit');
        }
        $row = $this->connection()->fetchAssociative('SELECT state, version FROM creditor_invoice WHERE id = ? FOR UPDATE', [$id], [ParameterType::INTEGER]);

        return $row === false ? null : ['state' => (string) $row['state'], 'version' => (int) $row['version']];
    }

    /** The invoice REFRESHED from the database (`HINT_REFRESH`) — call it after {@see lockRow()}. */
    public function refreshed(int $id): ?CreditorInvoice
    {
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(CreditorInvoice::class, 'i')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * The WHERE of {@see search()} / {@see countSearch()}: the state and the criteria AND-ed, one
     * bound value each; `LIKE … ESCAPE '!'` with the wildcards escaped (holds under
     * `NO_BACKSLASH_ESCAPES`, the contact.md rule).
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function searchWhere(CreditorInvoiceSearch $search): array
    {
        $where  = ['i.state = ?'];
        $params = [$search->state];
        $types  = [ParameterType::STRING];
        $like   = static fn(string $value): string => '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if ($search->dateFrom !== null) {
            $where[]  = 'i.invoice_date >= ?';
            $params[] = $search->dateFrom;
            $types[]  = ParameterType::STRING;
        }
        if ($search->dateTo !== null) {
            $where[]  = 'i.invoice_date <= ?';
            $params[] = $search->dateTo;
            $types[]  = ParameterType::STRING;
        }
        if ($search->creditor !== null && trim($search->creditor) !== '') {
            $where[] = "(c.company LIKE ? ESCAPE '!' OR c.last_name LIKE ? ESCAPE '!' OR c.first_name LIKE ? ESCAPE '!'"
                . " OR (i.creditor_profile_id IS NULL AND i.creditor_proposal LIKE ? ESCAPE '!'))";
            array_push($params, ...array_fill(0, 4, $like(trim($search->creditor))));
            array_push($types, ...array_fill(0, 4, ParameterType::STRING));
        }
        if ($search->number !== null && trim($search->number) !== '') {
            $where[]  = "i.invoice_number LIKE ? ESCAPE '!'";
            $params[] = $like(trim($search->number));
            $types[]  = ParameterType::STRING;
        }
        if ($search->amount !== null && $search->amount !== '') {
            $where[]  = 'i.amount = ?';
            $params[] = $search->amount;
            $types[]  = ParameterType::STRING;
        }

        return [' WHERE ' . implode(' AND ', $where), $params, $types];
    }
}
