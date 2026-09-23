<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Shared\Money\Money;

/**
 * What the receivables side hands the accounting port (plan §6.6): a
 * posting date, a text, the OPAQUE origin (`sourceType` + `sourceRef`, what
 * financial stores and never interprets — ADR-040 decision 2), an
 * idempotency key and the lines. Debtor's OWN DTO — the mirror of
 * financial's `PostingRequest`, translated 1:1 by {@see LedgerAccountingGateway};
 * the mirror exists so that debtor compiles and runs without financial
 * (ADR-040 decision 5, the `suggest`).
 *
 * The invariants that need no bookkeeping are checked here, once: at least
 * two lines, one currency, Σ debit = Σ credit (ADR-042 decision 5 — checked
 * in the domain that builds the posting, before any port sees it).
 */
final class PostingRequest
{
    public const TEXT_LENGTH = 255;

    /** @param list<PostingLine> $lines */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $text,
        public readonly string $sourceType,
        public readonly string $sourceRef,
        public readonly string $idempotencyKey,
        public readonly array $lines,
    ) {
        if (trim($text) === '' || mb_strlen($text) > self::TEXT_LENGTH) {
            throw new \InvalidArgumentException('Posting: text is required, at most ' . self::TEXT_LENGTH . ' characters');
        }
        foreach (['sourceType' => $sourceType, 'sourceRef' => $sourceRef, 'idempotencyKey' => $idempotencyKey] as $name => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("Posting: {$name} is required");
            }
        }
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('Posting: a journal entry has at least two lines');
        }
        $currency = null;
        $debit    = null;
        $credit   = null;
        foreach ($lines as $i => $line) {
            if (!$line instanceof PostingLine) {
                throw new \InvalidArgumentException("Posting: line {$i} is not a PostingLine");
            }
            $currency ??= $line->debit->currency;
            $debit    ??= Money::zero($currency);
            $credit   ??= Money::zero($currency);
            if ($line->debit->currency !== $currency) {
                throw new \InvalidArgumentException("Posting: line {$i} is in {$line->debit->currency}, the entry in {$currency}");
            }
            $debit  = $debit->add($line->debit);
            $credit = $credit->add($line->credit);
        }
        if (!$debit->equals($credit)) {
            throw new \InvalidArgumentException("Posting: not balanced — debit {$debit}, credit {$credit}");
        }
    }

    public function currency(): string
    {
        return $this->lines[0]->debit->currency;
    }
}
