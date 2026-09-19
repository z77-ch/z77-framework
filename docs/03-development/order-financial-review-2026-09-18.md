# Review — order, debtor and financial from wdv-6.2.2

**Date:** 2026-09-18
**Basis:** read-only analysis of `wdv-622-master-6/wd-v6.2.2/{order,financial}` (+ the parts of
`common`, `service`, `creditor` they reach into), and z77 ADR-001/005/019/023/027/038,
`persistence-architecture.md`.
**Status:** decisions D1–D8 taken 2026-09-18 (§7). Nothing built. Next: ADRs (phase P0).

---

## 1. Verdict in one paragraph

**Rebuild, do not port.** The wdv modules carry valuable domain knowledge (Swiss VAT,
QR/ESR references, CAMT.054 matching, the debtor ledger idea) but their structure contradicts
the target on every axis that matters for accounting: the ledger points *into* order, issued
invoices are mutable, bookings are hard-deleted, money is `float`, VAT is recomputed from live
master data on every reprint, and the chart of accounts is an unwritten arithmetic contract.
What we take over is *knowledge and test cases*, not code. The developer's boundary —
**financial knows only debit/credit postings, it is posted to through a service** — is correct and
is the backbone of this review. Refined in the discussion into three modules: order, debtor
(invoicing + receivables) and financial (§3.1). financial is open to any posting source.

---

## 2. Findings in wdv (condensed)

### 2.1 Coupling runs the wrong way

| Direction | Where | Effect |
|---|---|---|
| financial → order | `FmBookingEntry` owns FKs to `Order\DebtorSpliting` (n:1), `Order\DebtorVat` (1:1), `Creditor\CreditSpliting` (n:1) | The ledger schema depends on order and creditor tables |
| financial → order | `TaxSettlementController`, `FmBookingEntryAjaxController` read `Order\VatData` for rates | VAT rates live in order, the VAT return depends on them |
| financial → order | `YearEndController` queries `Order\ShopOrder` / `ShopState` (`if`) | Year-end closing blocked by order state |
| order → financial internals | `DebtorAjaxController:296-301` reads the `FmBooking` lines of an earlier VAT booking to find accounts | Order depends on ledger internals, not on an API |
| order → financial controller | static `FmAccountController::createBooking()` / `removeBooking()` | A controller used as a service; no transaction boundary, no flush |
| service → financial | `Mandator` holds 7 `FmAccount` associations; `getBookingYear()` calls a financial controller | Tenant master data welded to the ledger |
| common → order | `common/Libraries/Util/VatCalculator` depends on `ShopOrder`, `VatData` | The "common" VAT calculator is an order class in disguise |

### 2.2 Accounting integrity

- **Issued invoices are mutable.** No `Invoice` entity: an invoice is `ShopOrder` in state `if`
  plus a 1:1 `Debtor` plus bookings. Re-invoicing deletes and rebuilds all bookings, may assign a
  **new invoice number** when the month changed, and overwrites the PDF. Deleting an order up to
  level 70 hard-deletes debtor, splits and bookings — including invoices in an already settled VAT
  quarter (`removeBooking()` checks neither `taxSettlement` nor `locked`).
- **Invoice number not atomic**: `prefix + YYMM + counter(3)`, counter from "last number of the
  month", no lock, no unique index, overflows after 999/month. (The order number, by contrast, uses
  an atomic `UPDATE … number+1` — the right pattern exists in the codebase.)
- **Double bookkeeping of the bookings**: every entry is stored as header (debit account, credit
  account, value) *and* as two `FmBooking` lines. The balance sheet reads the lines, the VAT return
  reads the header. Only 1:1 postings exist — no split entries.
- **Subledger ≠ general ledger possible**: the rounding difference is booked only when the last
  VAT acronym has positions; otherwise `DebtorSpliting.debit` (rounded to 0.05) differs from the
  collective account by Rappen.
- Entities read `$_SESSION` in lifecycle callbacks (`TaxSettlement`, `ShopOrder`).

