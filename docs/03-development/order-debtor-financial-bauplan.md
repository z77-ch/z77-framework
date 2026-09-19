# Bauplan — order, debtor, financial, vat, contact, article (first plan)

**Status:** `[CONCEPT]` — first plan, before external review. Nothing built.
**Date:** 2026-09-18
**Basis:** [`order-financial-review-2026-09-18.md`](order-financial-review-2026-09-18.md) — findings
in wdv-6.2.2 and decisions D1–D8 (§7 there). This plan does not repeat the wdv analysis.
**ADRs:** to be written in phase P0 (§10).

## Where we continue (as of 2026-09-18, end of day)

1. **Decide the article grouping (§4b):** is *one* product-group tree per article enough, or does
   an article sometimes need two placements at once (e.g. "white wine" and also "autumn offer")?
   The developer decides on 2026-09-19.
2. Then the remaining article questions in §4b (article code, variants, prices, images, discount
   groups, shop checkout → order).
3. Then the open questions Q5–Q8 (§11).
4. Then the **external review of this plan with Fable** (agreed 2026-09-18), then the ADRs (P0).

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

---

## 2. Packages and dependency direction

| Package | Namespace | Depends on |
|---|---|---|
| `z77/kernel` (existing) | `Z77\Core`, `Z77\Shared`, `Z77\Persistence` | — (no Composer deps, stays so) |
| `z77/persistence-doctrine` (new) | `Z77\Persistence\Doctrine` | kernel, doctrine/orm 3, doctrine/dbal 4, doctrine/migrations |
| `z77/module-vat` (new) | `Z77\Module\Vat` | kernel |
| `z77/module-contact` (new) | `Z77\Module\Contact` | kernel, persistence-doctrine |
| `z77/module-article` (new, proposal §4b) | `Z77\Module\Article` | kernel, persistence-doctrine, module-vat |
| `z77/module-financial` (new) | `Z77\Module\Financial` | kernel, persistence-doctrine, module-vat |
| `z77/module-debtor` (new) | `Z77\Module\Debtor` | kernel, persistence-doctrine, module-vat, module-contact; `suggest` module-financial |
| `z77/module-order` (new) | `Z77\Module\Order` | kernel, persistence-doctrine, module-vat, module-contact, module-article, module-debtor |

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
| `Contact` | Doctrine | person or company, name parts, language, e-mail/phone, optional `memberAccountId`, active |
| `Address` | Doctrine | salutation, title, first name, name, address row, street, house no, zip, city, country |
| `ContactAddress` | Doctrine | contact ↔ address with **type** and title — n addresses per contact (wdv `Addressing`) |
| `AddressType` | file | main, invoice, delivery, regional, … — managed data, like wdv `AddressType` |

- Module-specific data stays in its module, keyed by contact id: payment terms and dunning block in
  debtor, order defaults in order. contact knows none of them.
- Every document (quote, order, invoice, dunning notice) stores the address it used as a **snapshot**;
  later address changes never alter an issued document.
- Newsletter: see Q9.

## 4b. module-article — proposal, not decided

Articles have **two consumers**: order (lines from articles) and a web shop (display with price,
VAT, text, images; basket; checkout). The developer has 2 shop clients → articles are their own
module, not part of order. A shop module itself is a later step; the article module is designed for
both consumers now.

### What wdv does today (analysis 2026-09-18)

- `AbstractShopArticle` (1406 lines, god class; infrastructure and HTML inside the entity).
- **Grouping:** `group1..group4` are **not** independent classifications — they store the **path**
  of the article in **one** product-group tree (`ShopGroup`, nested set, tag `shop`), denormalised,
  fixed depth 4. The group `item`s derive the article code (`01-010-01-xxx`), the sort key and the
  shop URL. Moving a group does not update the articles; a slot cannot be emptied.
- Other classifications beside the tree: `ShopType` (+ `typeGroup`), `DiscountGroup` (customer
  discount per group, applied in order, not in the shop; a group named `zero` is mandatory),
  `ShopNote` (badge), `favorite` / `notOrderable` (colour codes).
- **Price:** `price` + exactly **one** future step (`priceValideAt` / `dateValideAt`), moved by a
  batch on the due date — no history. Net/gross never declared (implicitly net). Offer price only as
  a client extension (molki-stans), yet called from core code.
