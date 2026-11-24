<?php

namespace Z77\Module\Financial\Repositories;

/**
 * Which coded journal lines one list of the VAT return shows — the entries of a form field,
 * narrowed to one display origin and one account (screen decision 9 of the review, 2026-11-24:
 * a standard list inside the return, its total = the figure of «Herkunft»). Built by
 * `VatReturn\VatReturnService::lineSearch()` — the service decides which code + rate + origin
 * belongs to a field; the repository only binds values
 * ({@see JournalLineRepository::vatReturnLines()} / `countVatReturnLines()` /
 * `vatReturnLineTotals()`).
 *
 * Every criterion is already PARSED and bound, never interpreted:
 *
 *   - `yearIds` / `from` / `to` — the settlement period (`Y-m-d`, inclusive) and the fiscal years
 *     it touches (the index `fiscal_year_id, entry_date` is used per year);
 *   - `selectors` — what the field holds, as code + rate + SOURCE TYPE (null = the manual entries)
 *     + the REVERSAL flags taken (0 = not part of a reversal pair, 1 = part of one): field 200
 *     takes a taxable code's non-reduction lines, field 235 its reductions — the service derives
 *     the selectors from the very groups the form was computed from, so the list holds exactly the
 *     lines of its node in «Herkunft». No selector = nothing matches;
 *   - `accountId` — one account, or every one;
 *   - the column searches of the list: `dayFrom` / `dayTo` (narrow the period, never widen it),
 *     `number` (the entry number, exact), `text` (contained in the entry or the line text);
 *   - `sort` — one of {@see SORTS}, mapped to an ORDER BY in the repository; `descending`.
 */
final class VatReturnLineSearch
{
    /** Columns the list can sort by — the key is the URL value, the ORDER BY is the repository's. */
    public const SORTS = ['date', 'number', 'account', 'text', 'base', 'tax'];

    /**
     * @param list<int>                                                 $yearIds
     * @param list<array{code: string, rate: int, source: ?string, reversal: list<int>}> $selectors
     */
    public function __construct(
        public readonly array $yearIds,
        public readonly string $from,
        public readonly string $to,
        public readonly array $selectors,
        public readonly ?int $accountId = null,
        public readonly ?string $dayFrom = null,
        public readonly ?string $dayTo = null,
        public readonly ?int $number = null,
        public readonly ?string $text = null,
        public readonly string $sort = 'date',
        public readonly bool $descending = false,
    ) {
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException("Unknown VAT return line sort '{$sort}'");
        }
        foreach ($selectors as $selector) {
            foreach ($selector['reversal'] as $flag) {
                if ($flag !== 0 && $flag !== 1) {
                    throw new \InvalidArgumentException('A reversal flag is 0 or 1');
                }
            }
        }
    }

    /** A copy with the list's column searches and sort (what `{X}Listing::search($state)` fills in). */
    public function narrowed(?string $dayFrom, ?string $dayTo, ?int $number, ?string $text, string $sort, bool $descending): self
    {
        return new self($this->yearIds, $this->from, $this->to, $this->selectors, $this->accountId, $dayFrom, $dayTo, $number, $text, $sort, $descending);
    }
}
