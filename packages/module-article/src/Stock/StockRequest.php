<?php

namespace Z77\Module\Article\Stock;

/**
 * ONE call of the stock seam (plan §8.1): the source document, why it calls, who, the optional
 * reversal confirmation, and the COMPLETE current target state of the document — every article
 * line, never a delta. Plain data, checked on construction, so every ledger (the pending one of
 * P7 slim, the journal of P7b) receives the same valid shape.
 *
 *   - `sourceType` / `sourceRef` — the opaque origin (`order` / the order id); the ledger keys its
 *     journal by them and never interprets them (the `LedgerService::post()` model);
 *   - `trigger` — what caused the call, for the journal: `status:{from}>{to}`, `lines`,
 *     `delivery-note`, `booking-print` — opaque as well;
 *   - `actor` — who;
 *   - `lines` — the full state: free and text lines are NOT sent (they move no stock). A line
 *     appears at most once; an EMPTY list is valid and means «this document holds nothing» (every
 *     line deleted).
 */
final class StockRequest
{
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;
    public const TRIGGER_LENGTH     = 128;
    public const ACTOR_LENGTH       = 80;

    public readonly string $sourceType;
    public readonly string $sourceRef;
    public readonly string $trigger;
    public readonly string $actor;

    /** @var list<StockLineTarget> */
    public readonly array $lines;

    /**
     * @param list<StockLineTarget> $lines the COMPLETE current state of the document
     * @throws \InvalidArgumentException an empty or over-long origin, trigger or actor; a line listed twice; a non-target in $lines
     */
    public function __construct(
        string $sourceType,
        string $sourceRef,
        string $trigger,
        string $actor,
        array $lines,
        public readonly ?StockConfirmation $confirmation = null,
    ) {
        $this->sourceType = self::text($sourceType, 'source type', self::SOURCE_TYPE_LENGTH);
        $this->sourceRef  = self::text($sourceRef, 'source reference', self::SOURCE_REF_LENGTH);
        $this->trigger    = self::text($trigger, 'trigger', self::TRIGGER_LENGTH);
        $this->actor      = self::text($actor, 'actor', self::ACTOR_LENGTH);

        $seen = [];
        foreach ($lines as $line) {
            if (!$line instanceof StockLineTarget) {
                throw new \InvalidArgumentException('A stock request lists StockLineTarget objects only');
            }
            if (isset($seen[$line->lineRef])) {
                throw new \InvalidArgumentException("Line '{$line->lineRef}' is listed twice — a request carries each line's ONE target");
            }
            $seen[$line->lineRef] = true;
        }
        $this->lines = array_values($lines);
    }

    private static function text(string $value, string $what, int $max): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("A stock request needs a {$what} of 1–{$max} characters");
        }

        return $value;
    }
}
