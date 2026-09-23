# z77/module-financial

Double-entry bookkeeping for z77 business modules (ADR-040, ADR-042, plan §5). It knows no
other module: every posting source reaches the journal through one door,
`LedgerService::post()`, with an opaque origin and an idempotency key.
Depends on `z77/kernel`, `z77/persistence-doctrine` (ADR-039) and `z77/module-vat`.
Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-financial`); not yet a split target or on Packagist — projects consume it
through a `path` repository until it is.

State: **P2 part 3** — chart of accounts, fiscal years with periods, the journal, `LedgerService`,
manual entries with a change log, and the reports (trial balance, balance sheet, income
statement, account statement, journal). The VAT return, the period transitions and the
year-end with the opening entry (P5) follow.

Model:

- `Account` — number (digits, unique, fixed once created), name, one of five types (`asset`,
  `liability`, `equity`, `expense`, `revenue`), an optional parent GROUP, `postable`, `active`.
  A group (not postable) collects accounts and carries no postings. Deactivated, never deleted.
- `FiscalYear` — code (`2026`, `2026-27`), free start and end dates (at most 24 months,
  contiguous with the previous year) and one `Period` per calendar month, clipped to the year.
  Opening a year creates its journal-entry number range `journal-entry.{code}` in the same
  unit of work; deleting one removes all three again — only the latest year, only while
  nothing was ever posted in it. Periods start `open` (`open` → `vat-settled` → `closed`, ADR-042).
- `JournalEntry` + `JournalLine` — one entry stored once: number (gapless per year), date, text,
  kind (`generated` | `manual`), opaque origin, idempotency key, `reversalOf` (at most once, in
  the schema), created/changed by/at; n ≥ 2 lines with account, debit OR credit, and — on the
  net line — tax code, rate snapshot, signed tax base and amount. Base currency only.
- `EntryChange` — the change log of manual entries: who, when, before/after snapshot; survives
  the entry's deletion and documents the number gap.
- The Swiss SME chart (KMU-Kontenrahmen, Sterchi structure) ships as `res/charts/kmu.json`
  and is adopted by a button — only into an EMPTY chart.

Pieces:

- `Services/LedgerService` — `post(PostingRequest): EntryRef` and
  `reverse(EntryRef, date, reason): EntryRef`, both INSIDE the caller's unit of work (never
  commits). Refuses: no fiscal year / period, `closed`, a tax line into `vat-settled`, an
  unknown / non-postable / inactive account, an unknown tax code, a repeated key with other
  content. Draws the number as the FIRST write, after validation. `accountExists($number)`
  validates a configured account (exists, postable, active); `vatAccountFor($category)` reads
  the tax account of a tax-code category from the MANDATOR record (`z77/module-mandator`, owner
  decision E2 of 2026-09-23 — before that `financialConfig → vatAccounts`, a key that is removed
  and refused loudly when a project override still carries it).
- `Services/ManualEntryService` — `create()`, `update($entryId, $expectedVersion, $newRequest): bool` (false = unchanged, nothing written), `delete($entryId, $expectedVersion)` of manual
  entries, each with an `EntryChange`; generated entries are refused in the domain.
- `Ledger/PostingRequest`, `Ledger/PostingLine`, `Ledger/EntryRef` — the immutable DTOs other
  modules see.
- `Services/AccountService`, `Services/FiscalYearService`, `Validators/*` — part 1.
- `Services/LedgerReports` — the reports, read-only: SQL aggregates over `journal_line`
  (`Repositories/JournalLineRepository`, Doctrine-only), sums turned into `Money` from their
  decimal strings, one fiscal year and a date range per report (`Reports/ReportRange`);
  balances positive on the account's natural side. No opening entry before P5 — a later
  year starts at zero.
- `Ui/*ControllerTrait` — the backend screens as fragments; `module-backend` mounts them at
  `/backend/finance/account/list`, `/backend/finance/fiscal-year/list`,
  `/backend/finance/journal/list` and `/backend/finance/report` (printable from the browser).
  A manual entry is captured ONE-LINE by default (`Ui/OneLineEntryForm`: Soll, Datum, Bu-Nr,
  Text, Haben, gross Betrag, optional MwSt row — the system splits the tax out and writes the
  tax line); real splits use the multi-line «Sammelbuchung» (`Ui/ManualEntryForm`).

```php
$em     = DI::getUnifiedEntityManager();
$ledger = new LedgerService($em);   // author = the logged-in backend user (or pass a name)

$em->getTransaction(JournalEntry::class)->run(function () use ($ledger): void {
    // … the module's own writes (an invoice, an open item) …
    $ref = $ledger->post(PostingRequest::generated(
        new DateTimeImmutable('2026-08-15'), 'Rechnung 2026-0042', 'invoice', '2026-0042', 'invoice:42:final',
        [
            PostingLine::debit('1100', Money::fromDecimal('108.10', 'CHF')),
            PostingLine::credit('3200', Money::fromDecimal('100.00', 'CHF'), 'Handelserlös', 'UN', 810,
                Money::fromDecimal('100.00', 'CHF'), Money::fromDecimal('8.10', 'CHF')),
            PostingLine::credit('2200', Money::fromDecimal('8.10', 'CHF')),
        ]
    ));   // EntryRef('2026', 12) — the same call again returns the same ref
});
```

Docs: `docs/topics/financial.md` in the framework repository.
