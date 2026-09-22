# Bauplan — order, debtor, financial, vat, contact, article

**Status:** `[CONCEPT]` → P0 closed 2026-09-21 (ADR-039 to ADR-043 approved). P1 closed 2026-09-21. P2 parts 1–3 built 2026-09-22 (part 3, the reports, awaiting commit after review); next the P2 exit check, then P3 debtor.
**Date:** 2026-09-18, updated 2026-09-21 (article model A1–A7 decided, Q7 answered, module cut and
build phases final, all questions answered, external review worked in; the persistence-access
question reopened ADR 2 on 2026-09-20 and was settled on 2026-09-21)
**Basis:** [`order-financial-review-2026-09-18.md`](order-financial-review-2026-09-18.md) — findings
in wdv-6.2.2 and decisions D1–D8 (§7 there). This plan does not repeat the wdv analysis.
**ADRs:** ADR-039 to ADR-043, approved 2026-09-21 (§10).

## Where we continue (as of 2026-09-22)

**What this plan builds:** order processing open to many sources, financial bookkeeping, receivables
management, article management. Nothing else. Subscriptions, shipping and a shop are applications on
top of it and are not in here (§2, §13).

Settled on 2026-09-20: the article model **A1–A7 and A-Pos** (§4b), **Q7** (§11), the **module cut**
(§2) and the **build phases** (§9). Decided against the live data of the installations rather than on
the whiteboard. Anything that identifies an installation or describes a client's business is **kept
out of this public repository** — the measurements live in the maintainer's local notes under
`docs/_local/` (gitignored, synced via NAS).

What is left before building:

**All open questions are answered** as of 2026-09-20 — Q5 (order status as data, no cancelled state),
Q6 (no foreign currency; the ledger is base-currency only), Q8 (MariaDB 10.6, one charset) and Q10
(stock valuation is judgement: a dated inventory list, posted by hand — no valuation method in code).

The **external review** has been held — brief:
[`order-bauplan-review-request-2026-09-20.md`](order-bauplan-review-request-2026-09-20.md), result and
what was accepted, narrowed or rejected:
[`order-bauplan-review-result-2026-09-20.md`](order-bauplan-review-result-2026-09-20.md). Its findings
are worked in: payment state is no longer an order status but asked through a port (§7);
the invoice line gets type and parent line in P3 (§6.2); every product has at least one variant
(A3); attributes move to the shop concept because nothing here consumes them (§4b); `NumberRange`
and the open-work check live once in `persistence-doctrine` (§2); the two rounding accounts are named
apart (§5.1, §6.1); statuses are installation data in `data/`, not code under `override/` (§7); the
import is named as the one sanctioned second write path (§8, ADR 1); the developer's own migration
moves forward to P5b and stock behind order to P7b (§9); stock lives in its own tables (§4b).

The last open point from the review — how a stock movement is derived from a status change — is
decided as well (§4b): against the movement journal rather than the previous status, so flags stay
editable, a repeated call does nothing, and a reversal always carries a reason.

The persistence-access question is settled as well (below): unified API, two drivers, minimal
transaction port.

**P1 closed (2026-09-21, end of session).** Done: `Money` in the kernel (`shared/src/Money`,
`tests/money.php`, topic `money.md`); local MariaDB 10.6.28 for the driver tests (maintainer runbook).
`z77/persistence-doctrine` is built in three parts, each with its own commit and tests against
MariaDB. **Part (1) done** — package, `Bootstrap`, `DoctrineEntityManager`, `DoctrineRepository`,
`MoneyType` (base currency from `systemConfig → baseCurrency`, owner-confirmed), driver map,
`config/client/database.inc.php`, `doctrineEntities` plus the additive override file
`doctrineEntitiesConfig.inc.php`; topic [`persistence-doctrine.md`](../topics/persistence-doctrine.md).
**Part (2) done** — transaction port (`UnifiedEntityManager::getTransaction()`, nesting joins without
savepoints, rollback-only, connection reset after a failed rollback), `NumberRange` (bare integer,
ranges created ahead via `create()` — P2 calls it at fiscal-year opening) and the open-work registry
(`openWorkChecks`, additive override file). **Part (3) done** — Doctrine caches under the
release-local `var/cache/doctrine/` (in-memory in DEBUG), cleared by «Cache leeren», the DEBUG toggle
and `migrate`; migrations CLI `vendor/bin/z77-db` (`migrate`, `status`, `diff`, `generate`), table
`schema_migration`, timestamp order across modules; first package migration `number_range`.
`persistence-doctrine` is complete. **`module-vat` done** (file-based tax codes with dated rates,
`VatRates` lookup, `VatCalculator` on `Money`, backend `/backend/finance/tax-code`, CH seed from 2018;
ESTV mapping deferred to P5, snapshot serialisation to P3 — topic [`vat.md`](../topics/vat.md)).
**`module-contact` done** (Doctrine contact with n typed addresses, file-based `AddressType`, first
module migration, backend `/backend/contact/…`; `z77-db migrate` on `next` is in the deploy
checklist — topic [`contact.md`](../topics/contact.md)). **P1 is complete** (exit criteria met).
**P2 part 1 done (2026-09-22)** — `module-financial` package with the chart of accounts and the
fiscal years/periods (topic [`financial.md`](../topics/financial.md), `tests/module-financial.php`,
the module's first migration `Version20260922071232`, backend `/backend/finance/account` and
`/backend/finance/fiscal-year`). Owner decisions of 2026-09-22: (1) **periods are calendar months**,
derived when a year is opened and clipped to its bounds; (2) the **KMU chart comes by button** —
«KMU-Kontenrahmen übernehmen» only while the chart is empty, not as installer seed or migration,
so a migrated wdv chart (P5b) never gets it forced on it; (3) **five account types** — `equity`
separate from `liability`, so the balance sheet splits equity by type without a number rule;
(4) **free fiscal-year dates** (deviating, shortened, extended years; at most 24 months,
contiguous). Follow-on decision (orchestrator): a fiscal year carries a **`code`** (`2026`,
`2026-27`, proposed from the dates, immutable) and its range is `journal-entry.{code}` — two years
can start in the same calendar year, so the start year cannot name the range. Opening a year creates
that range at 0 through `NumberRange::create()` in the same unit of work (DOCTRINE-NR-003 resolved).
**P2 part 2 done (2026-09-22)** — the journal (`JournalEntry` / `JournalLine`, migration
`Version20260922091711`), `LedgerService::post()` / `reverse()` as the one write path (§1, §5.4),
manual entries with change log (`EntryChange`, `ManualEntryService`), the `Ledger/` DTOs other
modules see (`PostingRequest`, `PostingLine`, `EntryRef`), backend `/backend/finance/journal`
(list per fiscal year, detail with reversal link and change log, manual entry form without
JavaScript, edit/delete with confirmation). Harness: `tests/module-financial.php`, 180 checks,
including three parallel posters with rollbacks staying gapless. Decisions taken on the way, all
recorded in [`financial.md`](../topics/financial.md): a repeated idempotency key with DIFFERENT
content is refused loudly (`IdempotencyConflictException`), content = date, text, origin, lines;
the reversal's reason is the reversal entry's TEXT, its origin is inherited from the reversed
entry, its tax base/amount are negated; `taxRate` is snapshotted on the line next to ADR-041's
three fields (§5.6 groups by code + rate — an ADDITION to ADR-041 decision 7, not a contradiction;
the label is not snapshotted); tax codes are validated for EXISTENCE at posting (a deactivated code
still posts from a snapshotted document), the manual form offers active codes only; the author is
the `AuthUser` name (backend user or `cron:{job}`), a CLI caller names the actor explicitly;
`accountExists()` of §5.4 is NOT built (no production caller yet — arrives with debtor's account
settings in P3). Independent review worked in the same day: **optimistic locking** on manual
entries (`journal_entry.version`, `#[ORM\Version]`; the services take id + version, re-read under
a row lock, refuse a stale write with `EntryConflictException` — two parallel edits, a stale delete
and a stale edit after a delete are harness cases); `reverse()` may post to a now-inactive account
and a manual edit may keep an unchanged line on one (ADR-043/19 applied to accounts); the race
contract of `post()`/`reverse()` (unique-index failure at commit, no number consumed, no retry
inside the unit of work) is a rule binding the P3 debtor adapter; a reversal may be dated in the
next fiscal year. Two owner questions stay open: locking `type` / `postable` once postings exist
(FIN-TYPE-001) and deleting a wrongly opened fiscal year (FIN-FY-002).
**P2 part 3 built (2026-09-22, not yet reviewed / committed)** — the reports (§5.5): trial
balance, balance sheet, income statement, account statement (Kontoblatt) and journal, as SQL
aggregates over `journal_line` on the EntityManager's own connection
(`JournalLineRepository`, Doctrine-only, every value bound), turned into `Money` from the
decimal strings (`LedgerReports`), one fiscal year and a from–to range inside it per report;
backend `/backend/finance/report/…` (one page each, tab row, GET parameters, month shortcuts,
paging, printable from the browser through a new `@media print` block of the shell — no
JavaScript). Decisions taken on the way, recorded in [`financial.md`](../topics/financial.md):
balances positive on the account's natural side (the trial balance splits Saldo Soll / Haben
instead); the balance sheet is a statement AT «to» from the year's first day, with the current
result as a line in equity; the type decides the block, the parent chain the grouping (a mixed
KMU group appears on both sides); account statement 500 lines and journal 200 entries per page,
the running balance as a window function over the whole range. **No opening entry before P5**:
every report reads one fiscal year, so a later year shows no carried-forward balances
(FIN-REPORT-001 — owner: derived from the previous year, re-derivable until the close; no
cross-year workaround). No export (CSV/PDF) yet. At 20'000 lines every report takes ≈ 0.1 s with
the existing indexes — no new migration. Harness: `tests/module-financial.php`, 265 checks (review findings of the same day worked in).
**P2 parts 1–3 are built. Next: the P2 exit check «manual bookkeeping usable»** (a live pass in
a project installation: open a year, post, edit, delete, read and print every report — see
`financial.md` pending), **then P3 debtor**.
**FIN-FY-002 resolved (owner, 2026-09-22):** a wrongly opened fiscal year is deleted in the backend while it is the latest and nothing was ever posted in it — year, periods and range in one unit of work (`FiscalYearService::delete()`, `NumberRangeRepository::dropUnused()`); FIN-TYPE-001 stays open.
Framework-wide pending found on the way: module config override replaces instead of merging
(BOOT-CONFIG-001 in `bootstrap.md`).

