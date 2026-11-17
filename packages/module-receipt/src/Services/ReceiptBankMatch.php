<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Entities\BankMovement;

/**
 * What the bank match found for ONE receipt (ADR-061 decision 11, stage C5) — pure data. A receipt
 * carries no QR reference, so there is NO identity level: nothing is ever taken without the office.
 *
 *   - {@see PROPOSAL}   — level 2: the booked amount equal, the booking date in the receipt's
 *     window, on the receipt's bank account, and exactly one movement (or exactly one that names
 *     the receipt's text). Proposed, ticked in advance;
 *   - {@see CANDIDATES} — level 2: several fit, nothing is unique; level 3: a movement whose
 *     ORIGINAL (foreign) amount equals the receipt's amount — the receipt names no currency, so
 *     it is never ticked. Shown, never a guess;
 *   - {@see NONE}       — nothing fits, or the receipt was not paid through a bank account.
 *
 * Twin of module-creditor's `CreditorBankMatch` (extraction pending, receipt.md).
 */
final class ReceiptBankMatch
{
    public const PROPOSAL   = 'proposal';
    public const CANDIDATES = 'candidates';
    public const NONE       = 'none';

    /**
     * @param int $level                    2 CHF likeness · 3 foreign-currency likeness · 0 none
     * @param list<BankMovement> $movements the one movement of a proposal, or the candidates (named and nearest first)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $level = 0,
        public readonly array $movements = [],
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** The movement a proposal stands for; null for candidates / none. */
    public function proposed(): ?BankMovement
    {
        return $this->kind === self::PROPOSAL ? ($this->movements[0] ?? null) : null;
    }
}