### 2.3 Money and rounding

- DB `decimal`, PHP `float` throughout; strict float comparisons (`$orderTotal === $invoiced`,
  `$netTotal !== $invoiceTotal`).
- VAT never rounded to 0.01 before posting — MySQL rounds on insert. Invoice print, posting and
  VAT return round three different ways; the difference ends up in `TaxSettlement.rounding`.
- `Currency.rounding` (0.05) is applied to every unit price and position sum, not only the total.
- Foreign currency: prices stored in CHF, converted with the **current** `chfValue` on every
  calculation; the rate is never stored; invoice postings go in order currency **without
  conversion to CHF**.

### 2.4 VAT

| Aspect | wdv today |
|---|---|
| Master data | `VatAcronym` (`00`/`ns`/`rs`) + dated `VatData` (rate, `fmAccount`, `profitAccountSuffix`) in **order** |
| Rate choice | by service date (`deliveryAt`, fallback `invoiceAt`) — correct principle |
| Snapshot | none; `ShopPosition.vatAcronym` is stored but **ignored** — the live article's acronym is used |
| Price mode | `ShopOrder.vat` 0/1/2 (exempt/exclusive/inclusive); "inclusive" is simulated from net, so a fixed gross price is impossible; `=== 1` vs `=== 2` checks are inconsistent (bug) |
| Posting | gross method: revenue booked gross, then VAT moved 3811/3812 → 2200 per invoice |
| Revenue account | computed: `str_pad(group,4,'0') + (vat+1)*10 + suffix` — accounts `32x0/32x1/32x2` encode price mode and rate |
| Shipping/packing | always at the tenant's default rate, regardless of the main supply |
| VAT return | effective method, agreed consideration only; **one** normal + **one** reduced rate per period; tax **recomputed** as sales × rate instead of summing posted VAT; no special rate (3.8 %), no mixed-rate periods, no net-tax-rate method |
| Input tax | manual, child entry via `parentFmBookingEntry`; the JS computes `value * rate/100` on a gross amount (should be `rate/(100+rate)`: 108.10 @ 8.1 % → 8.76 instead of 8.10); settlement amounts taken straight from `$_POST` |
| Exempt vs export | no distinction in code — only via account suffix |

### 2.5 Scope creep inside "order"

The wdv `order` module is really five contexts: **sales documents** (order/quote/invoice),
**receivables** (debtor, payments, CAMT, dunning), **catalog** (articles, groups, part lists,
search index — also used by shop/purchase/user), **stock** (two parallel stock models with
opposite reservation signs) and **shipping tariffs**. Only the first two belong to the target.

### 2.6 Code health

God classes (`OrderAjaxController` 1399 lines, `ShopOrder` 1414, `OrderReportController` 1173,
`editAction.tpl.php` 1026), business logic in controllers, temporal coupling (sums exist only after
`VatCalculator::setOrder()` ran — two known bugs from that), several fatal paths
(`triggerError()` does not exist in managers; creditor passes `stdClass` to a typed
`createBooking()` — creditor bookings likely do not run at all), dead code (`TaxStatement`,
`invoiceContentOHNE_MWST`, `PaymentController`, `NOTUSED*`). ESR slips are still generated although
the orange/red slip was discontinued in Switzerland on 2022-09-30.

**What is worth keeping as knowledge:** rate by service date; the debtor "account sheet" idea
(invoice line + payment/discount lines); CAMT.054 import with dedup by `MsgId`; QR reference
construction (mod-10 recursive); the atomic number-range pattern; the list of ESTV form fields the
client actually uses. And every bug above becomes a **test case** for the rebuild.

---

## 3. Target architecture

### 3.1 Bounded contexts and dependency direction

