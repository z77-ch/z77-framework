<?php

namespace Z77\Module\Debtor\Repositories;

use Doctrine\DBAL\ParameterType;
use Z77\Module\Debtor\Entities\InvoiceJournalPosting;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see InvoiceJournalPosting} — the protocols of «Journal verbuchen»
 * (ADR-054 decision 6): the paged list of the tab «Verbuchte Journale». Doctrine-only (SQL for
 * the ids and the count, DQL for the rows).
 */
class InvoiceJournalPostingRepository extends DoctrineRepository
{
    /**
     * One page of protocols by id — newest first, or oldest first.
     *
     * @return list<InvoiceJournalPosting>
     */
    public function page(int $offset, int $limit, bool $newestFirst = true): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM invoice_journal_posting ORDER BY id ' . ($newestFirst ? 'DESC' : 'ASC') . ' LIMIT ? OFFSET ?',
            [max(1, $limit), max(0, $offset)],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->find((int) $id);
            if ($row instanceof InvoiceJournalPosting) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function countAll(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM invoice_journal_posting');
    }
}
