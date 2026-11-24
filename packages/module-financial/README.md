# z77/module-financial

Double-entry bookkeeping for z77 business modules (Swiss SME, base currency only): chart of
accounts, fiscal years with monthly periods, the journal with manual and module-generated
entries, the reports (trial balance, balance sheet, income statement, account statement,
journal) as screen and PDF, the year opening and the year close.

The module knows no other business module. Every posting source — invoices, payments, receipts,
the year opening — reaches the journal through **one door**, `LedgerService::post()`, with an
opaque origin and an idempotency key (ADR-040, ADR-042).

Part of the [z77 framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-financial`). Published on Packagist since 2.0.0:
`composer require z77/module-financial`.

## Requirements

- PHP ≥ 8.4, MariaDB (Doctrine, ADR-039)
- `z77/kernel`, `z77/persistence-doctrine`
- `z77/module-vat` — tax codes and rates; journal lines reference a code
- `z77/module-mandator` — the VAT, receivable and year-opening accounts live on the mandator record,
  as do the private and bank-transfer accounts that bank-driven bookkeeping uses

## Enabling it in a project

1. Require the package (and its dependencies) in the project `composer.json` and register the
   module in `autoload.psr-4` — the installer registers modules from the PROJECT's psr-4:

   ```json
   "Z77\\Module\\Financial\\": ["override/z77/module/financial/src/"]
   ```

2. `composer update` — registers the module, its template paths and its navigation seed.
3. `php vendor/bin/z77-db migrate` — creates the tables (`account`, `fiscal_year`,
   `fiscal_period`, `journal_entry`, `journal_line`, `journal_entry_change`,
   `fiscal_year_close_log`, and the bank tables `bank_account`, `bank_statement`, `bank_movement`,
   `bank_link`, `bank_movement_mark`, `bank_pattern`, `bank_posting`, `bank_anchor`).
4. In the backend: adopt the KMU chart (or build your own), fill in the mandator's accounts
   (Stammdaten › Firma › Mandant, tab «Konten» — for bank-driven bookkeeping also the private and
   the bank-transfer account), open the first fiscal year.

Optional settings in the project's `financialConfig` override: `journalListLimit` (page size of
the journal list, default 200) and `bankPosting.voucherFromGross` (from this gross amount a direct
bank posting with a tax code needs an attached voucher, default `'100.00'`).

## Backend screens

Logic and templates live here as controller traits (`Ui/*ControllerTrait`); `z77/module-backend`
mounts them with thin host controllers (ADR-018). Each list and form carries its help text
(`*.help.tpl.php`, ADR-048), which is also the knowledge of the backend assistant (ADR-055).

| Menu | URL | What it does |
|---|---|---|
| Finanzen › Journal | `/backend/finance/journal/list` | capture (one-line «Einzel» or multi-line «Sammel») above, the journal of the selected fiscal year below; edit / delete manual entries |
| Finanzen › Auswertungen | `/backend/finance/report/trial-balance` | Saldobilanz, Bilanz, Erfolgsrechnung, Kontoblatt, Journal — range, prior-year comparison, PDF |
| Finanzen › Änderungsprotokoll | `/backend/finance/change-log/list` | every edit and deletion of an entry, before / after (read-only) |
| Stammdaten › Finanzen › Geschäftsjahre | `/backend/finance/fiscal-year/list` | open (next or prior year), year opening, close, admin reopen, delete an unused year |
| Stammdaten › Finanzen › Kontenplan | `/backend/finance/account/list` | accounts and groups, adopt the KMU chart, deactivate (never delete) |
| Stammdaten › Finanzen › Bankkonten | `/backend/finance/bank-account/list` | which IBAN is which ledger account; the switch «Kontoauszug einlesen», the date «Abgleich ab» and the switch «gemischt geführt» (only a mixed account knows «privat»); deactivate (never delete) |
| Finanzen › Bankkonto | `/backend/finance/bank-movement/list` | camt.053 upload and the list «Bewegungen» of a bank account. A click on the upload opens a window with «Eingelesen bis» and «Laden ab» per account; a file must overlap the last stored day, else it is refused with the day to download from (ADR-060). From «Abgleich ab» every movement is «zu prüfen», «geschäftlich» or «privat» (ADR-061); «Privat» (mixed accounts only) remembers the merchant, «Geschäftlich buchen …» posts a movement by an explicit act, «Buchung zurücknehmen» reverses it. The window «Abgleich …» shows the anchor, the check per period and what blocks the close |

A base-knowledge help for the assistant, «Buchhaltung: Kontenplan, Journal, Geschäftsjahr,
Abschluss», lives in `res/view/templates/Backend/Guide/bookkeeping.help.tpl.php`.

## Model

- `Account` — number (digits, unique, immutable), name, type (`asset`, `liability`, `equity`,
  `expense`, `revenue`), optional parent group, `postable`, `active`, and two flags:
  «auf Belegen wählbar» (revenue accounts offered on invoice lines) and «nur durch Module
  bebuchbar» (collective accounts such as 1100 refuse manual entries). Deactivated, never deleted.
  The type locks once a line lies in a closed year.
- `FiscalYear` + `Period` — code (`2026`, `2026-27`), free start and end (≤ 24 months,
  contiguous), one period per calendar month. Periods are `open` → (`vat-settled`) → `closed`;
  only whole years are closed and reopened (`FiscalYearCloseService`, protocol
  `FiscalYearCloseLog`).
- `JournalEntry` + `JournalLine` — gapless number per year, date, text, kind (`generated` |
  `manual`), origin, idempotency key, `reversalOf`; lines with debit OR credit and, on the net
  line, tax code, rate snapshot, signed tax base and amount.
- `EntryChange` — the change log (before / after); it survives a deletion and documents the gap.
- `res/charts/kmu.json` — the Swiss SME chart (KMU-Kontenrahmen), adopted by a button into an
  EMPTY chart only.

## Posting from another module

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

`post()` and `reverse()` run INSIDE the caller's unit of work (they never commit); the number is
drawn as the first write after validation, so a refused or rolled-back posting consumes none.
A module corrects its own generated entry with `amend()` / `retract()` (same number, logged) or
`reverse()`; the journal screen never edits a generated entry.

A module that captures GROSS amounts (a receipt, a supplier invoice) does not split the VAT
itself — `GrossEntryBuilder` builds the lines, the same split as the one-line entry:

```php
$entry = GrossEntryBuilder::of($em)->build(
    new DateTimeImmutable('2026-11-10'), '1020',                     // date, counter account (Haben)
    [new GrossPart('6200', Money::fromDecimal('108.10', 'CHF'), 'VI', 'Benzin'),
     new GrossPart('4200', Money::fromDecimal('54.00', 'CHF'))],    // null code = «ohne MWST»
    $liableToVat,                                                     // false: every tax code refused
);
// $entry->lines: 6200 100.00 (VI 8.10) · 1171 8.10 · 4200 54.00 · 1020 162.10 in Haben
$ledger->post(PostingRequest::generated($date, $text, 'creditor', $id, "creditor:{$id}", $entry->lines));
```

Refusals are `GrossEntryRefusedException` (German message, the field, the part index); amounts in
the base currency only.

Extension points other modules use, without financial naming them:

- open-work checks of scope `period-close` (block or warn at the year close) and `ledger-report`
  (a notice above the reports) — `persistence-doctrine`'s `OpenWorkChecks` registry;
- `subledgerReconciliations` — a collective account compared with its subledger at the close;
- `bankEntryOwners` — modules that own journal entries on a bank's ledger account (the bank check
  asks them whether an entry has its movement elsewhere), `BankEntryOwnerInterface`;
- `bankMovementClaims` — a module may claim a bank movement as its own (e.g. a collective credit),
  so it is not decided or posted here, `BankMovementClaimInterface`.

## Not built yet

- **Posting the VAT return** (MWST-Abrechnung, plan P5 part 2). Since stage S1b the return can be
  READ and printed — Finanzen › MWST-Abrechnungen: the periods of a fiscal year, the ESTV form per
  period computed from the coded journal lines (effective method, agreed consideration), where each
  figure comes from, the reconciliation with the VAT accounts, a PDF. Not yet: the settlement entry,
  the lock of a settled period (`vat-settled` — the state and its posting rules exist, nothing sets
  it), corrections, reverse charge, the column «Ins ePortal». Not suitable, for example, for the
  net tax rate method, private shares of a sole proprietor, imports, foreign currencies — the full
  list is in the screen's help.
- **Bank-driven bookkeeping, remaining parts** (camt.053, ADR-060 / ADR-061): links, states,
  learned patterns, the direct posting, «Abgleich ab», the anchor, the check and the close blocks
  are built (stages C1–C4), and receipts find their movement (C5, on the receipt module's side). A
  movement is posted as a whole, never in parts. The year-end posting «Privat / Bank» is open
  (with the fiduciary). Topic doc:
  [`docs/topics/bank.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/bank.md),
  harness `tests/financial-bank.php`.
- Supplier invoices: `z77/module-creditor` (a separate package, not necessarily part of the first
  release) posts its creditor journal through `LedgerService` and is matched by the bank; without
  it, purchases are posted in the journal or through `z77/module-receipt`.
- Foreign currencies — base currency only (ADR-042 decision 4).
- Keyset paging for very large account statements (FIN-REPORT-005) and the `type_locked`
  marker (FIN-TYPE-001) — performance work, see the topic doc.

## Documentation

Topic doc: [`docs/topics/financial.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/financial.md)
— the single source of truth for this module (rules, known issues, pending work). Harness:
`tests/module-financial.php`.

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