**Decided 2026-09-18 (D8, the developer's cut): three business modules plus `module-vat`.**

| Module | Owns |
|---|---|
| `module-order` | quote, order, order header + lines from articles |
| `module-debtor` | the claim: **invoicing** (invoice, credit note), open items, payments, discount/loss, CAMT import, QR-bill, dunning |
| `module-financial` | journal entries, balance sheet, P&L, VAT return |
| `module-vat` | tax codes with dated rates, calculation, country pack `CH` (§3.5.8) |

```text
module-order ──→ module-debtor ──→ module-financial
      │                │
      └──→ module-vat ←┘
```

- **Invoicing lives in debtor, not in order.** An invoice is a claim against a customer; it can
  come from an order, a contract/subscription, a manual entry, membership fees, later time
  tracking. With the invoicing service in debtor, **revenue can arise without an order**, and no
  source has to know the others. order hands invoiceable lines to debtor's invoicing service and
  gets the invoice number back.
- **order posts nothing.** An order is not an accounting event; the invoice is. debtor posts on
  invoice, credit note, payment, discount and loss. **financial is not limited to debtor**: any
  module may post through `LedgerService` — a future `module-creditor`, the wdv migration, and
  manual entries in financial itself.
- **Collective invoice** becomes clean: one invoice holds lines from several orders, each line with
  its own source reference (wdv copies orders into a new "collective order").
- **order computes VAT for display only** (quote/order totals) with `module-vat`; the binding
  computation is the one on the invoice in debtor.
- **Account determination**: each invoice line carries its revenue account, supplied by the source
  (order derives it from the article/group, a manual invoice picks it). debtor adds the receivable
  collective account, VAT accounts per tax code, bank per payment target, discount and loss
  accounts — from its own configuration, never by account-number arithmetic.
- **Nobody knows its consumer.** debtor knows no order, financial knows nobody. The only trace of
  a source is an **opaque reference** (`sourceType` + `sourceRef` strings, e.g. `order` /
  `A-2026-0042` on an invoice line, `debtor.invoice` / `RE-2026-00017` on a journal entry) for
  display and drill-down, plus an **idempotency key** (unique) so a retry can never post twice.
- **debtor owns an outbound port** `AccountingGateway` (interface in debtor). The default adapter
  calls financial's `LedgerService`; a `NullAccountingGateway` (or an export adapter for an external
  bookkeeping tool) can replace it. financial is a Composer `suggest` of debtor, not a hard
  `require` — a client may invoice with external bookkeeping. Wiring by config hook, the pattern
  `module-member` already uses (`activationHook`, ADR-038 "the module asks, it never leads").
- **Subledger → general ledger**: debtor holds the open items per customer, financial only sees
  the collective account (e.g. 1100). A later `module-creditor` is the mirror image and may share
  the payment import (CAMT is symmetric) — extracted only when it exists.
- **Catalog and stock are out of scope.** An order line holds a snapshot (code, text, unit,
  price, tax code) and an optional article reference. A catalog module can come later without
  touching order or debtor.

### 3.2 Transaction boundary

Issuing an invoice, opening its open item and posting it must commit **atomically or not at all**.
All modules use the same database and the same Doctrine connection, so the whole chain runs in one
transaction opened by the caller (order's "invoice these orders", or debtor's manual invoice);
`InvoicingService` and `LedgerService::post()` join it and never commit on their own. An outbox /
async event bus is **not** needed at this scale and would only add a failure mode (invoice issued,
posting pending). Revisit only if financial ever moves to a separate database.

### 3.3 financial — the ledger

- **Journal entry = header + n lines**, invariant `Σ debit = Σ credit` enforced in the domain
  and by a check before insert. No separate "debit account / credit account" header fields — one
  representation only (fixes §2.2 double storage). Split entries (one receivable against two
  revenue accounts + VAT) are one entry.
- **Two kinds of entries (decided 2026-09-18).** *Generated* entries (posted by a module such as
  debtor) are **never editable or deletable** — a correction is a reversal entry (Storno) from the
  source. *Manual* entries in financial **can be edited or deleted until the accounting close** of
  their period — practicable, and how bookkeepers work. After the close everything is frozen.
  Traceability (GeBüV, 10-year retention OR 958f) is kept by a change log on manual entries — see
  the build plan. wdv today hard-deletes even generated entries.
