<?php

namespace Z77\Module\Debtor\Services;

/**
 * A re-issue named a version the document no longer has, or a document that
 * is gone — another writer was first (the optimistic lock of
 * `invoice.version`, the `EntryConflictException` model). The user reloads
 * and sees the current document; nothing is merged.
 */
final class InvoiceConflictException extends DebtorException
{
    public function __construct(public readonly int $invoiceId, public readonly int $expectedVersion)
    {
        parent::__construct("Invoice {$invoiceId} was changed or removed in the meantime (expected version {$expectedVersion}) — reload it");
    }
}
