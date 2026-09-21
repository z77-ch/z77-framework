<?php

namespace Z77\Module\Vat\Calculation;

use Z77\Shared\Money\Money;

/**
 * The tax summary of one document: one {@see TaxSummaryEntry} per tax code,
 * ordered by code, plus the totals. Every amount is in the one currency the
 * summary was built for.
 */
final class TaxSummary
{
    /** @var array<string, TaxSummaryEntry> code → entry, sorted by code */
    private array $entries = [];

    /** @param list<TaxSummaryEntry> $entries */
    public function __construct(public readonly string $currency, array $entries = [])
    {
        foreach ($entries as $entry) {
            if (isset($this->entries[$entry->code])) {
                throw new \InvalidArgumentException("TaxSummary: duplicate code '{$entry->code}'");
            }
            if ($entry->base->currency !== $currency || $entry->tax->currency !== $currency) {
                throw new \InvalidArgumentException("TaxSummary: entry '{$entry->code}' is not in {$currency}");
            }
            $this->entries[$entry->code] = $entry;
        }
        ksort($this->entries, SORT_STRING);
    }

    /** @return list<TaxSummaryEntry> ordered by code */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    public function byCode(string $code): ?TaxSummaryEntry
    {
        return $this->entries[$code] ?? null;
    }

    public function base(): Money
    {
        return $this->sum(static fn(TaxSummaryEntry $e) => $e->base);
    }

    public function tax(): Money
    {
        return $this->sum(static fn(TaxSummaryEntry $e) => $e->tax);
    }

    public function gross(): Money
    {
        return $this->base()->add($this->tax());
    }

    /** @param callable(TaxSummaryEntry): Money $pick */
    private function sum(callable $pick): Money
    {
        $total = Money::zero($this->currency);
        foreach ($this->entries as $entry) {
            $total = $total->add($pick($entry));
        }

        return $total;
    }
}
