# Bauplan — order, debtor, financial, vat, contact, article

**Status:** `[CONCEPT]` — before external review. Nothing built.
**Date:** 2026-09-18, updated 2026-09-20 (article model A1–A7 decided, Q7 answered, module cut and
build phases final, all open questions answered, scope narrowed to order / financial / debtor / article)
**Basis:** [`order-financial-review-2026-09-18.md`](order-financial-review-2026-09-18.md) — findings
in wdv-6.2.2 and decisions D1–D8 (§7 there). This plan does not repeat the wdv analysis.
**ADRs:** to be written in phase P0 (§10).

## Where we continue (as of 2026-09-20, end of day)

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
Q6 (no foreign currency; the ledger is base-currency only) and Q8 (MariaDB 10.6, one charset). Two
steps remain:

1. The **external review of this plan with Fable** (agreed 2026-09-18). The prepared brief, with the
   five decisions most expensive to reverse, is in
   [`order-bauplan-review-request-2026-09-20.md`](order-bauplan-review-request-2026-09-20.md).
2. The **five ADRs** (§10) — phase P0. Then P1 starts.

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
| — | Correction principle (2026-09-20) | **Nothing is corrected by deletion or by a special state — always by a counterpart of the same shape.** A reversal against a journal entry, a credit note against an invoice, an order with negative quantities against an order. This is why there is no cancelled order status (§7) and why generated entries are immutable (below): the counterpart records *what happened*, a deleted row or a `cancelled` flag records only that something did not. |

---

## 2. Packages and dependency direction

| Package | Namespace | Depends on |
|---|---|---|
| `z77/kernel` (existing) | `Z77\Core`, `Z77\Shared`, `Z77\Persistence` | — (no Composer deps, stays so) |
| `z77/persistence-doctrine` (new) | `Z77\Persistence\Doctrine` | kernel, doctrine/orm 3, doctrine/dbal 4, doctrine/migrations |
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
| `Contact` | Doctrine | person or company, name parts, language, e-mail/phone, optional `memberAccountId`, active |
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
| **A3** | **Two-stage article: product + variant.** Product = the marketing unit (one page, one URL, text, images, producer, attributes). Variant (SKU) = the stock, price and invoicing unit (own article number, own purchase and sales price, own stock). Vintage and size are variants. **A variant-less article is the normal case** — in the service and membership installations no article has a variant at all — so with no variant the UI shows no second stage. An invoice line always references the variant. |
| **A4** | **Price per variant with "valid from" and history**, net/gross declared per price. An offer price stays a regular feature (it is in use, but in wdv it is a client extension that core code nonetheless calls). The wdv future-price mechanism is dropped — it is effectively unused. |
| **A-Pos** | **Position types belong in the model:** `service` / `lump sum` / `text` (and `subtotal` later), and the position text is editable from the start. Otherwise the text-only pseudo-articles come back. This requirement is invisible in the shop case and shows up only in the service case. |

Consequences for the model:

- **Attributes are declared per product group and typed** (wine has grape and vintage, oil has
  pressing) — **no EAV**, no runtime meta-model; values in one narrow indexed table. Magento's EAV
  is the warning, not the example.
- **Attribute values are controlled**, not free text, or a filter offers the same grape three
  times. Multi-valued where reality is multi-valued (cuvée).
- **The producer is a `Contact` in a supplier role** (§4a) — not a new entity, not a tree level.
  Then the winemaker is the same record you purchase from. Named neutrally (manufacturer /
  supplier), because the module stays industry-neutral (D1).
- **Facets belong to the shop, not to the article.** The article carries attribute values; which of
  them appear as filters, and in which order, is a shop setting. Otherwise every order-only
  installation drags the facet logic along.
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
lives in `module-article`, because that is where the balance is; it knows no caller, and the trigger
reaches it as an **opaque reference** — exactly the pattern `LedgerService::post()` uses in financial
(§5.4). One quantity, one write path, no exceptions.

