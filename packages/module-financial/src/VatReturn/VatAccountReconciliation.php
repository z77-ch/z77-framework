<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One VAT account in the reconciliation of the tab «Abstimmung»: its MOVEMENT in the period
 * (without the settlement and the opening entries — never its balance) against the tax the CODED
 * lines posted to it (ADR-041 addendum 2026-11-24, point 6; ADR-042 addendum point 1: a gap is
 * BLOCKING, never something the rounding account absorbs). Immutable.
 *
 *   - `role` — {@see OWED} (the VAT-owed account, against the posted tax of the rate fields) or
 *     {@see INPUT} (an input-tax account, against its input field);
 *   - `accountNumber` — the mandator's account for the categories; `accountName` '' when the
 *     number is not an account of the chart;
 *   - `fields` — the form fields whose coded lines post to this account;
 *   - `expected` — Σ posted tax of those coded lines, on the account's natural side (owed:
 *     credit, input: debit);
 *   - `movement` — the account's movement, on the same side; `lines` its journal lines;
 *   - `difference` — movement − expected; anything but 0.00 is the blocking finding.
 */
final class VatAccountReconciliation
{
    public const OWED  = 'owed';
    public const INPUT = 'input';

    /** @param list<string> $fields */
    public function __construct(
        public readonly string $role,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly array $fields,
        public readonly Money $expected,
        public readonly Money $movement,
        public readonly int $lines,
    ) {}

    public function difference(): Money
    {
        return $this->movement->subtract($this->expected);
    }

    public function agrees(): bool
    {
        return $this->difference()->isZero();
    }
}
