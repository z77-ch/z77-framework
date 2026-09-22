# z77/module-financial

Double-entry bookkeeping for z77 business modules (ADR-040, ADR-042, plan §5). It knows no
other module: every posting source will reach the journal through one door,
`LedgerService::post()`, with an opaque origin and an idempotency key.
Depends on `z77/kernel`, `z77/persistence-doctrine` (ADR-039) and `z77/module-vat`.
Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-financial`); not yet a split target or on Packagist — projects consume it
through a `path` repository until it is.

State: **P2 part 1** — the chart of accounts and the fiscal years with their periods. The
journal, manual entries and `LedgerService` (part 2) and the reports (part 3) follow.

Model:

- `Account` — number (digits, unique, fixed once created), name, one of five types (`asset`,
  `liability`, `equity`, `expense`, `revenue`), an optional parent GROUP, `postable`, `active`.
  A group (not postable) collects accounts and carries no postings. Deactivated, never deleted.
- `FiscalYear` — code (`2026`, `2026-27`), free start and end dates (at most 24 months,
  contiguous with the previous year) and one `Period` per calendar month, clipped to the year.
  Opening a year creates its journal-entry number range `journal-entry.{code}` in the same
  unit of work. Periods start `open` (`open` → `vat-settled` → `closed`, ADR-042).
- The Swiss SME chart (KMU-Kontenrahmen, Sterchi structure) ships as `res/charts/kmu.json`
  and is adopted by a button — only into an EMPTY chart.

Pieces:

- `Services/AccountService` — `save()`, `update($account, $values)` (validated on a draft),
  `setActive()`, `adoptKmuChart()`.
- `Services/FiscalYearService` — `proposeNext()`, `proposeCode()`, `open()`.
- `Validators/*` — the rules every writer is bound to.
- `Ui/AccountControllerTrait` + `Ui/FiscalYearControllerTrait` — the backend screens as
  fragments; `module-backend` mounts them at `/backend/finance/account/list` and
  `/backend/finance/fiscal-year/list`.

```php
$em = DI::getUnifiedEntityManager();

(new AccountService($em))->adoptKmuChart();            // empty chart only

$years = new FiscalYearService($em);
$year  = new FiscalYear('2026-27', new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2027-06-30'));
$years->open($year);   // twelve periods July … June, range journal-entry.2026-27 at 0
```

Docs: `docs/topics/financial.md` in the framework repository.