- **Gapless entry numbers per fiscal year** from an atomic number range (row lock).
- **Periods with a lock state** (open → VAT-settled → closed). Posting into a locked period is
  refused by the ledger itself, not by the caller.
- **Line fields** (sketch): `account`, `debit`, `credit` (Money, one of them zero),
  `taxCode?`, `taxBase?`, `taxAmount?`, `currency`, `amountFx?`, `fxRate?`, `text`.
  Tax data lives **on the line**; the VAT return sums lines by tax code (§3.5).
- **Reports are SQL aggregates** (DBAL), never "load all entries and sum in PHP" — wdv's
  `FmAccount::addDebitSum()/addMove()` pattern does not scale.
- **Accounts**: plain chart (number, name, type asset/liability/expense/revenue, active, parent for
  grouping). A default chart following the Swiss SME chart of accounts (KMU-Kontenrahmen) as seed
  (ADR-024 first-install seed). No nested set unless the UI proves the need.

### 3.4 order and debtor

**order**

- **`SalesOrder`** is the mutable working object (quote/order stages as states), header + lines
  from articles. It records which of its lines were invoiced (invoice number from debtor) and asks
  debtor for the payment state — debtor never calls back into order.
- **States as a PHP enum with guarded transitions** in the domain, not DB rows with magic acronyms
  and level thresholds (`t`, `if`, `f`, `tb`, `b`, level 25/69/70 today).
- **Selection state** per user/session, not a global `sortSelectorKey` column.

**debtor**

- **`InvoicingService`** is the single entry for every source: it takes invoice lines (text, qty,
  unit price, tax code, revenue account, source reference), computes VAT via `module-vat`, issues
  the invoice, opens the open item and posts.
- **`Invoice`** is its **own entity**, created by an `issue` operation, **immutable** afterwards:
  number (atomic range), dates, address snapshot, lines snapshot (text, qty, unit price, tax code,
  **rate**), a **tax summary** per code (base, rate, tax), rounding difference, total, currency and
  FX rate. Reprints render from the snapshot only. Corrections = **credit note** referencing the
  invoice (own entity/type, not "an order with negative quantities").
- **Open items**: `OpenItem` per invoice/credit note, `Payment` with allocations (partial payment,
  discount, write-off each an allocation line). Balance = invoice − Σ allocations, derived, not
  stored twice (wdv keeps `paid`, `invoiced`, `debit` side by side).
- **Payment import**: CAMT.054 (and camt.053 later) matched by **QR reference** (QRR) or creditor
  reference (SCOR, ISO 11649). ESR/ISR import and slips are dropped (discontinued 2022).
- **Dunning**: levels with configurable fees and due days; the QR slip shows the **open balance**
  (wdv shows the full invoice amount — bug).

### 3.5 VAT — redesigned for both sides

The core rule: **VAT is computed exactly once — on the document — and from then on only carried.**

**Scope (decided 2026-09-18):** the *model* is European from day one — a tax code is
country + category (standard, reduced, super-reduced, zero, exempt, reverse charge) + dated rates,
and each country's return is a **country pack** mapping codes to that country's form fields. Only
the **Swiss pack** (rates + ESTV return) is built now. Other countries, OSS and further special
cases are built when a client needs them — nothing in stock, and adding them must not change the
model.

1. **Tax codes, not acronyms + account arithmetic.** A tax code (e.g. `UN` normal, `UR` reduced,
   `US` special/accommodation, `UE` export, `UA` excluded, `VM` input tax material/services,
   `VI` input tax investments/other) has **dated rates** (7.7 → 8.1 % on 2024-01-01 etc.) and a
   **form-field mapping** from its country pack (Swiss: ESTV). Rates come with the code; a project
   overrides only deviations.
