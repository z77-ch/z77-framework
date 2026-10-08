<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Shared\Money\Money;

/**
 * One row of the open-item list — a FINAL payable document with what was
 * invoiced, what settled it and what is still open, read in ONE query
 * ({@see InvoiceRepository::openItems()}); a read model, never persisted.
 *
 * `settled` = the payment allocations (payments, discount, loss) plus the
 * final credit notes issued against the document; `open` = gross − settled.
 * `level` is the highest dunning notice the document carries (0: none).
 */
final class OpenItem
{
    public function __construct(
        public readonly int $id,
        public readonly InvoiceKind $kind,
        public readonly int $number,
        public readonly int $customerNumber,
        public readonly string $name,
        public readonly \DateTimeImmutable $invoiceDate,
        public readonly \DateTimeImmutable $dueDate,
        public readonly Money $gross,
        public readonly Money $settled,
        public readonly Money $open,
        public readonly int $level,
    ) {}

    public function isSettled(): bool
    {
        return !$this->open->isPositive();
    }

    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->open->isPositive() && $this->dueDate < $today;
    }

    /** «Rechnung 2609002», «Gebühr 47» — the document's name as the screens write it. */
    public function documentName(): string
    {
        return $this->kind->label() . ' ' . $this->number;
    }
}
