<?php

namespace Z77\Module\Financial\Ledger;

use Z77\Module\Financial\Entities\EntryKind;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Shared\Money\Money;

/**
 * What a posting source hands `LedgerService::post()` (plan §5.4, ADR-040
 * decision 2): date, text, the OPAQUE origin, the idempotency key and the
 * lines. This DTO and {@see EntryRef} are the whole surface another module
 * sees of the journal. Immutable; the invariants that need no database are
 * checked here, once, on construction:
 *
 *   - at least two lines, all in one currency;
 *   - Σ debit = Σ credit (ADR-042 decision 5 — checked in the domain before
 *     anything is persisted);
 *   - a GENERATED request names its origin and carries an idempotency key;
 *     a MANUAL one carries no key (the bookkeeper's «post» is not a retry).
 *
 * Built through {@see generated()} by a module and through {@see manual()}
 * by financial's own manual-entry path.
 */
final class PostingRequest
{
    /**
     * @param list<PostingLine> $lines
     */
    private function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $text,
        public readonly EntryKind $kind,
        public readonly ?string $sourceType,
        public readonly ?string $sourceRef,
        public readonly ?string $idempotencyKey,
        public readonly array $lines,
    ) {
        if (trim($text) === '' || mb_strlen($text) > JournalEntry::TEXT_LENGTH) {
            throw new \InvalidArgumentException('Posting: text is required, at most ' . JournalEntry::TEXT_LENGTH . ' characters');
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
                throw new \InvalidArgumentException("Posting: line {$i} is in {$line->debit->currency}, the entry in {$currency} — the ledger posts one currency (ADR-042 decision 4)");
            }
            $debit  = $debit->add($line->debit);
            $credit = $credit->add($line->credit);
        }
        if (!$debit->equals($credit)) {
            throw new \InvalidArgumentException("Posting: not balanced — debit {$debit}, credit {$credit}");
        }
        if ($kind === EntryKind::Generated) {
            if ($sourceType === null || trim($sourceType) === '' || mb_strlen($sourceType) > JournalEntry::SOURCE_TYPE_LENGTH) {
                throw new \InvalidArgumentException('Posting: a generated entry names its source type (1-' . JournalEntry::SOURCE_TYPE_LENGTH . ' characters)');
            }
            if ($sourceRef === null || trim($sourceRef) === '' || mb_strlen($sourceRef) > JournalEntry::SOURCE_REF_LENGTH) {
                throw new \InvalidArgumentException('Posting: a generated entry names its source reference (1-' . JournalEntry::SOURCE_REF_LENGTH . ' characters)');
            }
            if ($idempotencyKey === null || trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > JournalEntry::KEY_LENGTH) {
                throw new \InvalidArgumentException('Posting: a generated entry carries an idempotency key (1-' . JournalEntry::KEY_LENGTH . ' characters)');
            }
        } elseif ($idempotencyKey !== null) {
            throw new \InvalidArgumentException('Posting: a manual entry carries no idempotency key');
        }
    }

    /**
     * A posting by a module: the origin is opaque to financial (`sourceType`
     * names the kind of thing, `sourceRef` the thing — `invoice` / `2026-0042`),
     * the key makes a repeated call return the same entry.
     *
     * @param list<PostingLine> $lines
     */
    public static function generated(\DateTimeImmutable $date, string $text, string $sourceType, string $sourceRef, string $idempotencyKey, array $lines): self
    {
        return new self($date, $text, EntryKind::Generated, $sourceType, $sourceRef, $idempotencyKey, array_values($lines));
    }

    /**
     * A manual entry (the bookkeeper in financial). No origin, no key.
     *
     * @param list<PostingLine> $lines
     */
    public static function manual(\DateTimeImmutable $date, string $text, array $lines): self
    {
        return new self($date, $text, EntryKind::Manual, null, null, null, array_values($lines));
    }

    public function currency(): string
    {
        return $this->lines[0]->debit->currency;
    }

    public function hasTaxLine(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->hasTax()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The «content» of the request for the idempotency comparison
     * (`LedgerService::post()`): date, text, origin and the lines in order —
     * everything a module decides. Compared with the stored entry's
     * snapshot; a repeated key with different content is refused loudly
     * rather than answered with the old entry.
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'date'        => $this->date->format('Y-m-d'),
            'text'        => $this->text,
            'source_type' => $this->sourceType,
            'source_ref'  => $this->sourceRef,
            'lines'       => array_map(static fn(PostingLine $line) => $line->fingerprint(), $this->lines),
        ];
    }

    /**
     * The same content as a stored entry's snapshot, reduced to the fields
     * both sides have — the other half of the comparison.
     *
     * @param array<string, mixed> $snapshot `JournalEntry::snapshot()`
     * @return array<string, mixed>
     */
    public static function fingerprintOf(array $snapshot): array
    {
        $keys = ['account', 'debit', 'credit', 'tax_code', 'tax_rate', 'tax_base', 'tax_amount', 'text'];

        return [
            'date'        => $snapshot['date'] ?? null,
            'text'        => $snapshot['text'] ?? null,
            'source_type' => $snapshot['source_type'] ?? null,
            'source_ref'  => $snapshot['source_ref'] ?? null,
            'lines'       => array_map(
                static fn(array $line) => array_intersect_key($line, array_fill_keys($keys, true)) + array_fill_keys($keys, null),
                array_values($snapshot['lines'] ?? [])
            ),
        ];
    }
}