2. **Rate by service date** (kept from wdv — legally correct); the document stores the resolved
   rate on every line.
3. **Calculation rule per document**: group lines by tax code → base = Σ line nets (each rounded to
   0.01) → tax = round(base × rate, 0.01) per code. Gross price mode: tax =
   round(gross × rate / (100 + rate), 0.01). The total is rounded to 0.05 **as a separate rounding
   line** posted to a rounding account — never smeared onto a revenue account.
4. **Shipping/packing** carry their own tax code (default configurable; practice: follows the main
   supply). Not hard-wired to the tenant default.
5. **Posting uses the net method**: receivable against net revenue *per code* and VAT payable per
   code — one entry, each line tagged with tax code + base + amount. This replaces wdv's gross
   posting + VAT transfer (half the entries, no reverse-reading of old postings for discounts).
6. **The VAT return is an aggregation**, not a recomputation: Σ `taxAmount` and Σ `taxBase` of
   posted lines per tax code in the period → ESTV form fields via the code mapping. Mixed rates in
   one period (old/new rate, form fields 302/303 etc.) fall out automatically. The return's own
   entry (VAT payable → settlement account) is posted through the same `LedgerService`, and the
   period is locked.
7. **Discount (Skonto) and bad-debt loss (Debitorenverlust) on payment** — required from the
   start, they occur all the time. Each reduces base and tax proportionally per code of the
   invoice's tax summary — computed from the **invoice snapshot**, not from earlier ledger lines —
   and is reported as a reduction of consideration in the VAT return.
8. **Who owns tax codes?** Both sides need them (order to compute, financial for manual input-tax
   postings and the return). **Decided (D4):** a small module **`module-vat`**
   (`Z77\Module\Vat`), required by both. Tax codes and their dated rates are **data, managed in the
   backend** — a rate change is a new row with "valid from", old rows stay (the wdv `VatData`
   pattern). Stored with the file driver (a few dozen rows, no database needed); the Swiss codes
   and rates are seeded on first install (ADR-024). Calculation, rounding and the `CH` country pack
   are plain PHP classes in the same module, fully unit-tested. Every document stores the rate it
   used, so editing the list never changes an issued invoice. Neither order nor financial depends on
   the other for tax data.

Methods to decide per installation: **effective method** (full), **net tax rate method**
(Saldosteuersatz: no input tax, return = gross revenue × rate per activity), **agreed vs. received
consideration** (vereinbart/vereinnahmt). Recommendation: build *effective + agreed* first, design
the line schema so *received* works (payment allocations carry the tax split per code) and the net
tax rate method is only a different aggregation. See decision D5.

---

## 4. Persistence — Doctrine, and where

### 4.1 Is a database the right call?

**Yes.** Not because of volume first, but because of **transactions**: an invoice, its open item and
its journal entry must commit together; a gapless number needs a row lock; posted entries need
constraints. The file driver has none of this — `persistence-architecture.md` ARCH-A003 says so
explicitly ("don't abstract transactions through RepositoryInterface"). Volume is the second
argument (a ledger grows by thousands of lines per year per client; wdv's `findBy` on files would be
O(n) PHP filtering).

### 4.2 Doctrine ORM — yes, with two rules

A lesson worth stating plainly: **an ORM is for writing aggregates, not for reading reports.**

- **Write side → ORM.** Invoices with lines, journal entries with lines, open items with
  allocations are aggregates; unit of work, identity map and cascades pay off there.
- **Read side (reports, lists, VAT return, open-item list) → DBAL SQL**, returning arrays/read DTOs.
  Hydrating a year of journal lines into objects to sum them is the wdv performance trap.
- A DBAL-only design (no ORM, hand-written mappers) is a legitimate alternative for the ledger — it
  is append-only and aggregation-heavy. Not recommended here: two persistence styles in two sibling
  modules cost more than the ORM overhead, and the developer already knows Doctrine.

