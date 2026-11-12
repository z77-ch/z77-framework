<?php

namespace Z77\Module\Receipt\Repositories;

use Doctrine\DBAL\ParameterType;
use Z77\Module\Receipt\Entities\ReceiptJournalPosting;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * The protocols of «Journal verbuchen» — the tab «Verbuchte Journale» of the receipt journal (the
 * shape of debtor's `InvoiceJournalPostingRepository`). Doctrine-only.
 */
class ReceiptJournalPostingRepository extends DoctrineRepository
{
    /**
     * One page of protocols by id — newest first by default.
     *
     * @return list<ReceiptJournalPosting>
     */
    public function page(int $offset, int $limit, bool $newestFirst = true): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM receipt_journal_posting ORDER BY id ' . ($newestFirst ? 'DESC' : 'ASC') . ' LIMIT ? OFFSET ?',
            [max(1, $limit), max(0, $offset)],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->find((int) $id);
            if ($row instanceof ReceiptJournalPosting) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function countAll(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM receipt_journal_posting');
    }
}
