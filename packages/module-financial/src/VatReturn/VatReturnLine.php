<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One coded journal line of the list «entries of a form field» (screen decision 9 of the review,
 * 2026-11-24). Immutable; read, never hydrated.
 *
 *   - `entryId`, `fiscalYear` (code) + `number` — the journal entry («2026/17», links to the
 *     journal's detail page); `date`; `text` the entry's text, `lineText` the line's own;
 *   - `accountId` / `accountNumber` / `accountName`; `taxCode`, `rate` (hundredths of a percent);
 *   - `base` / `tax` — the signed `tax_base` / `tax_amount` as posted;
 *   - `sourceType` / `sourceRef` — the entry's opaque origin, exactly what
 *     `Ledger\EntryDocuments::documentsFor()` takes ({@see documentQuery()}) to name the document
 *     behind it (invoice PDF, receipt photo, supplier PDF) for the CURRENT session — the screen asks
 *     it once per page, like the journal does;
 *   - `origin` — the calculator's origin of the line ({@see RowOrigin}).
 */
final class VatReturnLine
{
    public function __construct(
        public readonly int $lineId,
        public readonly int $entryId,
        public readonly string $fiscalYear,
        public readonly int $number,
        public readonly \DateTimeImmutable $date,
        public readonly string $text,
        public readonly ?string $lineText,
        public readonly int $accountId,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly string $taxCode,
        public readonly int $rate,
        public readonly Money $base,
        public readonly Money $tax,
        public readonly ?string $sourceType,
        public readonly ?string $sourceRef,
        public readonly RowOrigin $origin,
    ) {}

    /** «2026/17» — how a journal names an entry. */
    public function entryRef(): string
    {
        return $this->fiscalYear . '/' . $this->number;
    }

    /**
     * The shape `EntryDocuments::documentsFor()` takes, for a page of lines:
     * `$documents = EntryDocuments::fromModules($mm)->documentsFor(array_map(fn($l) => $l->documentQuery(), $lines));`
     *
     * @return array{id: int, sourceType: ?string, sourceRef: ?string}
     */
    public function documentQuery(): array
    {
        return ['id' => $this->entryId, 'sourceType' => $this->sourceType, 'sourceRef' => $this->sourceRef];
    }
}