Stack: **doctrine/orm 3 + doctrine/dbal 4 + doctrine/migrations**. ORM 3 has no annotations —
mapping by **PHP attributes** (wdv uses ORM 2.20 + annotations + Gedmo; none of that carries over).
No Gedmo: timestamps/blame are set by the domain, not by `$_SESSION` in lifecycle callbacks.
Schema changes only through **migrations**, never `schema:update` on a client database.

### 4.3 Not in the kernel

`z77/kernel` has **zero Composer dependencies** today (even the QR library is vendored), and
ADR-001 keeps ORM/DB out of the boot path. Doctrine ORM 3 pulls ~15 packages. Therefore:

- New package **`z77/persistence-doctrine`**: EM factory from config (lazy, via DI — ADR-001),
  a `DoctrineRepository` adapter for `RepositoryInterface` (fills the empty
  `persistence/src/Doctrine/` stub's intent, but outside the kernel), entity-directory registration
  per module (ADR-019 already put entities in per-domain `Entities/` folders for exactly this),
  migrations wired into the z77 CLI (ADR-028).
- Only `module-order`, `module-debtor` and `module-financial` require it. A one-pager never installs Doctrine.

### 4.4 The honest deviation from the unified persistence promise

`persistence-architecture.md` promises "switching a backend = changing the `#[Entity]` attribute,
consumer code unchanged". For order/financial that promise **cannot hold** — they need
transactions, locks and SQL aggregation, which the minimal `RepositoryInterface` deliberately does
not offer. Recommendation: say it openly in an ADR — **transactional business modules bind to
Doctrine directly** (their repositories are Doctrine repositories, services take the EM); the
unified layer stays the tool for content-like entities. Hiding Doctrine behind the lowest common
denominator would recreate wdv's workarounds.

Side finding: `persistence-architecture.md` lists `persist/flush/save/delete` on
`RepositoryInterface`; the code has only `find/findAll/findBy/findOneBy` (writes live on
`UnifiedEntityManager`). Doc drift, to fix with the ADR.

### 4.5 Money

- PHP: a `Money` value object with **integer minor units** (Rappen) + currency; no `float` anywhere
  in the domain. Rates as decimal strings / basis points. Rounding modes explicit (0.01, 0.05).
- DB: `DECIMAL(15,2)` for amounts (readable, `SUM()` in SQL is exact), mapped to `Money` via a
  custom Doctrine type or embeddable. Rates `DECIMAL(5,2)`.
- Postings in the ledger's functional currency (CHF); foreign-currency amount and FX rate stored on
  the line and on the invoice snapshot.

---

## 5. Placement — a philosophy check

ADR-027 keeps vendor/domain-specific integrations **out** of the MIT monorepo. Order and
accounting are not vendor-specific, but they are business-domain modules and arguably the paid
value in client projects. CLAUDE.md's philosophy says money comes from *using* the framework — which
speaks for MIT in the monorepo. **Decided (D1): monorepo, MIT, public** — double-entry
bookkeeping and order processing are industry-neutral.

---

## 6. Proposed phasing

| Phase | Content | Exit |
|---|---|---|
| P0 | Decisions D1–D8, ADRs (Doctrine package + deviation, module cut order/debtor/financial, VAT model, money) | ADRs approved |
| P1 | `z77/persistence-doctrine` + `module-vat` with backend management and country pack `CH` (with the wdv bugs as unit tests) | EM boots lazily; VAT calc green |
| P2 | financial: accounts, fiscal years/periods, `LedgerService` (post, reverse), trial balance, account statement | Posting API frozen |
| P3 | debtor: `InvoicingService`, manual invoice, invoice issue (snapshot, number, posting in one transaction), credit note, PDF + QR-bill | Invoice → journal round trip, without any order |
| P4 | debtor: open items, manual payment, discount/loss with VAT correction, CAMT.054 import, dunning | Subledger = collective account, to the Rappen |
| P5 | financial: VAT return (effective/agreed), period lock, year-end | ESTV form from posted codes |
| P6 | order: quote, order, lines from articles, hand-over to `InvoicingService`, collective invoice | Order → invoice → journal |
| P7 | wdv data import per client (ADR-032 import identity), full history (D7) — the developer's books first | Balances per year and account equal, to the Rappen |
| later | `module-creditor` (mirror of debtor, same gateway pattern), catalog, stock | — |

---

## 7. Decisions needed from the developer

| # | Question | Recommendation |
|---|---|---|
| D1 | Monorepo (MIT) or separate repo(s) (ADR-027)? | **Decided 2026-09-18: monorepo, MIT, public** — double-entry bookkeeping and order processing are industry-neutral. Added requirement: the VAT model is built for European VAT in general, not Swiss-only, where feasible. Guiding rule: build it right once, nothing twice. |
| D2 | Doctrine ORM + DBAL reports, or DBAL-only? | **Decided 2026-09-18:** ORM 3 for writes, DBAL SQL for reports, PHP attributes, no Gedmo, schema only through migrations (§4.2) |
| D3 | Doctrine in its own package `z77/persistence-doctrine`? | **Decided 2026-09-18: yes** (§4.3). Doctrine **where needed** (transactions, volume, SQL reports) — everything else stays file-based entities, also inside the business modules. Existing file entities (content, navigation, …) stay on the file driver; they are Doctrine-capable with some effort, and move only when a real need appears (e.g. ~1000 blog posts that need indexing). Consequence: the `DoctrineRepository` adapter for `RepositoryInterface` is **not** built now — order/financial bind to Doctrine directly (§4.4); the adapter comes with the first file entity that switches. |
| D4 | Tax codes: own library, or owned by financial and read by order? | **Decided 2026-09-18:** own module `module-vat`, rates managed in the backend with "valid from", file driver, CH seed (§3.5.8). A z77-free library was dropped — no consumer outside z77. |
| D5 | VAT methods to support at start | **Decided 2026-09-18:** build **effective + agreed** only (what all 5 books use today). Net tax rate (Saldosteuersatz) and received consideration are **not built**, but the model must carry them without change (net tax rate = a second aggregation of the same lines; received = payment allocations already split per tax code). **Discount (Skonto) and bad-debt loss (Debitorenverlust) with proportional VAT correction are mandatory from the start** — they occur all the time (§3.5.7). |
| D6 | Several legal entities (Mandanten) per installation, or one per database? | **Decided 2026-09-18: one installation = one company = one database** (as in wdv; `Mandator` holds the company's own data). Multi-tenancy is not a topic now and gets no column in stock; if it ever comes, a separate database per company works unchanged, and a shared database is a mechanical migration (company id on the aggregate roots). |
| D7 | Which clients run wdv order/financial in production, and must their data be migrated (open items, current fiscal year, full history)? | **Known 2026-09-18:** 4 accounting clients + the developer's own books (orders + accounting) — the developer is the first migration. **Decided 2026-09-18: full history, migrated once** — wdv is not kept alive for the 10-year retention (OR 958f). All years imported as entries marked with origin `wdv`; per year and per account the wdv balance must equal the z77 balance to the Rappen, or the migration is not done. Invoices: the PDF issued back then stays the binding document and is migrated with the invoice data — nothing is recomputed. wdv errors (rounding differences, deviations) are imported as booked and shown in the reconciliation, never silently corrected. Migration is part of the design from day one (origin/source fields on entries and invoices), not a later add-on. |
| D8 | Receivables inside `module-order` (domain folder) — confirmed? | **Decided 2026-09-18 (the developer's cut): own module `module-debtor`**, which also owns invoicing; order posts nothing; revenue can arise without an order; financial accepts postings from any source (debtor now, creditor later, manual, migration) (§3.1). |

Before D6/D7: read `shop_status` (level per acronym) and `currencies` (`rounding`, `chfValue`) from
a production wdv database — both are business rules that exist only as data.
