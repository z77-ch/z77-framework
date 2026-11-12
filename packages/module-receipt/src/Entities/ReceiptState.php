<?php

namespace Z77\Module\Receipt\Entities;

/**
 * Where a receipt stands (owner decision E1, 2026-11-12): `captured` — in the receipt journal,
 * correctable and deletable, the ledger sees nothing; `posted` — «Journal verbuchen» booked it,
 * it names its journal entry and is read-only.
 */
enum ReceiptState: string
{
    case Captured = 'captured';
    case Posted   = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Captured => 'erfasst',
            self::Posted   => 'verbucht',
        };
    }
}
