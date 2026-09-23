<?php

namespace Z77\Module\Debtor\Repositories;

use Doctrine\DBAL\LockMode;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see Invoice}. Doctrine-only seams (ADR-039
 * decision 8), each documented: the fetch-joined read of one document with
 * its lines, the locking re-read a write path needs, and one SQL aggregate.
 */
class InvoiceRepository extends DoctrineRepository
{
    /**
     * The document with its lines in ONE query (fetch-join). The tax rows
     * load lazily on first access — a handful per document. Doctrine-only (DQL).
     */
    public function withLines(int $id): ?Invoice
    {
        return $this->em()->createQueryBuilder()
            ->select('i', 'l')
            ->from(Invoice::class, 'i')
            ->leftJoin('i.lines', 'l')
            ->where('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The document re-read under an EXCLUSIVE row lock (`SELECT … FOR
     * UPDATE`), held until the caller's commit — what a re-issue and a
     * finalize do before touching it, so two writers serialise and each
     * sees the committed state of the other. Inside an open unit of work
     * only (the `JournalEntryRepository::lockForUpdate()` model).
     * Doctrine-only.
     *
     * @throws \LogicException outside an open unit of work
     */
    public function lockForUpdate(int $id): ?Invoice
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockForUpdate() needs an open unit of work — the row lock holds until commit');
        }
        if ($this->em()->find(Invoice::class, $id, LockMode::PESSIMISTIC_WRITE) === null) {
            return null;
        }

        return $this->withLines($id);
    }

    /**
     * Σ gross of the FINAL credit notes against $invoice, as a decimal
     * string («0.00» when none) — the open amount is DERIVED from it
     * (plan §6.3: never stored in parallel). Doctrine-only (SQL).
     */
    public function sumOfFinalCreditNotes(Invoice $invoice): string
    {
        if ($invoice->getId() === null) {
            return '0.00';
        }
        $sum = $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(gross_total), 0.00) FROM invoice WHERE credit_note_of_id = ? AND kind = ? AND state = ?',
            [$invoice->getId(), InvoiceKind::CreditNote->value, InvoiceState::Final->value]
        );

        return (string) $sum;
    }
}