Open for the owner: `persistence-doctrine`, `module-vat` and `module-contact` are not split targets
yet (`.github/workflows/split.yml`, Packagist). Working method that carried P1: each building block
built by one agent, reviewed independently by a second against the ADRs (with probes against
MariaDB), findings fixed before the commit; owner decisions recorded in the topic docs.

### Settled before P0 — how the business modules reach persistence

Raised 2026-09-20 in the evening, deliberately **not** decided that day. Decided on 2026-09-21.

ADR 2 as drafted in §10 deviates from
[`persistence-architecture.md`](../topics/persistence-architecture.md), which promises **one**
consumer API — `UnifiedEntityManager::getRepository()` returning a `RepositoryInterface` — for every
backend. The developer's position (2026-09-20): that document is correct and was well considered, so
the plan bends to it rather than the other way round. **Confirmed 2026-09-21.**

The question splits in two, and this plan had conflated them:

- **Where the driver lives** — kernel `persistence/src/Doctrine/` (as the driver contract in the
  topic doc spells out) or the own package `z77/persistence-doctrine` (§2). **No real conflict
  here:** §2 already keeps the namespace `Z77\Persistence\Doctrine` so that `bootManager()`'s
  convention holds, and the topic doc names a *path*, not a semantic. The reason for the separate
  package has nothing to do with persistence — it is ADR-001: the kernel carries no Composer
  dependencies, and Doctrine brings about fifteen.
- **How a module reads and writes** — through `UnifiedEntityManager`, or bound to Doctrine's
  EntityManager directly. **This is the actual decision.**

What the discussion established, so it is not re-derived tomorrow:

- The DBAL SQL reports of §5.5 are **not** an argument against the unified API. The topic doc's
  rules already cover them: a complex query needing DQL or QueryBuilder is implemented as a
  driver-specific method *outside* the interface, with the deviation documented.
- For the unified API: **the mixed case is the normal case here.** `module-debtor` holds
  `Invoice`, `OpenItem` and `Payment` in Doctrine and `PaymentTerms`, `DunningLevel` and
  `PaymentTarget` as files — same module, often the same service. One access path keeps that code
  uniform and keeps the backend a property of `#[Entity]` instead of a property of the calling
  code. Convention discovery (`\Entities\X` → `\Repositories\XRepository`) and the Memory driver
  (tests without a database) stay available with it.
- Against it, and the point the decision actually turns on: **transactions.** ARCH-A003 in the
  topic doc states that they cannot be abstracted through `RepositoryInterface`, and that is
  correct. This plan needs them in four places — §5.4 (`LedgerService` never commits, the caller
  owns the transaction), §6.2 (`invoice()` and `finalize()`, one transaction each) and §4b (status
  change and stock movement in one). Keeping the unified API therefore requires a **transaction
  port** that only the Doctrine driver fulfils and the File driver honestly refuses. That is an
  *extension* of the architecture, not a contradiction of it — but it has to be designed, and
  ARCH-A003 then needs rewording: not "no transactions", but "no transaction in the shared
  interface".
