<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One VAT account of a tax year — the tab «Vorsteuer» of the yearly overview (Art. 128 para. 3
 * MWSTV and the owed side; ADR-042 addendum 2 of 2026-11-25, point 8). Immutable. Every amount on
 * the account's NATURAL side (owed: credit − debit; input: debit − credit), like
 * {@see VatAccountReconciliation}:
 *
 *   - `movement` — the account's movement over the settled-for days of the year, without the year
 *     opening and the return's settlement entries;
 *   - `expected` — the tax the year's CODED lines posted to it, by `VatAccountMap::taxLines()`
 *     (the table the lock and the return's reconciliation read) — the correction entries dated in
 *     the year included;
 *   - {@see remainder()} = movement − expected — what no coded line explains (a posting past the
 *     lock, e.g. straight into the database): red when ≠ 0.00, blocks nothing (small rule 3);
 *   - `declared` — what the year's LIVE returns declared for it, as posted: the owed account the
 *     tax total (399), an input account its input fields (+ 400 / 405 / 410, − 415 / 420), each
 *     return's ROUNDED figure, plus the blocks those returns carried (their correction rounded
 *     once per block) — what the settlement entries cleared;
 *   - {@see difference()} = expected − declared — the Rappen the settlements booked onto the
 *     rounding account; while the year is «vorläufig» it also holds the tax of the periods not
 *     posted yet;
 *   - `fields` — the form fields the account is declared in.
 */
final class VatYearAccount
{
    /** @param list<string> $fields */
    public function __construct(
        public readonly string $role,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly array $fields,
        public readonly Money $movement,
        public readonly Money $expected,
        public readonly Money $declared,
    ) {}

    /** Movement − the tax of the coded lines: anything but 0.00 is red. */
    public function remainder(): Money
    {
        return $this->movement->subtract($this->expected);
    }

    /** The tax of the coded lines − what was declared: the rounding (and, «vorläufig», the open periods). */
    public function difference(): Money
    {
        return $this->expected->subtract($this->declared);
    }
}