- **Texts:** title single-language; descriptions multilingual via `PageContent`, parallel to legacy
  columns. **One image** per article, PHP-serialised.
- **Part lists** exist (flattened, no cycle check); **variants** (size/colour) do not.
- **Shop:** no finished shop in wdv-622. studio-vonaarburg shows articles as teasers, checkout =
  confirmation mail. chaesgschichten (old separate code base) creates an order on checkout
  (status "reserved", no payment). No payment provider anywhere.
- molki-stans (wine trade) extends the article (grape, vintage, supplier, offer price) — client
  extensions of the article must stay possible (override/, Rule 1).

### Proposal

- **Grouping:** one product-group tree of **any depth**, built on the z77 tree foundation
  (ADR-008, the mechanism behind navigation). Each article hangs at one group; the path is always
  read from the tree, never stored on the article — moving a group takes its articles along.
  Group names multilingual, slug for the shop URL (z77 translation layer). Tree file-based (dozens
  to hundreds of entries), articles in Doctrine.
- **Open (decide 2026-09-19):**
  - A1 — one tree per article enough, or several placements (e.g. "white wine" + "autumn offer")?
  - A2 — article code: derived from the group path as in wdv, or free / from a number range?
  - A3 — variants (size/colour) needed by the shop clients, or one article per variant as today?
  - A4 — prices: price rows with "valid from" like VAT rates (history), net/gross declared per
    price; offer price as a general feature?
  - A5 — images: several per article via `module-dms` (reuse) instead of one serialised image?
  - A6 — discount groups (customer discount per article group) still used?
  - A7 — part lists and stock: needed now, or later?
  - A8 — shop checkout: creates an order in `module-order` (like chaesgschichten) or only a mail
    (like studio-vonaarburg)? Unanswered question from 2026-09-18.

## 5. module-financial

### 5.1 Entities and storage

| Entity | Storage | Why |
|---|---|---|
| `Account` (number, name, type asset/liability/expense/revenue, parent for grouping, active) | Doctrine | referenced by every line; reports join by type/group |
| `FiscalYear`, `Period` (with close state) | Doctrine | locks are checked inside the posting transaction |
| `JournalEntry` (header) + `JournalLine` | Doctrine | volume, transactions, SQL reports |
| `EntryChange` (change log of manual entries) | Doctrine | traceability, see §5.3 |
| `NumberRange` | Doctrine | atomic numbers need a row lock |
| `VatReturn` (period, method, totals per form field, state) | Doctrine | references the period and its entries |
| Settings (VAT method, rounding account, VAT payable/settlement accounts, retained earnings) | file config | single values, one place (Rule 2) |

Default chart: Swiss SME chart of accounts (KMU-Kontenrahmen) as first-install seed.

### 5.2 Journal entry

- Header: `number` (per fiscal year), `date`, `text`, `kind` (`generated` | `manual`),
  `origin` (`sourceType` + `sourceRef`, opaque strings), `idempotencyKey` (unique, generated only),
  `reversalOf?`, created/changed by/at.
- Lines (n ≥ 2): `account`, `debit`, `credit` (Money, exactly one > 0), `taxCode?`, `taxBase?`,
  `taxAmount?`, `currency`, `amountFx?`, `fxRate?`, `text?`.
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
period `vat-settled`. Rounding difference between return and ledger: posted to the rounding account,
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
| Account settings (receivables collective account, discount, loss, rounding, fees) | file config | single values |

### 6.2 Invoicing

`InvoicingService` — the **single entry for every source** (order, manual invoice, later
contracts/subscriptions, fees).

- Draft: contact + invoice address, invoice date, service date(s), currency (+ FX rate if ≠ CHF), price mode, lines
  (text, qty, unit, unit price, discount, tax code, **revenue account**, `sourceType`/`sourceRef`).

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

- States as a PHP enum with guarded transitions (e.g. `draft → quoted → confirmed → invoiceable →
  invoiced → closed`, `cancelled`) — exact set from the developer's practice → Q5.
- Lines from articles (snapshot: code, text, unit, price, tax code, revenue account) or free lines.
- Quote and order confirmation as PDF from the order.
- VAT on quote/order **for display only** via `module-vat`.
- "Invoice": builds an `InvoiceDraft` from one or several orders (collective invoice), calls
  `InvoicingService::issue()`, records the invoice number per line. Payment state is **asked** from
  debtor, never pushed back.
