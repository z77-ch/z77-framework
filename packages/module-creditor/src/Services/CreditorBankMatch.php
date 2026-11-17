<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Financial\Entities\BankMovement;

/**
 * What the bank match found for ONE creditor invoice (ADR-061 decision 11, stage C1) — pure data.
 *
 *   - {@see IDENTITY}   — level 1: the QR / RF reference is identical (and the amount agrees),
 *     exactly one movement, no other invoice claims it. Taken WITHOUT asking;
 *   - {@see PROPOSAL}   — level 2 (CHF: amount + the creditor's name / IBAN / «Banktext» in the
 *     entry + the date window) or level 3 (foreign currency: original amount + currency + the
 *     merchant text + the date window): exactly one movement. Proposed, ticked in advance;
 *   - {@see CANDIDATES} — something fits, nothing is unique: shown, never ticked, never a guess;
 *   - {@see NONE}       — nothing fits (or the invoice is blocked, or carries no amount).
 */
final class CreditorBankMatch
{
    public const IDENTITY   = 'identity';
    public const PROPOSAL   = 'proposal';
    public const CANDIDATES = 'candidates';
    public const NONE       = 'none';

    /**
     * @param int $level                  1 reference · 2 CHF likeness · 3 foreign-currency likeness · 0 none
     * @param list<BankMovement> $movements the one movement of an identity / a proposal, or the candidates (nearest first)
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

    /** The movement the match stands for — of an identity or a proposal; null for candidates / none. */
    public function proposed(): ?BankMovement
    {
        return in_array($this->kind, [self::IDENTITY, self::PROPOSAL], true) ? ($this->movements[0] ?? null) : null;
    }
}
