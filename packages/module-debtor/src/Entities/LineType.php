<?php

namespace Z77\Module\Debtor\Entities;

/**
 * The type of an {@see InvoiceLine} (plan §6.2, §13 requirement 2 — A-Pos):
 *
 *   - `service`: quantity × unit price, with unit, discount, tax code and
 *     revenue account;
 *   - `lump-sum`: one price without a quantity, otherwise like a service;
 *   - `text`: prints only — no amount, no tax code, no account;
 *   - `rounding`: the ONE system line of a document, the 0.05 rounding of the
 *     gross total (plan §6.2, its own account `debtorAccounts → rounding`).
 *     Never part of a draft — `InvoicingService` adds it and refuses it in
 *     input. No tax code: a rounding difference is not a supply.
 *
 * `subtotal` (A-Pos) is named in the plan for later; it is not a type yet
 * because nothing prints it (nothing in stock).
 */
enum LineType: string
{
    case Service  = 'service';
    case LumpSum  = 'lump-sum';
    case Text     = 'text';
    case Rounding = 'rounding';

    /** Whether a line of this type carries an amount, a tax code and a revenue account. */
    public function isPriced(): bool
    {
        return $this === self::Service || $this === self::LumpSum;
    }
}