- order posts nothing.

---

## 8. Migration from wdv (per installation, the developer's books first)

- Reader on the wdv SQL dump / DB, mapping as code (ADR-032: source-agnostic reader seam, import
  identity, snapshot staging).
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
| P0 | ADRs (§10); answers to §11 | ADRs approved |
| P1 | `Money` in kernel; `persistence-doctrine` (lazy EM, Money type, migrations in CLI); `module-vat` with backend + CH seed | Tests green; EM boots only on demand |
| P2 | financial: accounts, fiscal years/periods, manual entries with change log, `LedgerService`, reports | Manual bookkeeping usable |
| P3 | debtor: master, `InvoicingService`, manual invoice, credit note, PDF + QR-bill, gateway | Invoice → journal round trip without order |
| P4 | debtor: payments, discount/loss with VAT, CAMT.054, dunning | Subledger = collective account to the Rappen |
| P5 | financial: VAT return CH, close states, year-end | ESTV form from posted codes |
| P6 | order: quote, order, lines, collective invoice → `InvoicingService` | Order → invoice → journal |
| P7 | wdv migration, developer's installation first, then the 4 clients | Acceptance per §8 |

Each phase leaves the framework runnable. Tests as plain PHP scripts in `tests/`, like the existing
ones.

---

## 10. ADRs to write (P0)

1. **Business module cut** — order / debtor / financial / vat, dependency direction, invoicing in
   debtor, order posts nothing, financial open to any posting source, opaque origin + idempotency,
   one transaction, accounting port.
2. **Doctrine persistence package** — own package, lazy (ADR-001), Doctrine only where needed,
   business modules bind to Doctrine directly (deviation from the unified-repository promise in
   `persistence-architecture.md`), migrations only.
3. **VAT model** — tax codes with dated rates as managed data, country packs, computed once on the
   invoice and carried, net posting method, discount/loss correction from the snapshot.
4. **Ledger and money** — generated vs. manual entries, close states, change log, numbering, integer
   money, `DECIMAL` ↔ minor-units mapping.

---

## 11. Open questions

| # | Question | Proposal |
|---|---|---|
| Q1 | Who owns the customer master? debtor (`Debtor`), a separate contacts module, or the member account? | **Decided 2026-09-18:** own `module-contact` with n typed addresses per contact (§4a); debtor and order both consume it. |
| Q2 | Articles: minimal article master in order now, or a separate catalog module? | **Changed 2026-09-18:** shop + order are two consumers (2 shop clients) → own `module-article`; details open in §4b (A1–A8). |
| Q3 | After the VAT return: freeze only tax-carrying lines, or the whole period? | **Decided 2026-09-18:** filed = closed; the return and all tax-carrying lines of the period are frozen; the rest stays editable until the accounting close. |
| Q4 | Change log + number gaps for editable manual entries acceptable? | **Decided 2026-09-18:** yes — every change is traceable. |
| Q5 | The exact order states and transitions from practice | The developer's list; the plan's set is a placeholder. |
| Q6 | Foreign currencies (EUR invoices) needed at start? | Only if one of the 5 installations invoices in foreign currency today. |
| Q7 | Recurring invoices (contracts/subscriptions) at start? | Only if used today; the `InvoicingService` is ready for it. |
| Q8 | Database engine at the hoster (MySQL / MariaDB version) | Check per installation; minimum defined in the Doctrine ADR. |
| Q9 | Newsletter tool (not in z77 yet) as a consumer of contacts? | Yes as a consumer, but subscriptions (e-mail, list, double opt-in consent, unsubscribe) stay in the newsletter module — a subscriber is often not a contact at all. Optional link subscription → contact; contacts can be an audience source. `Contact` carries no newsletter fields. |

---

## 12. Risks

- **Migration is the largest single effort** — five installations, wdv data with known
  inconsistencies. Mitigation: developer's installation first, acceptance by balances, not by
  sampling.
- **Doctrine is the first heavy dependency** in the z77 world — hosting (PHP extensions, memory),
  update cadence of ~15 packages. Mitigation: isolated package, only installed where used.
- **Scope pull from wdv** (stock, shop, shipping tariffs, creditor) — explicitly out until needed.