- Two smaller ones ride along: the row lock for `NumberRange` (`SELECT … FOR UPDATE`) is a second
  driver-specific spot and falls under the same deviation rule; and ARCH-A002 ("z77 does not
  implement an Identity Map") stops being true the moment the Doctrine branch is alive.

**Decided 2026-09-21:**

- **Unified API confirmed.** Business modules read and write through
  `UnifiedEntityManager::getRepository()`; a simple order query looks the same on every backend.
- **Two drivers, not three.** A separate SQL connection for the ledger reports (balance sheet,
  income statement) was considered and **rejected**: Doctrine ORM already runs on DBAL, so the report
  SQL goes through `$em->getConnection()` of the same Doctrine driver. A second connection would
  duplicate the credentials (Rule 2) and would not see uncommitted writes of the first one. Report
  methods live in the repository, outside `RepositoryInterface`, documented as Doctrine-specific.
- **wdv `EntityManager::transactional($persister)` is not a model.** It was never called in wdv; its
  retry loop (`sleep(1)`, five attempts) treated a symptom — a batch that was triggered twice. The fix
  for a double trigger is idempotency (§4b), not a retry.

- **Transaction port: minimal.** Doctrine's `flush()` already writes in one transaction; an explicit
  transaction is needed only where DBAL SQL and ORM writes must be atomic together — concretely the
  `NumberRange` row lock (`SELECT … FOR UPDATE` holds only inside an open transaction). Only the
  Doctrine driver fulfils the port; the File driver refuses it. No retry loop, no persister class.

Consequences: ADR 2 changes its statement, §2 and
§5.4/§6.2 are pulled along, and `persistence-architecture.md` is **extended** by the transaction
port rather than corrected.

Background analyses of wdv (order domain, order↔financial/VAT coupling, article catalog/shop) are
condensed in the review document and in §4b; nothing else needs to be re-read.

Goal: rebuild order processing and double-entry bookkeeping from wdv-6.2.2 as z77 modules —
knowledge and test cases from wdv and from the developer's 25 years of practice, no wdv code.
Guiding rule: **build it right once, nothing twice, nothing in stock.**

---

## 1. Owner decisions (2026-09-18)

| # | Topic | Decision |
|---|---|---|
| D1 | Placement | Framework monorepo, MIT, public. Industry-neutral modules. |
| D2 | Doctrine usage | ORM 3 for writes, DBAL SQL for reports; PHP attributes; no Gedmo; schema only through migrations. |
| D3 | Doctrine placement | Own package `z77/persistence-doctrine`, not in the kernel. **Doctrine where needed** (transactions, volume, SQL reports) — everything else file-based entities, also inside these modules. |
| D4 | Tax codes | Own module `module-vat`; rates are data managed in the backend with "valid from"; file-based; CH seed. |
| D5 | VAT methods | Build **effective + agreed** only. Net tax rate and received consideration not built, model must carry them unchanged. **Discount and bad-debt loss with VAT correction from day one.** |
| D6 | Tenancy | One installation = one company = one database. No tenant column in stock. |
| D7 | Migration | 4 accounting clients + the developer's own books (first). **Full history migrated once**, reconciled per year and account to the Rappen. |
| D8 | Module cut | Four modules: `order`, `debtor` (owns invoicing), `financial`, `vat`. order posts nothing. financial accepts postings from **any** source. |
| — | Entry mutability | *Generated* entries (posted by a module) are never editable/deletable — correction by reversal. *Manual* entries in financial are editable/deletable **until the accounting close** of their period. |
| — | Money | Integer minor units (Rappen) everywhere in PHP. Never `float`. |
| — | VAT scope | European model from day one; only country pack `CH` is built. |
| — | One write path (2026-09-20) | **Every stock quantity has exactly one service allowed to change it, and every change is journalled.** For the ledger that service is `LedgerService::post()` (§5.4), for stock the service in `module-article` (§4b). Neither knows its callers; a trigger arrives as an opaque reference. A quantity that many places may write is a quantity nobody can explain. |
| — | Correction principle (2026-09-20) | **Nothing that has been posted or consumed is corrected by deletion or by a special state — always by a counterpart of the same shape.** (Sharpened after the review: stated absolutely the rule was decorative, because it already had three carve-outs — manual entries until the close, `reinvoice` while in `invoicing`, and stock correction with a reason. Bounded to *posted or consumed*, it holds.) A reversal against a journal entry, a credit note against an invoice, an order with negative quantities against an order. This is why there is no cancelled order status (§7) and why generated entries are immutable (below): the counterpart records *what happened*, a deleted row or a `cancelled` flag records only that something did not. |

---

## 2. Packages and dependency direction

| Package | Namespace | Depends on |
|---|---|---|
| `z77/kernel` (existing) | `Z77\Core`, `Z77\Shared`, `Z77\Persistence` | — (no Composer deps, stays so) |
| `z77/persistence-doctrine` (new) | `Z77\Persistence\Doctrine` | kernel, doctrine/orm 3, doctrine/dbal 4, doctrine/migrations |

Two building blocks live **once** in `persistence-doctrine` rather than three times in the modules
(Rule 8, review 2026-09-20): **`NumberRange`** — gapless numbering needs a row lock, and financial,
debtor and order all need it — and an **"open work check"** registry, the mechanism behind both the
period-close check (§5.3) and the stocktake block (§4b): a module registers a check, the caller asks
"anything open?", each finding is blocking or a warning.
| `z77/module-vat` (new) | `Z77\Module\Vat` | kernel |
| `z77/module-contact` (new) | `Z77\Module\Contact` | kernel, persistence-doctrine |
| `z77/module-article` (new, decided §4b) | `Z77\Module\Article` | kernel, persistence-doctrine, module-vat |
| `z77/module-financial` (new) | `Z77\Module\Financial` | kernel, persistence-doctrine, module-vat |
| `z77/module-debtor` (new) | `Z77\Module\Debtor` | kernel, persistence-doctrine, module-vat, module-contact; `suggest` module-financial |
| `z77/module-order` (new) | `Z77\Module\Order` | kernel, persistence-doctrine, module-vat, module-contact, module-article, module-debtor |

**These eight are the plan.** What order processing, bookkeeping, receivables and article management
need, and nothing else.

Named but **outside this plan**, to be built later as their own modules on top of the seam in §7
(§13): **subscriptions** (turnus, cycles, customer preferences — Q7), **shipping and delivery
zones**, and a **shop**. None of them is a prerequisite for the eight above; each is an application
on an open `module-order`, and each gets its concept when it gets built. Designing them now would
shape the framework around one client's application — which the guiding rule forbids.

```text
module-order ──→ module-debtor ··→ module-financial      ··→ = through a port, suggest only
      │                │                  │
      ├────────────────┴──→ module-vat ←──┘
      └──→ module-contact ←──┘  (debtor → contact as well)
                 all ──→ persistence-doctrine ──→ kernel
```

- **financial knows no module.** debtor knows no order. Sources appear only as opaque references.
- `persistence-doctrine` uses the namespace `Z77\Persistence\Doctrine` so that
  `UnifiedEntityManager::bootManager()` keeps its convention
  (`Z77\Persistence\{Driver}\Bootstrap`). The empty `packages/kernel/persistence/src/Doctrine/`
  stub is removed from the kernel.
- Every module follows ADR-019 (by domain, inside by layer: `Entities/`, `Repositories/`,
  `Services/`, `Validators/`) and ADR-005/022 for its backend UI (groups, view areas, nav slots).

---

## 3. Shared primitive: `Money`

Domain-less, needed by all four modules → **kernel `shared`** (ADR-019 Rule 2), pure PHP.

- `Money` = `int $minor` + `string $currency` (ISO 4217). Immutable. `add`, `subtract`,
  `multiply(int|string $factor)`, `allocate(ratios)` (split without losing a Rappen),
  `roundTo(int $step)` (1 = 0.01, 5 = 0.05), comparison. No `float` in the API — a float argument is
  a `TypeError`.
- Rates and percentages as integers in hundredths of a percent (8.1 % = `810`). Tax =
  `round_half_up(base × rate / 10000)` in integer arithmetic.
- DB: amounts `DECIMAL(15,2)` (readable, exact `SUM()` in SQL), mapped by a Doctrine custom type
  in `persistence-doctrine` that parses the decimal **string** into minor units — never through
  float. File-based entities store minor units as integers.
- Tests: the wdv float bugs (strict float compares, unrounded VAT, rounding smeared onto the last
  rate) become test cases.

---

## 4. module-vat

**Storage: file-based** (a few dozen rows).

| Entity | Fields (sketch) |
|---|---|
| `TaxCode` | `code` (e.g. `UN`, `UR`, `US`, `UE`, `UA`, `VM`, `VI`), `country` (`CH`), `category` (standard, reduced, special, zero, exempt, reverse-charge, input-material, input-other), `label`, `active` |
| `TaxRate` | `code`, `validFrom` (date), `rate` (int, 1/100 %) — a rate change is a new row; old rows stay |

- **Backend**: list of codes with their rates; "new rate valid from" is a new row. Seeded on first
  install with the Swiss codes and rates since 2018 (ADR-024), so historical documents resolve.
- **`VatCalculator`** (plain PHP): input = lines (net or gross amount, tax code), service date,
  price mode (net/gross); output = per line the resolved rate, and a **tax summary** per code
  (base, rate, tax). Rule: Σ line amounts per code → tax per code rounded to 0.01. Gross mode:
  tax = gross × rate / (10000 + rate). Rounding of the total to 0.05 is **not** done here — it is a
  document concern (debtor).
- **Country pack `CH`**: maps tax code (+ rate) to ESTV form fields; used only by financial's VAT
  return. Other countries later, as new packs, without changing the model.
- Rate resolution: by **service date** (legally correct; kept from wdv).

---

## 4a. module-contact

Decided 2026-09-18 (Q1). The same party is needed by order (delivery address), debtor (invoice
address), a later creditor (supplier) and possibly a newsletter tool — so contacts are their own
module, not part of debtor. There is no "order customer" or "debtor customer": there is one
**contact**, and each module takes the address it needs.

| Entity | Storage | Fields (sketch) |
|---|---|---|
| `Contact` | Doctrine | person or company, name parts, language, e-mail/phone, active (`memberAccountId` removed 2026-09-21, owner — added back with the first consumer, customer portal/shop) |
| `Address` | Doctrine | salutation, title, first name, name, address row, street, house no, zip, city, country |
| `ContactAddress` | Doctrine | contact ↔ address with **type** and title — n addresses per contact (wdv `Addressing`) |
| `AddressType` | file | main, invoice, delivery, regional, … — managed data, like wdv `AddressType` |

- Module-specific data stays in its module, keyed by contact id: payment terms and dunning block in
  debtor, order defaults in order. contact knows none of them.
- Every document (quote, order, invoice, dunning notice) stores the address it used as a **snapshot**;
  later address changes never alter an issued document.
- Newsletter: see Q9.

## 4b. module-article — decided 2026-09-20

Articles have **two consumers**: order (lines from articles) and a web shop (display with price,
VAT, text, images; basket; checkout). Both consumers exist in practice → articles are their own
module, not part of order. A shop module itself is a later step; the article module is designed for
both consumers now.

### Data basis

These decisions were not taken on the whiteboard. They were checked against the live data of the
installations this framework has to carry — read-only, aggregated, 2026-09-20. **Everything that
identifies an installation or describes a client's business stays out of this public repository**;
the measurements and the client mapping are in the maintainer's local notes
(`docs/_local/order-article-installation-data.md`, gitignored, synced via NAS).

What matters here is the **span** the module has to survive, not who sits at which end of it:

- from a master of **two articles** (membership fees, no shop, no stock, no variants) to a
  multi-level retail range with a web shop;
- from **no order positions at all** to a position count that only SQL reporting can aggregate;
- from **pure services**, where the article is a tariff and the text is written per position, to
  **pure retail**, where the article *is* the product.

The small end is the sharper test. A model that gets in the way at two articles is wrong, however
well it serves a shop.

### What wdv does today

These are properties of **wdv**, not of any installation — which is why they are here and the
measurements are not.

**One product tree with four fixed levels, and the levels carry whatever the installation needs.**
In a retail setting they end up as product kind, country of origin and growing region; in a service
setting as product group, business area and kind of service. One of the four levels is dead
everywhere. So the same scaffold carries a classification on one level and attributes on the others,
and because the levels are fixed, things slip: a region ends up on the country level, a service
ends up as a country, a customer-specific tariff ends up sitting in the product tree as if it were a
product group. What does not fit the schema lands somewhere.

**The article number is derived from the tree path.** A running number inside a group path collides
as soon as the same product appears twice in that slot, and the practice patches it by appending
letter suffixes to the running number; the same group path also shows up once with and once without a
leading zero. A number that encodes a placement survives neither a reclassification nor a second
entry in the same slot — and it is printed on ten-year-old invoices.

**wdv knows no variants, so a recurring change of the same product becomes a new article.** In wine
retail that is the vintage: title and slug carry the year, so every new vintage is a new article with
a new URL, and the previous one is archived rather than corrected. The search rank the old URL
accumulated starts again at zero, and the archived URLs stay dead. Slugs also use underscores, which
search engines read as a word joiner rather than a separator — against Rule 6 anyway.

**Attributes exist only as free text, so they cannot be filtered on.** A grape variety column (a
client override, correctly not in the kernel) accumulates several spellings of the same grape:
differing capitalisation, and the same grape once with and once without a percentage prefix. Values
that name several grapes are normal, because a cuvée is normal — which neither a single tree
placement nor a single text field can express. The producer is free text as well, so there is no
producer record and no producer page, although in wine retail the producer is the selling argument.
Units go the same way: noticeably more master records than there are real quantities, the same
quantity spelled several ways and entered more than once.

**Dead weight that will not be rebuilt:** a numeric field that is never filled, two of three
description fields that are never used, and a future-price mechanism that is effectively unused
everywhere.

**What wdv gets right and we keep:** `shop_positions` holds a real snapshot — code, title, supplier,
price, tax code, description, unit — with the article id only as a reference. An issued document does
not change when its article changes. That pattern moves into `module-debtor` unchanged (§6.2).

**In a service setting the article master is something else entirely.** Every position references an
article, yet the article count is tiny while nearly every position carries its own title: the article
supplies tariff, unit, revenue account and tax code, and the text is written per position. Stock is
switched off, images are irrelevant. The position units include one meaning "lump sum, no quantity"
and one meaning "text line, no quantity and no price", and a small set of articles exists **only
because wdv has no text position type**. None of this is visible from the shop case.

### Decisions

| # | Decision (2026-09-20) |
|---|---|
| **A1** | **One product-group tree of free depth, exactly one placement per article.** It carries revenue account, VAT default, discount group and turnover statistics — it is the accounting classification, not the customer navigation. **No attributes in the tree:** country, region, grape and colour become attributes, promotions become curated lists, customer-specific tariffs belong to the price/discount axis. Free depth, because the depth legitimately differs per installation: one level where a single product kind is sold, two or three where business areas have to be reported separately. |
| **A2** | **Article number free or from a number range, carried by the variant, never derived from the tree path.** The number is printed on ten-year-old invoices; if it hangs on the placement, nothing can ever be reclassified. This also ends the suffix patching. |
| **A3** | **Two-stage article: product + variant.** Product = the marketing unit (one page, one URL, text, images, producer). Variant (SKU) = the stock, price and invoicing unit (own article number, own purchase and sales price, own stock). Vintage and size are variants. **Every product has at least one variant** — a default variant is created implicitly and the second stage stays hidden in the UI while it is the only one (review 2026-09-20). So article number, price, stock and every document reference always sit on the variant: "no second stage" is a UI fact, never a schema fact. Without this rule a line would have to reference product *or* variant, and that nullable dual reference is exactly the cost this decision is accused of. |
| **A4** | **Price per variant with "valid from" and history**, net/gross declared per price. An offer price stays a regular feature (it is in use, but in wdv it is a client extension that core code nonetheless calls). The wdv future-price mechanism is dropped — it is effectively unused. |
| **A-Pos** | **Position types belong in the model:** `service` / `lump sum` / `text` (and `subtotal` later), and the position text is editable from the start. Otherwise the text-only pseudo-articles come back. This requirement is invisible in the shop case and shows up only in the service case. |

What this plan builds from A1–A4: the **product group tree** (one placement, carrying revenue
account, VAT default and turnover statistics), **product + variant**, **prices with history**, the
**article number on the variant**, and stock (§4b below). That is what an order line needs.

**What A1 implies but this plan deliberately does not build** — the review of 2026-09-20 pointed out
that none of it has a consumer here, and a shop is out of scope (§13), so building it now would be
exactly the "in stock" the guiding rule forbids. It belongs to the shop module's concept:

- **Typed attributes per product group** (grape and vintage for wine, pressing for oil) with
  **controlled values** and multi-valued where reality is multi-valued (a cuvée has several grapes).
  When it is built: **no EAV**, no runtime meta-model, values in one narrow indexed table — Magento's
  EAV is the warning, not the example. Adding this later is a new table, not a change to an existing
  one.
- **Facets** — which attributes appear as filters and in which order is a shop setting, never an
  article property; otherwise every order-only installation drags the logic along.
- **The producer as a `Contact` in a supplier role** (§4a) rather than free text — right, but nothing
  in order, debtor or financial reads it.
- **Flat, stable product URLs** and the redirect list for the existing ones — a shop concern, and the
  migration of the value lists rides along with it.
- **Product URL flat and stable** — without vintage, region, producer or size, kebab-case (Rule 6):
  `/<group>/<product>`. A list URL may still *read* hierarchically, `/<group>/<attr>/<attr>`, while
  being a filter expression rather than a tree node — which is why `/<group>/<other-attr>` then works
  just as well, in any order. **Every existing product URL needs a 301 redirect** at cut-over, or the
  accumulated search rank is lost. That is a migration step, not a footnote.

### Migration effort that follows

Not one decision per article but **four value lists plus one redirect list**: grape spellings down
to the real grapes, supplier texts into contacts, units down to the real quantities, and country and
region out of the group levels including the rows that slipped onto the wrong level. The migration
proposes each list, the developer confirms or corrects **the list**, the articles are then mapped
automatically, and only the outliers land in a remainder list.

### A5–A7, decided 2026-09-20

| # | Decision |
|---|---|
| **A5** images | **Several per product through `module-dms`**, optionally one per variant. No installation uses more than one image today — but that is a wdv limit, not a measured lack of need, and dms already exists, so the reuse is cheap (Rule 8). |
| **A6** discount groups | **Not an axis of their own.** Measured: where the axis exists at all, nearly every article sits in the same group and a mere handful anywhere else; elsewhere the table does not exist. A customer discount belongs to the price side (customer price / contract), and if a group-wide rule is ever needed it derives from the product group (A1). |
| **A7** stock | **Yes, now:** balance and reservation on the variant, plus the flag whether an article is stock-relevant at all. No storage locations, no batches. Retail uses stock, services switch it off — hence the flag. **One write path and a movement journal** — see below; that is the part which decides whether balances stay trustworthy. |
| **A7** part lists | **Later.** A set of variants **with quantity** (wdv has no quantity, which is why its part lists are unusable). Barely used anywhere today — the model must allow it, nothing gets built. |

Two smaller ones fall out with them: **free-text tags** become attributes (A1) or a curated list,
they do not survive as free text; and an **hourly and a quarter-hourly tariff are one tariff at two
resolutions** — one article with a fractional quantity, since fractional quantities are already in
daily use.

### Stock: one write path (A7)

Stock balances go wrong in wdv because many places may change them and nothing records how a balance
came about. Both are fixed by the same two rules.

**One service owns the balance, and nothing else may write it.** No controller, no repository, no
import, no backend form and no subscription run touches balance or reservation directly. The service
lives in `module-article` for now — nothing is built on stock, so nothing earns its own package yet —
it knows no caller, and the trigger reaches it as an **opaque reference**, exactly the pattern
`LedgerService::post()` uses in financial (§5.4). One quantity, one write path, no exceptions.

**Balance, reservation and journal live in their own tables, keyed by variant id** — not as columns on
the variant (review 2026-09-20). Then extracting a `module-stock` later is a namespace move inside the
same database and the same monorepo, in either direction, and it costs one small table set instead of
two columns.

**Stock never posts** (Q10): the valuation of inventory is a matter of judgement and is entered as a
manual journal entry by whoever keeps the books. Stock therefore needs **no dependency on financial**
and no gateway of its own — it owes the books one report instead: **the balance per variant for a
given date**, which the movement journal answers by definition. This also settles the review's
assumption that Q10 would eventually force stock to talk to financial; it does not.

**The order status is the control table.** A stock movement is triggered by a status change and by
nothing else, and what it books follows from the status flags (`reservesStock`, `consumesStock`) —
never from a special case per status name. From "ordered" (reserves) to "delivered" (consumes) means:
release the reservation, book the balance down. That calculation exists **once**. Status change and
stock movement commit in **one transaction**, or the balance drifts away from the status; and the same
change applied twice must not book twice — the same idempotency discipline as posting to the ledger.

**The movement is derived against the journal, never against the previous status** (decided
2026-09-20 after the review). The journal holds, per **order line**, how much is currently reserved
and how much is consumed. A status change works like this:

1. order reads the target status' flags and turns them into target quantities for the line —
   `reservesStock` means "reserved = line quantity", `consumesStock` means "consumed = line
   quantity", neither means zero. A negative line quantity therefore yields an inflow, which is how
   a return works (§7).
2. order calls the stock service: *bring this line to reserved = x, consumed = y.* It passes
   **quantities, not a status** — which is why `module-article` never needs to know `OrderStatus` and
   the dependency direction of §2 holds.
3. the service compares with what the journal says and books only the difference.

This is what makes the mechanism hold up in practice:

- **Flags stay editable.** Change `reservesStock` on a status that live orders sit in, and the next
  transition still releases their reservations correctly — the journal knows what was actually
  reserved, and no setting can rewrite that. An earlier version compared the target with the *old
  status' flags*, which meant an edited flag silently corrupted the journal for good. New statuses
  and new flag combinations can therefore be introduced at any time, which is the whole point of
  status being data (§7).
- **A repeated call does nothing.** Clicking "delivered" twice, or reloading the page, produces the
  same target — no difference, no movement. Idempotency is structural rather than a guard someone has
  to remember.
- **A booking is never reversed silently.** If a status change would bring consumed stock back into
  the balance — a mistaken click on "delivered", or a move to "done without stock effect" on an order
  that has already been delivered — the service **demands a reason and a confirmation**, and the
  journal then shows both movements with that reason. This is §1 applied literally: not a deletion,
  but a counterpart, and afterwards one can see what happened. A physical return remains what the
  developer does today, an order with negative quantities (§7).

**Every movement is journalled**: variant, quantity, direction, trigger (order plus status change
from → to, or a correction reason), timestamp, who. The sum of the movements **is** the balance, the
way the journal yields the balance sheet. Without it a wrong balance can only be corrected, never
explained — which is exactly the situation today.

**Correction without an order** is the one exception, and it is required (developer, 2026-09-20):
the yearly stocktake corrects. It goes through the same service, with a mandatory reason (stocktake,
shrinkage, breakage, correction) and a free text, so it stands in the journal marked as a correction
instead of disguised as a movement.

**Stocktaking is a process, not a field:** produce the count list (target balances held with a
timestamp) → count → enter → show differences → release → book the movements. It is **blocked while
stock-relevant orders are unbooked** — the same mechanism as the `PeriodCloseCheck` in §5.3, with
blocking and warning findings, and it shows the counter that list before counting starts.

On the timing problem — the counter counts in the morning, the office books at noon — the movement
journal solves one half outright: the count slip carries the **count time**, and the service computes
`expected at count time = current balance − all movements after the count time`. The difference shown
is then real, not an artefact of when someone pressed the button. The other half — goods that
physically left before anyone booked them — no software can know. But the stocktake block turns
"three are missing somewhere" into "these two orders are not booked yet", and whatever remains lands
as a named difference with a reason instead of silently in the balance.

**A8 (shop checkout) is dropped from this plan.** A shop is not part of order processing,
bookkeeping, receivables and article management — it is a later module, and it decides for itself
whether a checkout creates an order or sends a mail. `module-order` only has to be open to being
called from outside (§7).

## 5. module-financial

### 5.1 Entities and storage

| Entity | Storage | Why |
|---|---|---|
| `Account` (number, name, type asset/liability/equity/expense/revenue — five, owner 2026-09-22 — parent group, postable, active) | Doctrine | referenced by every line; reports join by type/group |
| `FiscalYear`, `Period` (with close state) | Doctrine | locks are checked inside the posting transaction |
| `JournalEntry` (header) + `JournalLine` | Doctrine | volume, transactions, SQL reports |
| `EntryChange` (change log of manual entries) | Doctrine | traceability, see §5.3 |
| `NumberRange` | Doctrine | atomic numbers need a row lock |
| `VatReturn` (period, method, totals per form field, state) | Doctrine | references the period and its entries |
| Settings (VAT method, **VAT-return rounding account**, VAT payable/settlement accounts, retained earnings) | file config | single values, one place (Rule 2) |

Default chart: Swiss SME chart of accounts (KMU-Kontenrahmen), shipped as a resource file and
adopted by a button **only into an empty chart** — not a first-install seed (owner, 2026-09-22: a
migrated chart must not get it forced on it).

### 5.2 Journal entry

- Header: `number` (per fiscal year), `date`, `text`, `kind` (`generated` | `manual`),
  `origin` (`sourceType` + `sourceRef`, opaque strings), `idempotencyKey` (unique, generated only),
  `reversalOf?`, created/changed by/at.
- Lines (n ≥ 2): `account`, `debit`, `credit` (Money, exactly one > 0), `taxCode?`, `taxBase?`,
  `taxAmount?`, `text?`.
- **The ledger posts in the base currency only** (developer, 2026-09-20, as in wdv-6.2.2): a journal
  line carries no currency, no foreign amount and no rate. A document may be issued in a foreign
  currency — the foreign amount and the rate belong to **that document** (§6.2) — but it is posted
  converted, and an exchange difference on payment is an ordinary posting to an exchange-difference
  account, in base currency like everything else. This keeps the ledger single-currency: no parallel
  valuation, no revaluation run, no currency dimension in any report.
- Invariant: Σ debit = Σ credit, checked in the domain before persist.
- **Generated** entries: never editable or deletable. Correction = reversal (`reverse()`), posted by
  the source module.
- **Manual** entries: editable and deletable until the period is closed.

### 5.3 Close states and locks

```text
open ──(VAT return filed)──→ vat-settled ──(accounting close)──→ closed
```

- `open`: generated entries post, manual entries can be created/edited/deleted.
- `vat-settled`: the VAT return for the period is posted and filed with the ESTV. **Decided
  (Q3):** filing closes the return — the return itself and every line carrying a tax code in that
  period can no longer change (it is filed). Manual entries without tax code stay editable until
  the accounting close.
- `closed`: nothing changes. Only a reversal in an open period can correct.
- **Close check (decided 2026-09-18).** Before the VAT return and before the accounting close,
  financial asks every registered `PeriodCloseCheck` (an interface in financial; modules register
  an implementation by config hook — financial knows none of them): "anything open for this
  period?". Each finding is `blocking` or `warning`.
  - debtor, **blocking**: invoices in state `invoicing` dated in the period; payments/CAMT
    transactions not yet booked.
  - order, **warning** (proposal): invoiceable orders with service date in the period — under agreed
    consideration the invoice date counts, so invoicing them next quarter is correct.
  - The ledger's own refusal (no posting into a `vat-settled`/`closed` period) stays the last line
    of defence regardless of the check.
- **Change log** for manual entries (decided, Q4): every edit/delete writes an `EntryChange` (who,
  when, before/after). Keeps "editable until close" traceable in the sense of the GeBüV.
- Numbering: entry numbers per fiscal year from `NumberRange`. A deleted manual entry leaves a gap,
  documented by its change log entry.

### 5.4 Public API (the only thing other modules see)

```php
LedgerService::post(PostingRequest $r): EntryRef        // joins the caller's transaction
LedgerService::reverse(EntryRef $e, DateTimeImmutable $date, string $reason): EntryRef
LedgerService::accountExists(string $number): bool      // for configuration validation
```

- `PostingRequest`: date, text, origin, idempotency key, lines. A repeated key returns the existing
  entry instead of posting twice.
- Refused: unbalanced, unknown/inactive account, period `closed`, tax lines into a `vat-settled`
  period.
- The API never commits; the caller owns the transaction (review §3.2).

### 5.5 Reports (DBAL SQL, read models)

Trial balance, balance sheet, income statement, account statement (Kontoblatt), journal. SQL
aggregates over `JournalLine` — never hydrated entities.

### 5.6 VAT return (effective, agreed)

Σ `taxBase` and Σ `taxAmount` of the period's lines per tax code (+ rate) → country pack `CH` →
ESTV form fields. Mixed rates within a period come out automatically. Saving the return posts the
settlement entry (VAT payable / input tax → settlement account) through `LedgerService` and sets the
period `vat-settled`. Rounding difference between return and ledger: posted to the VAT-return rounding account,
shown, never silently absorbed.

### 5.7 Year-end

Close the last period, carry balance-sheet balances forward as opening entry of the next year,
income statement result to retained earnings. Blocked while a period of the year is still `open` —
**not** by the state of any order (wdv blocks on order state).

---

## 6. module-debtor

### 6.1 Entities and storage

| Entity | Storage | Why |
|---|---|---|
| `DebtorProfile` (per contact: default payment terms, currency, dunning block) | Doctrine | the debtor-specific part of a contact (§4a) |
| `Invoice` / `CreditNote` + lines + tax summary | Doctrine | immutable documents, volume |
| `OpenItem`, `Payment`, `Allocation` | Doctrine | transactional with postings |
| `CamtImport`, `CamtTransaction` | Doctrine | dedup, state per transaction |
| `DunningRun`, `DunningNotice` | Doctrine | history per invoice |
| `NumberRange` (invoice, credit note) | Doctrine | atomic, gapless |
| `PaymentTerms` (due days, discount %, text per language) | file | few rows, master data |
| `PaymentTarget` (IBAN / QR-IBAN, bank account number in the ledger) | file | few rows |
| `DunningLevel` (days, fee, text) | file | few rows |
| Account settings (receivables collective account, discount, loss, **invoice-rounding account** for the 0.05 line, fees) | file config | single values |

### 6.2 Invoicing

`InvoicingService` — the **single entry for every source** (order, manual invoice, later
contracts/subscriptions, fees).

- Draft: contact + invoice address, invoice date, service date(s), currency (+ FX rate if ≠ CHF), price
  mode, lines (text, qty, unit, unit price, discount, tax code, **revenue account**,
  `sourceType`/`sourceRef`).
- **The invoice line carries `type` and `parentLine`** (review 2026-09-20): type `service` / `lump sum`
  / `text` / later `subtotal` as in A-Pos (§4b), and a priced line may carry further lines beneath it,
  which is how a package prints with its contents at 0.00 (§13). This has to exist **in P3**, not in
  P7: it prints on the document, and after P3 every issued invoice is immutable data — adding it later
  is a migration on issued documents.

**Invoice states (decided 2026-09-18 — the wdv `if` state is kept, it is proven in practice):**

| State | Invoice | Ledger |
|---|---|---|
| `invoicing` (in Fakturierung) | Number + PDF exist. Can be re-invoiced as often as needed (clients notice errors after printing) — **the number stays the same**, snapshot and PDF are replaced | **nothing posted**, no open item |
| `final` (Fakturierung abgeschlossen) | Immutable; correction only by credit note | posted, open item opened |

- `invoice(draft)` (one transaction): VAT via `module-vat` → total rounded to 0.05 as **separate
  rounding line** → number from `NumberRange` (only the first time) → `Invoice` in state
  `invoicing` with full snapshot (addresses, lines, rates, tax summary, FX rate) → PDF with QR-bill
  (QRR/SCOR reference; bacon-qr-code is already vendored in the kernel).
- `reinvoice(invoice, draft)`: only in state `invoicing`; same number, new snapshot and PDF.
  Nothing to reverse — nothing was posted.
- `finalize(invoices)` (batch, one transaction): state `final` → `OpenItem` → posting via
  `AccountingGateway` (receivable / revenue per line + VAT per code + rounding). The PDF of this
  moment is the binding document.
- Why posting waits for `finalize`: re-invoicing never touches the books, so no reversal noise and
  no deleted entries (wdv deletes and re-posts). The price: an invoice sitting in `invoicing` is not
  in the books yet — covered by the close check (§5.3), which **blocks** VAT return and close.
- A payment arriving while its invoice is still `invoicing` finds no open item; it stays unmatched
  until `finalize` — also blocking the close, so nothing is lost.
- Credit note: references the invoice, same mechanics with opposite sign, reduces its open item.
- A manual invoice uses the same service from debtor's own UI — revenue without an order.

### 6.3 Payments, discount, loss

- `Payment` (date, amount, bank = payment target) allocated to one or more open items.
- `Allocation` kinds: `payment`, `discount`, `loss`, `fee`. Discount and loss **reduce base and tax
  proportionally per tax code of the invoice's tax summary** (`Money::allocate`, no lost Rappen)
  and post: discount/loss account + VAT correction per code / receivable.
- Open balance = invoice − Σ allocations; derived, not stored in parallel.

### 6.4 CAMT.054 import

Upload → dedup by message id and transaction reference → match by QR reference / SCOR → state per
transaction (unmatched, matched, booked, ignored) → booking posts payments in one transaction.
Several payments for the same invoice in one file must be detected (wdv misses this). ESR/V11 is
not built (ESR discontinued 2022). camt.053 later, when needed.

### 6.5 Dunning

Due list by date → dunning run → notice per debtor/level (PDF with QR-bill of the **open balance**)
→ optional fee as its own open item (no VAT on dunning fees). Levels from `DunningLevel`.

### 6.6 Accounting port

```php
interface AccountingGateway { public function post(PostingRequest $r): ?string; }
```

Default adapter → financial `LedgerService`; `NullAccountingGateway` for installations with
external bookkeeping. Selected by config hook (member pattern, ADR-038). The `PostingRequest` DTO
is financial's; the adapter is the only debtor class that knows financial.

---

## 7. module-order

| Entity | Storage |
|---|---|
| `SalesOrder` + `OrderLine` | Doctrine |
| `NumberRange` (quote, order) | Doctrine |
| Articles | from `module-article` (§4b) |

- **Status is data, not an enum** (decided 2026-09-20, Q5). `OrderStatus` is file-based (a dozen rows,
  like tax codes and payment terms): `code`, multilingual `label`, `level` as a progress number, and
  the flags the code actually reacts to — `reservesStock`, `consumesStock`, `invoiceable`,
  `invoiceInProgress`, `final`. Code never asks "is the status `if`", it asks "does this status
  consume stock". Seeded with the set proven in wdv, reduced to what the order itself controls:
  offer, reserved, ordered, delivered, to be invoiced, **in invoicing**, done, done without stock
  effect. The rows are **installation-owned master data in `data/`** (seed-once, ADR-024,
  `persistence-file.md`) — not code under `override/`; a project maintains them in the backend.
  Measured: the set is almost identical across all installations, yet none uses all of it and each
  adds rows of its own — which is exactly why a hard enum is wrong here.
- **Payment state is not an order status** (review 2026-09-20, confirmed by the developer). wdv has
  `invoiced`, `part-paid` and `paid` as order statuses, and they are set by the receivables side —
  which would make debtor write into order (against §2) and create two truths about one amount: the
  open item and the order status, free to drift apart. Instead order **asks** debtor for the payment
  state through a port, the mirror image of the `AccountingGateway` debtor uses for posting (§6.6).
  The open item stays the single truth; the order screen and its filters show the state exactly as
  before, so nothing changes in daily use. Partial payment, a remainder written off as a loss and
  dunning all happen in debtor (§6.3, §6.4) — as they do today.
- **Transitions: no matrix, two rules.** Forward along `level` is free. Backward is barred once an
  invoice is `final` — the bar comes from the invoice, not from the status model, so there is only one
  truth about when something is committed. Every change is logged (who, when, from → to), and the
  timestamps for offer, confirmation, delivery and invoicing derive from that log instead of being
  kept in parallel columns as wdv does.
- **There is no cancelled status** (developer, 2026-09-20 — wdv's was never used in practice and has
  no purpose). Cancelling is answered by *when*: **before invoicing** the order goes to "done without stock
  effect" and that is all; **after invoicing** a credit note is issued and the two are cleared against
  each other in receivables. A cancelled state would be a third, redundant answer that hides which of
  the two actually happened.
- **A return is an order with negative quantities**, not a special case — created from the original at
  one click, which books the stock back correctly without any dedicated logic, because the quantity
  carries the sign. Positions must therefore allow negative quantities.
- **The status change is the only trigger of a stock movement.** order turns the target status' flags
  into target quantities for the line and asks the stock service in `module-article` to bring the line
  to them, inside the same transaction (§4b, "Stock: one write path"). It passes quantities, never a
  status, so article stays ignorant of `OrderStatus`; and order never writes a balance itself.
- Lines from articles (snapshot: code, text, unit, price, tax code, revenue account) **or free lines**
  — so the variant reference is **nullable**; the snapshot is what the document shows either way
  (contradiction found in review 2026-09-20: an earlier version demanded both free lines and a
  mandatory variant reference).
  The snapshot is **editable from the start** and the line has a **type** — `service` / `lump sum` /
  `text` (`subtotal` later). Both come from the measured service case (§4b, A-Pos): every position
  references one of a few dozen articles yet carries its own title, and a whole group of positions
  exists only as text-only pseudo-articles because wdv has no text line. A line always references
  the **variant** (A3).
- An order can also be **created by a subscription run** (`module-subscription`, Q7) instead of by
  hand or by a shop checkout. order does not know the subscription; the run calls order.
- Quote and order confirmation as PDF from the order.
- VAT on quote/order **for display only** via `module-vat`.
- "Invoice": builds an `InvoiceDraft` from one or several orders (collective invoice), calls
  `InvoicingService::invoice()`, records the invoice number per line. Payment state is **asked** from
  debtor, never pushed back.
- order posts nothing.

---

## 8. Migration from wdv (per installation, the developer's books first)

- **Run in two stages** (§9): the developer's own books in **P5b**, right after the ledger and the
  VAT return exist, as the proof that the model is right; the remaining installations in **P8**.
- Reader on the wdv SQL dump / DB, mapping as code (ADR-032: source-agnostic reader seam, import
  identity, snapshot staging).
- **The import is the one sanctioned second write path** (ADR 1): it writes `Invoice`, `OpenItem`,
  `Payment` and `Allocation` directly and posts nothing, because the journal entries come across
  separately and have to reconcile *as booked*. Going through `InvoicingService::finalize()` would
  post everything a second time. Bounded to staging-based import with `origin = wdv`; stock opening
  balances likewise go through the stock correction path with reason `opening balance`, so the
  one-write-path rule (§1) holds there without an exception.
- Order of import: chart of accounts → fiscal years → journal entries (all years, `origin = wdv`,
  kind `generated`) → debtors → invoices with **the original PDFs** as binding documents → open
  items and payments → orders.
- The wdv double storage (header + `FmBooking` lines) is imported from the **lines** (what the
  balance sheet reads).
- **Acceptance per installation:** for every year and every account, wdv balance = z77 balance to
  the Rappen; open-item list at cut-over date equal; VAT returns per quarter equal. wdv errors are
  imported as booked and listed in the reconciliation report, never corrected silently.
- Migrated past years are set `closed` after acceptance.

---

## 9. Build phases

| Phase | Content | Exit |
|---|---|---|
| P0 | ADRs (§10) | ADRs approved |
| P1 | `Money` in kernel; `persistence-doctrine` (lazy EM, Money type, migrations in CLI, `NumberRange`, open-work check); `module-vat` with backend + CH seed; `module-contact` (§4a) | Tests green; EM boots only on demand; a contact with n typed addresses exists |
| P2 | financial: accounts, fiscal years/periods, manual entries with change log, `LedgerService`, reports | Manual bookkeeping usable |
| P3 | debtor: master, `InvoicingService`, manual invoice, credit note, line types and parent lines, PDF + QR-bill, gateway | Invoice → journal round trip without order |
| P4 | debtor: payments, discount/loss with VAT, CAMT.054, dunning | Subledger = collective account to the Rappen |
| P5 | financial: VAT return CH, close states, year-end | ESTV form from posted codes |
| **P5b** | **Migrate the developer's own ledger and invoice history** — accounts, fiscal years, journal entries, invoices with their original PDFs, open items, payments (§8, through the sanctioned import path in ADR 1) | **Every year and every account reconciles with wdv to the Rappen, VAT returns per quarter match.** The ledger and VAT model are proven before anything is built on them |
| P6 | article, thin: product group tree with revenue account and VAT default, product + (default) variant, price with history, `stockRelevant` flag, article number (§4b) | An order line resolves to a variant with price, tax code and revenue account |
| P7 | order: quote, order, positions with types and negative quantities, status as data, collective invoice → `InvoicingService`; **creatable from outside** with an idempotency key (§13) | Order → invoice → journal, and a second identical call creates nothing |
| **P7b** | stock: movement journal, reservation and consumption, correction with reason, stocktake with its block (§4b) — built **after** order, because the status change is its only trigger | The sum of the movements equals the balance; a stocktake reconciles with a counted difference |
| P8 | Migration of the remaining installations | Acceptance per §8 |

Each phase leaves the framework runnable. Tests as plain PHP scripts in `tests/`, like the existing
ones.

Sequence rationale: contact rides along in P1 because debtor needs it in P3 and it is small master
data; bookkeeping comes before invoicing so an invoice has somewhere to post; article comes before
order because an order line resolves to a variant. Subscriptions, shipping and a shop are not phases
here — see §2 and §13.

Two changes from the review of 2026-09-20, both about finding mistakes earlier:

- **P5b pulls the developer's own migration forward** out of P8. Reconciling a real set of books to
  the Rappen is the only honest test of the ledger and VAT model, and a flaw found there before
  article and order are built on top is a table change rather than a rebuild. ADR-032 already supports
  migrating in several runs, and §8's import order is separable, so this costs sequencing only.
- **Stock moves behind order into P7b.** Its only trigger is the status change, which does not exist
  until P7 — building the journal, the corrections and the stocktake before that means building them
  against nothing. P6 keeps only what an order line actually needs.

---

## 10. ADRs to write (P0)

**Written 2026-09-21:** 1 → [ADR-040](../02-decisions/adr-040-business-module-cut.md),
2 → [ADR-039](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md),
3 → [ADR-041](../02-decisions/adr-041-vat-model.md),
4 → [ADR-042](../02-decisions/adr-042-ledger-and-money.md),
5 → [ADR-043](../02-decisions/adr-043-order-status-and-stock-movements.md); all five approved 2026-09-21, P0 closed. The ADRs are
binding; where this list and an ADR differ, the ADR wins.

1. **Business module cut** — order / debtor / financial / vat, dependency direction, invoicing in
   debtor, order posts nothing, financial open to any posting source, opaque origin + idempotency,
   one transaction, accounting port, payment state asked through a port and never pushed (§7).
   Must also name **the one sanctioned second write path**: the wdv import writes `Invoice`,
   `OpenItem`, `Payment` and `Allocation` directly and posts nothing, because the journal entries are
   imported separately to reconcile as booked — going through `InvoicingService::finalize()` would
   post everything a second time. Bounded to staging-based import (ADR-032) with `origin = wdv`.
   Unnamed, this is discovered in the migration phase and looks like a violation (review 2026-09-20).
2. **Doctrine persistence package** — own package, lazy (ADR-001), Doctrine only where needed,
   migrations only. Business modules reach it through `UnifiedEntityManager` as
   `persistence-architecture.md` promises; ledger reports use DBAL of the same driver, no second
   connection; a minimal transaction port (Doctrine only) covers what `RepositoryInterface` cannot
   carry, concretely the `NumberRange` row lock (see «Settled before P0» at the top, 2026-09-21).
   **Generated files and caches (owner requirement, 2026-09-21):** Doctrine's metadata/query caches
   and any generated proxy files are disposable runtime state under the release-local `var/cache`
   (ADR-034/035). In **DEBUG** they are regenerated on every request, so an entity change is visible
   without a manual step; **«Cache leeren»** in the backend (`clearCacheAction()`) and toggling
   DEBUG remove them as well. In wdv this was done by hand (`setup.php` deleted them) — here it is
   the framework's job.
3. **VAT model** — tax codes with dated rates as managed data, country packs, computed once on the
   invoice and carried, net posting method, discount/loss correction from the snapshot.
4. **Ledger and money** — generated vs. manual entries, close states, change log, numbering, integer
   money, `DECIMAL` ↔ minor-units mapping, **base currency only** (§5.2), and the correction principle
   (§1) as the rule that ties reversal, credit note and negative order together.
5. **Order status and stock movements** — status with behaviour flags instead of a PHP enum, the two
   transition rules, no cancelled state, returns as negative quantities, installation-owned statuses
   in `data/` (§7, Q5); and the stock side hanging off it: one write path, movement journal, one
   transaction, idempotency, correction without an order, and the stocktake block (§4b). One ADR,
   because the status *is* the stock control. It must additionally fix (review 2026-09-20):
   - **Invariants per status**: `final ⇒ ¬invoiceable ∧ ¬invoiceInProgress`;
     `consumesStock ⇒ ¬reservesStock`; flags that code selects a status by must be unique per
     installation; a status referenced by any order is deactivated, never deleted. **Flags remain
     editable** — the movement journal, not the old status, is what a movement is computed against
     (§4b), so introducing a status or changing a flag cannot corrupt history. That freedom is the
     reason status is data at all.
   - **Per transition**: a change that would bring consumed stock back into the balance requires a
     **reason and a confirmation**, and both movements stand in the journal (§4b). It is not
     forbidden — a mistaken click has to be correctable — but it never happens silently. A physical
     return stays an order with negative quantities.
   - **Status is per order, consumption is per line**, so partial delivery cannot be expressed: lines
     move together and a partial delivery is an **order split**. Moving status to line level later is
     a schema change on the movement journal.
   - The **reference rule for file-based master data** held by Doctrine rows (`OrderStatus`,
     `PaymentTerms`, `DunningLevel`, `PaymentTarget`, `TaxCode`, `AddressType`): referenced by `code`,
     snapshotted at use, rows deactivated and never deleted. There is no foreign key across the two
     drivers and a database restore is not paired with a `data/` restore, so the rule has to be
     explicit.

---

## 11. Open questions

| # | Question | Proposal |
|---|---|---|
| Q1 | Who owns the customer master? debtor (`Debtor`), a separate contacts module, or the member account? | **Decided 2026-09-18:** own `module-contact` with n typed addresses per contact (§4a); debtor and order both consume it. |
| Q2 | Articles: minimal article master in order now, or a separate catalog module? | **Decided 2026-09-20:** own `module-article`, built in P6. A1–A7 and A-Pos are decided in §4b against the live data of the installations; A8 (shop checkout) was dropped, because a shop is not part of this plan. |
| Q3 | After the VAT return: freeze only tax-carrying lines, or the whole period? | **Decided 2026-09-18:** filed = closed; the return and all tax-carrying lines of the period are frozen; the rest stays editable until the accounting close. |
| Q4 | Change log + number gaps for editable manual entries acceptable? | **Decided 2026-09-18:** yes — every change is traceable. |
| Q5 | The exact order states and transitions from practice | **Decided 2026-09-20** — derived from `shop_status` across all installations and confirmed by the developer: **status is data with behaviour flags, not a PHP enum** (§7), transitions are governed by two rules rather than a matrix, and **there is no cancelled status**. Cancelling before invoicing sets the order to "done without stock effect"; after invoicing it is a credit note cleared against the invoice in receivables. A return is an order with negative quantities. |
| Q6 | Foreign currencies (EUR invoices) needed at start? | **Decided 2026-09-20: no.** Measured across all installations: a currency field exists only on the payment target (the bank account), and every one of them is CHF. Foreign currencies appear solely in a seeded master table of rates that nothing references. So **no foreign-currency invoicing is built** in P3. The document keeps currency and rate fields (§6.2) so that adding it later needs no schema change to issued documents — but nothing is built for it and no rate source is wired up. **Independently of that, the ledger is single-currency for good** (§5.2): bookkeeping records the base currency only, as wdv does today. A foreign-currency document is posted converted; an exchange difference is an ordinary posting. That is a property of the ledger, not a deferred feature. |
| Q7 | Recurring invoices (contracts/subscriptions) at start? | **Decided 2026-09-20: not in this plan — but the seam is.** Subscriptions are in real use today, and for the framework every variant of them comes down to one requirement: **an order must be creatable from outside**, through a service with an idempotency key, not only by a human at a screen. That seam is in §7. Everything on top of it — turnus, cycle counter, customer preferences, pause windows, delivery zones, how a delivery is composed — is the **application**, becomes its own module (`module-subscription`, §2) and is designed when it is built, not now. What was measured about it sits in the maintainer's local notes so the knowledge is there on that day. |
| Q8 | Database engine at the hoster (MySQL / MariaDB version) | **Decided 2026-09-20: MariaDB 10.6, InnoDB.** Measured: every installation runs MariaDB 10.6.x on the same managed host. The Doctrine ADR sets **MariaDB 10.6 as the minimum** and has to state that DBAL 4 / ORM 3 are verified against it before P1 starts. **Watch the charset:** the existing databases mix `utf8mb3` and `utf8mb4`, and their collations differ per table (`*_general_ci` next to `*_unicode_ci`). New schemas use **utf8mb4 with one collation throughout**, fixed in the ADR — a join across two different collations fails outright, which makes this a migration task (§8), not a detail. |
| Q10 | Does the stock **value** go into the bookkeeping? | **Decided 2026-09-20: not automatically — valuation is a matter of judgement.** The system prints an **inventory list for a given date** (quantity per variant with its purchase price) and stops there; the accounting valuation rules are applied by whoever keeps the books, and the result is entered as a **manual journal entry** (§5.2). So no valuation method in code — no average cost, no FIFO, no lower-of-cost-or-market logic — and no automatic posting of a stocktake difference in value terms. Under Swiss law this is genuinely discretionary, and an algorithm would only pretend to decide it. What it does require from stock is exactly one thing: **the balance must be reconstructable for any date**, which the movement journal delivers by definition (the sum of the movements up to that date). The list is a report (§5.5, DBAL SQL), not a posting. |
| Q9 | Newsletter tool (not in z77 yet) as a consumer of contacts? | Yes as a consumer, but subscriptions (e-mail, list, double opt-in consent, unsubscribe) stay in the newsletter module — a subscriber is often not a contact at all. Optional link subscription → contact; contacts can be an audience source. `Contact` carries no newsletter fields. |

---

## 12. Risks

- **Migration is the largest single effort** — five installations, wdv data with known
  inconsistencies. Mitigation: developer's installation first, acceptance by balances, not by
  sampling.
- **Doctrine is the first heavy dependency** in the z77 world — hosting (PHP extensions, memory),
  update cadence of ~15 packages. Mitigation: isolated package, only installed where used.
- **Scope pull** — shop, subscriptions, shipping tariffs, creditor: all out (§2, §13). This is the
  standing risk of this plan, and the measuring round of 2026-09-20 showed how easily it bites: a
  client's subscription mechanics were on the way into the framework before the framework existed.
  The counter-measure is the seam in §7, not a feature. Stock is the one exception and is bounded by
  A7 (balance and reservation, nothing else).
- **Volume is larger than this plan first assumed.** The largest installation carries an order and
  position count that only SQL aggregation can handle, and it will grow. That confirms D3 (Doctrine
  where the volume is) and §5.5 (reports as DBAL SQL, never hydrated entities), and it means that one
  migration is a different size than all the others together — but it changes nothing about the
  module cut.
- **One installation runs on a code base older than wdv** and is to be rebuilt from idea and
  knowledge, not ported (developer, 2026-09-20) — the same verdict as for order and financial in the
  review. Its application-level requirements are undocumented and partly unwritten ("expand"), which
  is precisely why it is not designed into this plan.

---

## 13. What the installations require of the module cut

The five installations (§4b) were measured on 2026-09-20 to find out what the **framework** has to
provide. Almost everything that came out of it turned out to be application, not framework. Three
requirements remain, and they are the whole of it:

1. **An order must be creatable from outside** — by a service with an idempotency key, not only by a
   human at a screen. Subscription runs need it, a later shop needs it, an import needs it. The seam is in §7; running the same request twice must not produce a second order.
2. **Positions must be free enough**: types `service` / `lump sum` / `text`, an editable text
   snapshot, and a priced position that may carry further positions at 0.00 beneath it — the way a
   package and its contents print on one document. That is A-Pos in §4b.
3. **The sizing case is the largest installation**, whose order and position counts only SQL
   aggregation can handle, and it will grow. It confirms D3 (Doctrine where the volume is) and
   §5.5 (reports as DBAL SQL, never hydrated entities).

Everything else stays out on purpose: turnus and cycle counters, customer preferences and
priorities, pause windows, composing a delivery, delivery zones and shipping tariffs. It is the
application on top of an open `module-order` — a later `module-subscription`, or project code under
`override/` (Rule 1, Rule 8). It gets its concept when it gets built, and the measurements that
would be needed for it are in the maintainer's local notes, not lost.

The same holds for a **shop**: not part of order processing, bookkeeping, receivables and article
management, therefore not in this plan. It is why A8 was dropped (§4b).

This is a deliberate correction of an earlier version of this section, which planned a full concept
round for one installation before the framework existed. Designing the framework around one client's
application is what the guiding rule forbids: **build it right once, nothing twice, nothing in
stock.**
