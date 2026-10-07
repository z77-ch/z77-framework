<?php

namespace Z77\Module\Debtor\Repositories;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see Invoice}. Doctrine-only seams (ADR-039
 * decision 8), each documented: the fetch-joined read of one document with
 * its lines, the locking re-read a write path needs, the SQL aggregates, and
 * — since P3 part 3 — the lookups of the document screens (by number, the
 * list per view with its search, the credit notes of an invoice).
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

    /** A document by kind and number — the number is unique per kind (`uniq_invoice_kind_number`). */
    public function findByNumber(InvoiceKind $kind, int $number): ?Invoice
    {
        return $this->findOneBy(['kind' => $kind->value, 'number' => $number]);
    }

    /**
     * The PAYABLE document with $number — an invoice or a fee; they share
     * the `invoice` number range, so there is at most one. What a QR
     * reference (positions 17–26) and a message's «Rechnung n» name.
     */
    public function findPayableByNumber(int $number): ?Invoice
    {
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.kind <> :credit AND i.number = :number')
            ->setParameter('credit', InvoiceKind::CreditNote->value)
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The open amount of EVERY final invoice (kind invoice) that still has
     * one, in ONE query: invoice id → decimal string (gross − final credit
     * notes − allocations). The due list of the dunning (P4 part 3) reads
     * this, never `openAmount()` per row (DEBTOR-OPEN-001). Doctrine-only
     * (SQL).
     *
     * @return array<int, string>
     */
    public function openAmountsOfFinalInvoices(): array
    {
        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT i.id, i.gross_total'
            . ' - COALESCE((SELECT SUM(c.gross_total) FROM invoice c WHERE c.credit_note_of_id = i.id AND c.kind = ? AND c.state = ?), 0)'
            . ' - COALESCE((SELECT SUM(a.amount) FROM payment_allocation a WHERE a.invoice_id = i.id), 0) AS open_amount'
            . ' FROM invoice i WHERE i.kind = ? AND i.state = ?'
            . ' HAVING open_amount > 0',
            [InvoiceKind::CreditNote->value, InvoiceState::Final->value, InvoiceKind::Invoice->value, InvoiceState::Final->value]
        );

        return array_map(static fn($v) => (string) $v, $rows);
    }

    /**
     * The FINAL documents of $kind of one party, oldest first — the fees
     * an overpayment is placed on (P4 part 2/3), the invoices a due list
     * walks. Doctrine-only (DQL).
     *
     * @return list<Invoice>
     */
    public function finalOfKindFor(int $contactId, InvoiceKind $kind): array
    {
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.contact = :contact AND i.kind = :kind AND i.state = :final')
            ->setParameter('contact', $contactId)
            ->setParameter('kind', $kind->value)
            ->setParameter('final', InvoiceState::Final->value)
            ->orderBy('i.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of the document list (P3 part 3): the documents of the view
     * matching every criterion of $search, in its order. The search runs in
     * the database — never a filter of the rows on screen. Every value is
     * bound; the ORDER BY comes from a fixed map, never from input.
     * Doctrine-only (SQL + DQL).
     *
     * @return list<Invoice>
     */
    public function search(InvoiceSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            'date'   => "invoice_date {$dir}, number {$dir}",
            'name'   => "addr_name {$dir}, addr_first_name {$dir}, number {$dir}",
            'amount' => "gross_total {$dir}, number {$dir}",
            default  => "number {$dir}",
        };
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT id FROM invoice{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        );
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->em()->createQueryBuilder()->select('i')->from(Invoice::class, 'i')->where('i.id IN (:ids)')->setParameter('ids', array_map('intval', $ids))->getQuery()->getResult() as $invoice) {
            $byId[$invoice->getId()] = $invoice;
        }

        return array_values(array_filter(array_map(static fn($id) => $byId[(int) $id] ?? null, $ids)));
    }

    /** How many documents match $search — the list's pager and badge. Doctrine-only (SQL). */
    public function countSearch(InvoiceSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM invoice{$where}", $params, $types);
    }

    /**
     * The documents per view without any criterion — the badges of the view
     * tabs. Doctrine-only (SQL, one query).
     *
     * @return array<string, int> view → count
     */
    public function countPerView(): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT SUM(kind IN (?, ?) AND state = ?) AS invoicing, SUM(kind IN (?, ?) AND state = ?) AS final, SUM(kind = ?) AS credit FROM invoice',
            [InvoiceKind::Invoice->value, InvoiceKind::Fee->value, InvoiceState::Invoicing->value, InvoiceKind::Invoice->value, InvoiceKind::Fee->value, InvoiceState::Final->value, InvoiceKind::CreditNote->value]
        ) ?: [];

        return array_map(static fn($n) => (int) $n, array_merge(array_fill_keys(InvoiceSearch::VIEWS, 0), array_filter($row, static fn($n) => $n !== null)));
    }

    /**
     * The credit notes issued against $invoice, oldest first — the detail
     * view lists them. Doctrine-only (DQL).
     *
     * @return list<Invoice>
     */
    public function creditNotesOf(Invoice $invoice): array
    {
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.creditNoteOf = :invoice')
            ->setParameter('invoice', $invoice)
            ->orderBy('i.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<ParameterType>} */
    private function searchWhere(InvoiceSearch $search): array
    {
        $where  = [];
        $params = [];
        $types  = [];
        $add = static function (string $sql, mixed $value, ParameterType $type = ParameterType::STRING) use (&$where, &$params, &$types): void {
            $where[]  = $sql;
            $params[] = $value;
            $types[]  = $type;
        };
        if ($search->view === InvoiceSearch::VIEW_CREDIT) {
            $add('kind = ?', InvoiceKind::CreditNote->value);
        } else {
            // The payable kinds — invoices and fees (P4 part 3) — share the two views.
            $add('kind <> ?', InvoiceKind::CreditNote->value);
            $add('state = ?', $search->view === InvoiceSearch::VIEW_FINAL ? InvoiceState::Final->value : InvoiceState::Invoicing->value);
        }
        if ($search->number !== null) {
            $add('number = ?', $search->number, ParameterType::INTEGER);
        }
        if ($search->dateFrom !== null) {
            $add('invoice_date >= ?', $search->dateFrom);
        }
        if ($search->dateTo !== null) {
            $add('invoice_date <= ?', $search->dateTo);
        }
        if ($search->name !== null) {
            $like = '%' . addcslashes($search->name, '\\%_') . '%';
            $where[]  = "(addr_name LIKE ? OR addr_first_name LIKE ? OR CONCAT(addr_first_name, ' ', addr_name) LIKE ?)";
            array_push($params, $like, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING);
        }
        if ($search->amount !== null) {
            $add('gross_total = ?', $search->amount);
        }

        return [' WHERE ' . implode(' AND ', $where), $params, $types];
    }

    /**
     * The documents still in `invoicing` (not final) dated from $from to
     * $to, both inclusive — what the year close is blocked by
     * (`InvoicingInProgressCheck`, plan §5.3): their posting would land in a
     * closed year. The count of all of them and the first $limit rows
     * (`id`, `kind`, `number`, `invoice_date`) in date order — by the index
     * `idx_invoice_state_date`. Doctrine-only (SQL).
     *
     * @return array{count: int, rows: list<array{id: int|string, kind: string, number: int|string, invoice_date: string}>}
     */
    public function invoicingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $params = [InvoiceState::Invoicing->value, $from->format('Y-m-d'), $to->format('Y-m-d')];
        $where  = 'FROM invoice WHERE state = ? AND invoice_date BETWEEN ? AND ?';
        $count  = (int) $this->connection()->fetchOne('SELECT COUNT(*) ' . $where, $params);
        $rows   = $count === 0 ? [] : $this->connection()->fetchAllAssociative(
            'SELECT id, kind, number, invoice_date ' . $where . ' ORDER BY invoice_date, id LIMIT ?',
            [...$params, max(1, $limit)],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        );

        return ['count' => $count, 'rows' => $rows];
    }
}
