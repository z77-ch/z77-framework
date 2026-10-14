<?php

namespace Z77\Module\Article\Stock;

/**
 * The answer to {@see StockReasonRequiredException} (ADR-043 decision 14): a REASON the user
 * typed and the confirmation that consumed stock may come back. The ledger journals both
 * movements with this reason (P7b); the source writes the reason into its own change log.
 */
final class StockConfirmation
{
    public const REASON_LENGTH = 255;

    public readonly string $reason;

    /** @throws \InvalidArgumentException an empty reason, or not confirmed */
    public function __construct(string $reason, public readonly bool $confirmed = true)
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_LENGTH) {
            throw new \InvalidArgumentException('A stock reversal needs a reason of 1–' . self::REASON_LENGTH . ' characters');
        }
        if (!$confirmed) {
            throw new \InvalidArgumentException('A stock reversal must be confirmed — an unconfirmed one is no confirmation');
        }
        $this->reason = $reason;
    }
}
