<?php

namespace Z77\Module\Article\Stock;

/**
 * What one {@see StockLedger::bringTo()} did: how many movements it journalled — 0 when the
 * journal already held the target (a repeated call), and always 0 for the pending ledger of P7
 * slim, which journals nothing. The movements themselves are the journal's (P7b); a source does
 * not read them back.
 */
final class StockResult
{
    private function __construct(public readonly int $movements)
    {
    }

    /** Nothing was booked — the target was already held, or no journal exists yet (P7 slim). */
    public static function none(): self
    {
        return new self(0);
    }

    /** $count movements were journalled (P7b). */
    public static function booked(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('A movement count is never negative');
        }

        return new self($count);
    }

    public function hasMovements(): bool
    {
        return $this->movements > 0;
    }
}