**The order status is the control table.** A stock movement is triggered by a status change and by
nothing else, and what it books follows from the **difference of the flags** between the old and the
new status (`reservesStock`, `consumesStock`) — never from a special case per status name. From
"ordered" (reserves) to "delivered" (consumes) means: release the reservation, book the balance down.
That calculation exists **once**. Status change and stock movement commit in **one transaction**, or
the balance drifts away from the status; and the same change applied twice must not book twice — the
same idempotency discipline as posting to the ledger.

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

- **Status is data, not an enum** (decided 2026-09-20, Q5). `OrderStatus` is file-based (a dozen rows,
  like tax codes and payment terms): `code`, multilingual `label`, `level` as a progress number, and
  the flags the code actually reacts to — `reservesStock`, `consumesStock`, `invoiceable`,
  `invoiceInProgress`, `final`. Code never asks "is the status `if`", it asks "does this status
  consume stock". Seeded with the set proven in wdv (offer, reserved, ordered, delivered, to be
  invoiced, **in invoicing**, invoiced, part-paid, paid, done, done without stock effect); a project
  adds its own rows under `override/` without touching the framework (Rule 1). Measured: the set is
  almost identical across all installations, yet none uses all of it and each adds rows of its own —
  which is exactly why a hard enum is wrong here.
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
- **The status change is the only trigger of a stock movement**, and it calls the stock service in
  `module-article` inside the same transaction (§4b, "Stock: one write path"). order never writes a
  balance itself.
- Lines from articles (snapshot: code, text, unit, price, tax code, revenue account) or free lines.
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
| P0 | ADRs (§10); answers to Q5, Q6, Q8 (§11) | ADRs approved |
| P1 | `Money` in kernel; `persistence-doctrine` (lazy EM, Money type, migrations in CLI); `module-vat` with backend + CH seed; `module-contact` (§4a) | Tests green; EM boots only on demand; a contact with n typed addresses exists |
| P2 | financial: accounts, fiscal years/periods, manual entries with change log, `LedgerService`, reports | Manual bookkeeping usable |
| P3 | debtor: master, `InvoicingService`, manual invoice, credit note, PDF + QR-bill, gateway | Invoice → journal round trip without order |
| P4 | debtor: payments, discount/loss with VAT, CAMT.054, dunning | Subledger = collective account to the Rappen |
| P5 | financial: VAT return CH, close states, year-end | ESTV form from posted codes |
| P6 | article: product group tree, product + variant, typed attributes, prices with history; stock with one write path, movement journal, correction and stocktake (§4b) | An article resolves to a variant with price, tax code and revenue account; the sum of the movements equals the balance |
| P7 | order: quote, order, positions with types, collective invoice → `InvoicingService`; **creatable from outside** with an idempotency key (§13) | Order → invoice → journal, and a second identical call creates nothing |
| P8 | wdv migration, developer's installation first, then the clients | Acceptance per §8 |

Each phase leaves the framework runnable. Tests as plain PHP scripts in `tests/`, like the existing
ones.

Sequence rationale: contact rides along in P1 because debtor needs it in P3 and it is small
master data; bookkeeping comes before invoicing so an invoice has somewhere to post; article comes
before order because an order line resolves to a variant. Subscriptions, shipping and a shop are not
phases here — see §2 and §13.

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
   money, `DECIMAL` ↔ minor-units mapping, **base currency only** (§5.2), and the correction principle
   (§1) as the rule that ties reversal, credit note and negative order together.
5. **Order status and stock movements** — status with behaviour flags instead of a PHP enum, the two
   transition rules, no cancelled state, returns as negative quantities, project-specific statuses
   under `override/` (§7, Q5); and the stock side hanging off it: one write path, movement journal,
   flag difference as the only rule, one transaction, idempotency, correction without an order, and
   the stocktake block (§4b). One ADR, because the status *is* the stock control.

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
| Q10 | Does the stock **value** go into the bookkeeping? | **Open, deliberately not decided here.** If stock sits on the balance sheet, a stocktake difference has to be posted as well, and that needs a valuation method (average cost, FIFO, lower of cost or market). A topic with its own weight: it does not belong in A7 and blocks nothing in P1–P8, but it needs an answer before the stock of a trading installation is reported. |
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
