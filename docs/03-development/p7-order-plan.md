# P7 slim — order, article (slim), the invoicing and the stock seam

**Status:** `[IN PROGRESS]` — written 2026-10-14 from the owner decisions of the same day. **Owner go
2026-10-14 («Alles ok los»):** the six recommendations of §14 accepted, the two ADR addenda (§2)
approved and written ([ADR-040 addendum 2026-10-14](../02-decisions/adr-040-business-module-cut.md),
[ADR-043 addendum 2026-10-14](../02-decisions/adr-043-order-status-and-stock-movements.md)).
Built: steps 1 and 2 (`module-article` slim and the stock seam — [`article.md`](../topics/article.md));
steps 3, 5 and 6 (`module-order`: status as data, schema, the write path — [`order.md`](../topics/order.md));
step 4 (the debtor seams) in parallel. The board on the developer canvas
([`backend-screen.md`](../01-handbook/backend-screen.md), checklist 1) is still open for the order
screens (step 7).
**Basis:** [`order-debtor-financial-bauplan.md`](order-debtor-financial-bauplan.md) (§1 correction
principle, §2 module cut, §4b article and stock, §7 order, §9 phases, §13 creation from outside),
[ADR-040](../02-decisions/adr-040-business-module-cut.md) (incl. addendum 2026-10-13),
[ADR-043](../02-decisions/adr-043-order-status-and-stock-movements.md),
[ADR-039](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md) (addendum
2026-10-08: abstract + empty entity), [ADR-047](../02-decisions/adr-047-controller-led-windows.md),
[ADR-049](../02-decisions/adr-049-form-action-bar.md),
[ADR-050](../02-decisions/adr-050-module-navigation-seeds.md),
[`debtor.md`](../topics/debtor.md) (InvoicingService, lock order, booking print),
[`persistence-doctrine.md`](../topics/persistence-doctrine.md) (`NumberRange`, transaction port,
open-work registry), [`persistence-file.md`](../topics/persistence-file.md) (seed-once),
[`listing.md`](../topics/listing.md), [`css-backend.md`](../topics/css-backend.md) (action cell per area).
**Legacy read (not copied):** wdv-630 `order/src/Entities/ShopState.php`, `ShopOrder.php`,
`ShopPosition.php`, `Libraries/Manager/ShopArticleStockManager.php`, `WarehouseManager.php`,
`common/src/Traits/Stockable.php` and their callers — findings in §8.4.

---

## 1. Goal and scope

Close the daily flow **Auftrag → Rechnung → Zahlung → Mahnung**: the office captures an order
(quote or order) with article and free positions, moves it through statuses, prints the delivery
note, and books the invoice straight from the order. Payments, CAMT and dunning exist (P4).

**In P7 slim:** `module-article` slim (article master, the stock flag, the stock SEAM without a
journal), `module-order` (order with head and positions, status as data with flags and a change
log, delivery note, quote / order confirmation PDF, invoice preview from the order, the booking
print from the order, the payment state asked from debtor), the debtor seams it needs, the
backend area «Aufträge» and its master data.

**Not in P7 slim:** the stock movement journal, reservation and stocktake (P7b — the seam is
built, §9); collective invoice (§12); creation from outside with idempotency key (§12); the
product-group tree, variant options, images, part lists (P6 full / shop); return «at one click»
(negative quantities are allowed by hand); PDF storage in module-dms; e-mail dispatch.

---

## 2. Decisions

### Owner decisions 2026-10-14 (binding)

| # | Decision |
|---|---|
| O1 | **Order and invoice are TWO records.** The office edits only the ORDER (head: subject, dates, customer = debtor contact, delivery address, positions). The INVOICE is created only at the **booking print** from the order — a frozen snapshot with number, QR part and the one posting (existing `InvoicingService`). For order-based invoices there is **no** separate editable invoice draft: the preview PDF comes straight from the order, «zu fakturieren» is the draft. The order screen shows its invoice(s) and the **payment state** (offen / teilbezahlt / bezahlt) asked from debtor through a port — «bezahlt» is not an order status. |
| O2 | **Order number:** its own simple running number from the number-range management (`NumberRange`), not `YYMMnnnn`. |
| O3 | **Positions are ARTICLES — P6 slim comes WITH P7.** Article = number, name, unit, price, tax code, revenue account (must be «auf Belegen wählbar»), flag «führt Bestand». On the position text and price stay overridable (snapshot, plan §4b A-Pos); line types as on the invoice (service / lump sum / text). |
| O4 | **Status as DATA** (file-based master data, seed-once, maintained in the backend, new statuses without code). Seed: Angebot/Offerte → Terminauftrag → geliefert → zu fakturieren → gebucht (invoice booked, financial posted) → erledigt mit Lagerbuchung / erledigt ohne Lagerbuchung. Each status carries behaviour FLAGS and a level. Forward free, backward barred once the invoice is booked. Every change logged (who, when, from → to). |
| O5 | **Stock** — «Lagerbuchung nur an einem Ort, nur ein Code, immer statusabhängig; es gibt auch das Feld reserviert»: one stock service in the article module; the order computes per position the TARGET quantities (reserved, consumed) = quantity × flags of the current status and calls ONE method on every status change AND every position change (add / edit / delete); stock and reserved are sums of a MOVEMENT JOURNAL, never counters. P7 slim: flags on status and article plus the seam; journal and service body in P7b without touching order. |
| O6 | **Delivery note:** a PDF; printing it sets the order to «geliefert» or directly «zu fakturieren» (mechanism: decision D6 below, open question Q1). |
| O7 | **Collective invoice LATER. Creation from outside LATER** — the design must not block either (§12). |
| O8 | **Backend:** area «Aufträge» — first entry «Aufträge» with «+ Neuer Auftrag» in the action cell, then Rechnungen, Debitoren, Zahlungseingänge, Mahnungen; master data under Stammdaten › Aufträge (Auftragsstatus, Artikel …). Order editor as a WINDOW (ADR-047) like the invoice. |

### Decisions of this plan (confirmed with the owner's build go 2026-10-14)

| # | Decision | Why |
|---|---|---|
| D1 | **Quote and order are ONE record** (`SalesOrder`); a quote is an order in a status flagged `offer`. The number is drawn at the FIRST save and stays when the quote becomes an order. | One record, one number, no copy step; the status says what it is (O4). |
| D2 | **Free lines stay allowed**: the article reference is NULLABLE. A free line picks its tax code and revenue account like an invoice line; a text line never references an article. | Plan §7 (review 2026-09-20) and the service case of A-Pos; the invoice editor already does it. |
| D3 | **On an ARTICLE line** text, quantity, unit, price and discount are editable (snapshot); **tax code and revenue account come from the article and are not edited on the line** — a different account is a free line. | The article is the accounting classification (A1); an override per line would make the article's account a mere proposal. |
| D4 | **The booking print is ONE unit of work in order**: lock the order rows → debtor issues AND books the invoices (`InvoicingService::invoiceAndFinalize()`, §7) → order sets the status flagged `invoiceBooked`, links the invoice, logs the change. All or nothing. | Without atomicity a crash leaves a booked invoice behind an order still «zu fakturieren» — the next click books twice. |
| D5 | **The invoice is linked on the ORDER** (`sales_order.invoice_id`, not per line, not unique). | No partial invoicing (ADR-043 decision 8: lines move together, a partial delivery is an order split); a later collective invoice is N orders → one invoice id. Deviates from plan §7's «records the invoice per line». |
| D6 | **Delivery note target = a status flag** `deliveryNoteTarget` (at most one active row; seed: «geliefert»). The print moves the order there only FORWARD; a reprint at or past that level changes nothing. | Data, not code (O4); a service business sets the flag on «zu fakturieren» once instead of choosing at every print (Q1). |
| D7 | **The order is editable while it has no invoice and its status is not `final`**; with an invoice it is read-only except a forward status change. | The bar comes from the invoice (ADR-043 decision 4); a booked invoice's source must not change under it. |
| D8 | **No delete of an order.** Cancelling before invoicing = «erledigt ohne Lagerbuchung» (ADR-043 decision 5); after invoicing a credit note in debtor. | The number is drawn and printed on quotes; no cancelled state, no gap without trace. |
| D9 | **A contact without debtor profile may be the customer** (quotes to prospects). The profile is opened at the booking print, in its own unit of work BEFORE the booking (`DebtorProfileService::save()` with the default terms, as the role «Debitor» does) — never inside it. | Opening it inside would draw `customer` next to `document.YYMM` and widen the lock order; a profile left over after a refused booking is harmless (switch «Debitor» off while no document exists) (Q4). |
| D10 | **The invoice gets an optional `subject`** (debtor: `InvoiceDraft::$subject`, column `invoice.subject`, expand only, printed under the title; the manual invoice editor gets the field too — Rule 9). Order passes «Auftrag {number} · {Betreff}». | The order's subject must reach the customer's invoice; a text line would mix header facts into the lines. |
| D11 | **Totals are computed once — by debtor.** The order window, the order PDF and the list total come from `InvoicingService::preview()` (the same `compose()` the booking print runs: VAT per code, 0.05 rounding). The order stores `total_net` / `total_tax` / `total_gross` written by its one write path at each save, for the list only. | One calculation (Rule 2); the preview IS what the booking print will issue. |

### Amendments the owner decisions require (ADR addenda — approved by the owner 2026-10-14, written)

- **ADR-040 addendum 2026-10-14:** a source may issue AND book in one unit of work
  (`invoiceAndFinalize()`), without a stored draft, when the source itself is the draft (O1).
  Decision 6 is sharpened: forbidden are statuses the RECEIVABLES side writes or that track the
  payment (`part-paid`, `paid`); «gebucht» is set by order's own booking step in the same unit of
  work — debtor still never writes into order.
- **ADR-043 addendum 2026-10-14:** (1) the seed of O4 replaces decision 1's seed; `invoiceInProgress`
  is replaced by `invoiceBooked` (there is no invoice draft stage for orders), plus `offer` and
  `deliveryNoteTarget`; (2) decision 11 «the status change is the only trigger» becomes «the
  order's writes are the only trigger — status change and position change; the quantities still
  follow from the status flags only» (O5); (3) the label stays German like every master-data label
  (a customer-facing label comes with its first consumer — shop / portal).

---

## 3. Module cut

Follows ADR-040 / plan §2; one deviation (article → mandator).

| Package | Namespace | Requires | New? |
|---|---|---|---|
| `z77/module-article` | `Z77\Module\Article` | kernel, persistence-doctrine, module-vat, **module-mandator** (the soft account check `LedgerAccountCheck` and the `accountDatalist` partial — deviation from §2's table, no cycle: mandator → kernel, doctrine, vat) | new |
| `z77/module-order` | `Z77\Module\Order` | kernel, persistence-doctrine, module-vat, module-contact, module-mandator (letterhead partial), module-article, module-debtor | new |
| `z77/module-debtor` | — | unchanged | gains four seams (§7) |

```text
module-order ──→ module-debtor ··→ module-financial   (port, suggest)
     │ └──→ module-article ──→ module-mandator ──→ module-vat
     └──→ module-contact
```

- **One way only:** debtor never names `Z77\Module\Order`, article never names order or
  `OrderStatus` (harness checks, §11). Order reaches debtor only through the seams of §7 and the
  read port `PaymentStates`; article through its repositories (read) and `StockLedger` (write).
- Both packages are required in the monorepo ROOT `composer.json` only, like debtor; a project
  requires them when it needs them.
- Inside: ADR-019 layers (`Entities/`, `Repositories/`, `Services/`, `Validators/`, `Ui/`, `Pdf/`,
  `Close/`), backend fragments as `Ui/{X}ControllerTrait` + `Ui/{X}Layout`, thin hosts in
  `module-backend` under a new group `Ui/Controllers/Order/` → URLs `/backend/order/order/…`,
  `/backend/order/order-status/…`, `/backend/order/article/…` (Rule 6).

---

## 4. Data model

### 4.1 module-article (Doctrine) — P6 slim

A3 (product + variant) is kept in the SCHEMA, hidden in the UI (Q2): «no second stage» is a UI
fact, never a schema fact, and every order line references the variant. ADR-039 addendum (b):
`AbstractProduct` + empty `Product`, `AbstractVariant` + empty `Variant`.

| Table | Columns |
|---|---|
| `article_product` | `id`, `name`, `tax_code` (by code, no FK — ADR-043/19), `revenue_account` (by number), `stock_relevant` («führt Bestand»), `active`, `created_by/at`, `changed_by/at`, `version` |
| `article_variant` | `id`, `product_id` (FK), `number` (VARCHAR 32, **unique**, typed — A2: free, never derived), `label` ('' = the default variant), `unit`, `active` |
| `article_price` | `id`, `variant_id` (FK), `valid_from` (DATE), `amount` (`MoneyType`), `price_mode` (net / gross) — unique (`variant_id`, `valid_from`); A4 history, see Q3 |

- One form, one save: name, number, unit, price (+ «gültig ab», default today; a change writes a
  new price row, the same day replaces that day's row), net / gross, tax code (active codes,
  `TaxCodeRepository::selectable()`), revenue account (`LedgerAccountCheck::documentAccounts()` —
  type «Ertrag» AND «auf Belegen wählbar», soft: without financial nothing is refused),
  «führt Bestand». Write path `Services/ArticleService::save()` / `setActive()`.
- An article referenced by any order line is deactivated, never deleted (the reference rule
  applied to rows); an unreferenced one may be deleted.
- Migration with `z77-db setup` (one module migration, expand only). No number range in slim.

### 4.2 module-order — file-based master data

`Entities/OrderStatus` — `#[Entity('file', 'framework/order/order_statuses.json')]`, seeded once
from `data/framework/order/order_statuses.default.json` (ADR-024, UTF-8 without BOM — DATA-JSON-001),
referenced BY `code`, code immutable, deactivated never deleted (ADR-043/19), one write path
`Services/OrderMasterData` without a remove method, `setActive()` a pure state change (the debtor
model). Fields: `code`, `label` (German), `level` (int), and the flags:

| Flag | Meaning for the code |
|---|---|
| `offer` | the order is a quote: PDF title «Offerte», not invoiceable |
| `reservesStock` | target reserved = line quantity |
| `consumesStock` | target consumed = line quantity |
| `invoiceable` | the booking print is offered |
| `invoiceBooked` | the status the booking print SETS (selected by this flag) |
| `deliveryNoteTarget` | the status the delivery-note print sets (D6) |
| `final` | the order is closed |

**Invariants** (validator on save; ADR-043 decision 2, extended): `final ⇒ ¬invoiceable ∧
¬invoiceBooked`; `consumesStock ⇒ ¬reservesStock`; `offer ⇒ ¬reservesStock ∧ ¬consumesStock ∧
¬invoiceable`; `invoiceBooked ⇒ ¬invoiceable`; `invoiceBooked` and `deliveryNoteTarget` each on at
most ONE active row; the `invoiceBooked` row's level > every `invoiceable` row's level (the booking
print must be a forward move). A missing active `invoiceBooked` row refuses the booking print with
what to mark — nothing is ever invented.

**Seed** (codes English kebab — they are keys; labels German):

| code | label | level | offer | reserves | consumes | invoiceable | invoiceBooked | deliveryNoteTarget | final |
|---|---|---|---|---|---|---|---|---|---|
| `offer` | Offerte | 10 | ✓ | | | | | | |
| `scheduled` | Terminauftrag | 20 | | ✓ | | | | | |
| `delivered` | Geliefert | 30 | | | ✓ | | | ✓ | |
| `to-invoice` | Zu fakturieren | 40 | | | ✓ | ✓ | | | |
| `booked` | Gebucht | 50 | | | ✓ | | ✓ | | |
| `done` | Erledigt mit Lagerbuchung | 90 | | | ✓ | | | | ✓ |
| `done-no-stock` | Erledigt ohne Lagerbuchung | 90 | | | | | | | ✓ |

A new order starts in the ACTIVE status with the LOWEST level (no code, no extra flag); the
create form may pick another.

### 4.3 module-order — Doctrine

| Table | Columns |
|---|---|
| `sales_order` | `id`, `number` (INT, **unique** — range `order`), `status_code` (by code), `subject` (VARCHAR 200), `order_date`, `service_from`, `service_to` (nullable), `contact_id` (FK `contact`), `price_mode`, `currency` (base, carried like the invoice), `payment_target_code` (nullable — the QR part at booking; editor preselects the first active), `ship_*` (debtor's `AddressSnapshot` embeddable, all empty = no separate delivery address — Rule 9 reuse), `total_net` / `total_tax` / `total_gross` (D11), `invoice_id` (FK `invoice`, nullable, NOT unique — D5), `source_type` / `source_ref` (opaque origin; `backend` from the screen — §12), `created_by/at`, `changed_by/at`, `version` (`#[ORM\Version]`) |
| `sales_order_line` | `id`, `order_id` (FK, cascade), `position`, `parent_line_id` (one level, as `invoice_line`), `type` (debtor's `LineType`: service / lump-sum / text — `rounding` refused), `variant_id` (FK `article_variant`, nullable — D2), `article_number` (snapshot), `text`, `quantity` (`DECIMAL(12,3)`, negative allowed), `unit`, `unit_price` (money, null on text), `discount_percent` (hundredths), `tax_code`, `revenue_account` |
| `sales_order_status_change` | `id`, `order_id` (FK), `from_code` (null at creation), `to_code`, `trigger` (`create` · `status` · `delivery-note` · `booking-print`), `reason` (nullable — stock reversal, §9), `changed_by`, `changed_at` |

- The timestamps of quote, confirmation, delivery and invoicing are DERIVED from the change log
  (ADR-043 decision 4), never parallel columns.
- **Number range `order`**: created at 0 by the module's migration (the `customer` model,
  `NumberRange::create()` in the data step — schema and data in SEPARATE migration files, debtor.md
  rule since 2026-10-13); drawn as the first write of `OrderService::create()`. Bare integer
  (DOCTRINE-NR); start value Q5. A wdv import raises it to the highest imported number (P8).
- No line amount column: the amounts printed on screen and PDF come from the preview (D11).

---

## 5. Write paths and services

All writes of an order go through **`Services/OrderService`** (one write path, DTO in — no
`Request`, so a later API or subscription run calls the same thing, §12). Every method takes the
VERSION the caller saw where a row exists; a stale one is `OrderConflictException`; refusals are
`OrderRefusedException` with a stable `reason` and a German message (the debtor model).

| Method | What it does |
|---|---|
| `create(OrderDraft): SalesOrder` | validate before the unit of work → draw `order` (first write) → insert order + lines + log row `create` → stock call |
| `update(id, version, OrderDraft): SalesOrder` | only while D7 allows; lines replaced whole (orphan removal, the `reinvoice()` model) → stock call with the FULL new state |
| `changeStatus(id, version, toCode, ?StockConfirmation): SalesOrder` | transition rule (§6) → status + log row `status` → stock call |
| `printDeliveryNote(id, version): SalesOrder` | if a `deliveryNoteTarget` row exists and lies FORWARD: status + log row `delivery-note` + stock call; otherwise nothing is written |
| `book(list<{id, version}>, invoiceDate, ?StockConfirmation): list<Invoice>` | the booking print (§7) |

- `OrderDraft` / `OrderLineDraft`: plain data — contact id, subject, dates, price mode, payment
  target code, delivery address snapshot, lines (type, variant id or null, text, quantity string,
  unit, unit price, discount, tax code, account, children). An article line with an empty text /
  price / unit takes the article's values AT SAVE (no JavaScript needed — Rule 7); the price is
  the one valid at the order date, converted to the order's price mode with `VatCalculator` when
  the article's mode differs, then a snapshot.
- Line validation reuses the shared checks — active tax code (`module-vat`), account type and
  «auf Belegen wählbar» (`LedgerAccountCheck`, soft) — for an early message; the BINDING check stays
  debtor's at the booking print (no second rule set, Rule 2).

### Lock order of module-order (consistent with debtor's)

**`order` range (create only) → `sales_order` rows (EXCLUSIVE, by id) → [booking print only:
debtor's chain `document.YYMM` (by name) → debtor profile rows SHARED → invoice inserts →
`journal-entry.*`] → stock rows (P7b, by variant id) → the order's own writes.**

- Every order unit of work takes its row locks with locking reads (`SalesOrderRepository::lockIds()`)
  and compares the version there — **no plain read before the last lock** (debtor.md: REPEATABLE
  READ, the read view of the first plain read). What it must know to choose its locks (the drafts
  of a booking print) is read and composed BEFORE the unit of work and judged again by version
  under the lock.
- Stock comes AFTER debtor's chain: the stock service reads the journal, and debtor composes and
  judges only after its own locks. No debtor or financial unit of work ever locks an order or a
  stock row, and stock never posts (ADR-043/18) — no cycle.
- A lock conflict that still happens is recognised with debtor's `LockConflict::is()` and answered
  «nichts gespeichert, neu laden und nochmals versuchen»; no automatic retry.

---

## 6. Status change rules

- **Forward** = target level ≥ current level (equal level = lateral, allowed: the two «erledigt»).
  **Backward** = lower level: allowed only while the order has NO invoice (ADR-043 decision 4 —
  the bar comes from the invoice). Only ACTIVE statuses are offered; an order keeps a deactivated
  one.
- The flag `invoiceBooked` row is reached ONLY by the booking print (the confirm list hides it,
  the service refuses it as a target of `changeStatus()` — otherwise «gebucht» without an invoice).
- Every change writes a log row; the window's «Verlauf» lists them.
- **No code path selects a status by its `code`** (ADR-043 consequence; harness N1 greps for it):
  initial = lowest level, delivery note = `deliveryNoteTarget`, booking = `invoiceBooked`.

---

## 7. The invoicing seam to debtor

Four additions to `module-debtor`; the manual invoice, credit note and fee paths (`invoice()`,
`reinvoice()`, `discard()`, `finalize()`) stay exactly as they are.

| Seam | Contract |
|---|---|
| `InvoicingService::preview(InvoiceDraft, bool $openProfile = false): Invoice` | `compose()` + materialize into a TRANSIENT `Invoice` (state `invoicing`, never persisted, nothing locked or written); `openProfile` composes against the unsaved default profile like `invoice(openProfile: true)`. Refusals are the usual `InvoiceRefusedException` — the order window lists them as «Vor dem Buchungsdruck beheben». `InvoicePdf::render()` draws it with the existing PREVIEW layout (watermark, no number, no payment part). |
| `InvoicingService::prepareDocumentRanges(list<DateTimeImmutable>)` | `NumberRange::create('document.YYMM')` per month, outside any unit of work (idempotent, committed at once) — closes DEBTOR-NR-001 for this path. Called by order before it opens its unit of work. |
| `InvoicingService::invoiceAndFinalize(list<InvoiceDraft>): list<Invoice>` | Kind `invoice` only. Joins the caller's open unit of work (or opens its own). Inside, in debtor's lock order: the month ranges locked by NAME (`create()`) → each draft's profile row SHARED → `compose()` (reads after the locks) → insert → payment part taken from the target and the mandator NOW and checked (`bookedPaymentPart()`, `QrBill::problems()` → `payment-part`) → numbers drawn in the order invoice date, then id → `PostingBuilder` → `AccountingGateway::post()` → `final`. Any refusal rolls back the WHOLE unit of work, the order's writes included. Origin `order` / `{order number}`, line origin `order-line` / `{line id}` (opaque). Posting key unchanged (`invoice:{id}:final`). |
| `Services/PaymentStates::of(list<int> $invoiceIds): array<int, PaymentState>` | The read port of ADR-040 decision 6. ONE query for a page of orders (the `openItems()` aggregates): `open` (open = gross), `partial` (0 < open < gross), `paid` (open ≤ 0 — credit notes included), with the open amount. Debtor never calls order; order asks. |

Plus D10 (`InvoiceDraft::$subject`, `invoice.subject`).

**How an order becomes its invoice** (`Invoicing/OrderInvoiceDraft::of(SalesOrder, invoiceDate)` in
module-order — a pure mapper): contact, invoice date (the confirm, default today), service date
(head; empty → the invoice date), price mode, payment target code, subject, address `null` (debtor
snapshots the contact's invoice address at booking), the lines 1:1 with type, parent, text,
quantity, unit, price, discount, tax code, account. Payment terms `null` → the profile's.

**The booking print from the order** (`OrderService::book()`):

1. Before the unit of work: per order the debtor profile ensured (D9, own unit of work); the drafts
   composed from the orders at the versions the screen showed; `prepareDocumentRanges()`.
2. Unit of work: lock the order rows (id order), compare versions, refuse an order already linked
   to an invoice or not `invoiceable` → `invoiceAndFinalize(drafts)` → per order: `invoice_id`,
   status `invoiceBooked`, log row `booking-print` → stock call (consumption usually unchanged).
3. Answer: the PDF of the booked invoices (`InvoicePdf::combined()`) in a new tab — debtor's
   booking-print screen pattern.

**Idempotent on the screen**: a second submit of the same selection — every order linked to a
final invoice at the shown version + 1 — reprints, books nothing (debtor's `invoiceBookedBy()`
model). The service itself refuses an order that already has an invoice.

**What changes for debtor's screens:** nothing in daily use. An order-based invoice is never in
«In Fakturierung» (it is born `final`), so `InvoicingInProgressCheck` never sees it; credit notes
against it are made on the invoice detail as before. The invoice detail shows its origin as text;
a link back to the order would make debtor know order — not built.

---

## 8. The stock seam for P7b

### 8.1 Contract (in module-article, built in P7 slim)

```php
namespace Z77\Module\Article\Stock;

interface StockLedger
{
    /** Bring ONE source document to the given target state; books only the difference. */
    public function bringTo(StockRequest $request): StockResult;
}

final class StockRequest   // plain data
{
    public string $sourceType;        // 'order'
    public string $sourceRef;         // the order id
    public string $trigger;           // 'status:{from}>{to}' | 'lines' | 'delivery-note' | 'booking-print'
    public string $actor;
    public ?StockConfirmation $confirmation;   // reason + confirmed, for a reversal
    /** @var list<StockLineTarget> the COMPLETE current state of the document */
    public array $lines;
}

final class StockLineTarget { string $lineRef; int $variantId; string $reserved; string $consumed; } // decimal strings, 3 places
```

- **Order sends the COMPLETE state, every time** — all article lines with their targets
  (`reserved` = quantity if the status `reservesStock`, `consumed` = quantity if it
  `consumesStock`, else `'0.000'`; negative quantities stay negative = inflow, a return). Free and
  text lines are not sent. Whatever the journal holds for this source and is NOT in the request
  (a deleted line, a line whose article changed — keyed by line AND variant) is brought to zero.
  One method, one shape, for status change and position change alike (O5).
- **The service decides per variant** whether stock is kept (`stock_relevant`); a non-stock
  variant is treated as target zero — so switching the flag off later still releases what the
  journal holds. Order never reads the stock flag.
- **Quantities, never a status** cross the boundary (ADR-043 decision 11); article never knows
  `OrderStatus`.
- **Reversal**: a change that would bring CONSUMED stock back is refused with
  `StockReasonRequiredException` (naming the lines) unless the request carries a
  `StockConfirmation` (reason + confirmed) — ADR-043 decision 14. Order's screens handle it in
  slim already: the status / save / delivery-note / booking confirm re-opens with a mandatory
  reason field and resubmits with the confirmation; the reason is written to the log row.
- **Same unit of work** as the order's write (ADR-043 decision 13); joins it, never commits.
- **Selection**: `StockLedgers::default(UnifiedEntityManager, actor)` in module-article. P7 slim
  returns `PendingStockLedger` (validates the request, books nothing, never refuses); P7b returns
  `JournalStockLedger`. Order's services take an optional `StockLedger` in the constructor (the
  harness injects a spy / a refusing double — the `AccountingGateway` model) and use the default
  otherwise. **P7b changes article only.**

### 8.2 What P7b adds behind it (not in slim)

Tables keyed by variant id (`stock_movement` journal; balance and reservation as SUMS of it, a
cached balance row only if a measured need appears), the difference booking, corrections with
reason (`stocktake`, `shrinkage`, `breakage`, `correction`, `opening balance`), the dated balance
report, the stocktake process with its block through `openWorkChecks` scope `stocktake` (order
registers «stock-relevant orders not booked»), the stock column on the article list.

### 8.3 Calls in order (the only places)

`OrderService::create`, `update`, `changeStatus`, `printDeliveryNote`, `book` — each ends in
ONE private `syncStock(SalesOrder, trigger, ?confirmation)` that builds the request from the
order's current lines and status. No controller, repository or template calls stock.

### 8.4 wdv findings (read, not copied)

- **Scattered triggers:** the status is set in ~12 places across `OrderController`,
  `OrderCreateController`, `OrderAjaxController`, `InvoiceController`, `DebtorController` and
  `InvoiceManager`; only some call `WarehouseManager::manageStockByOrder()` first (e.g.
  `OrderController.php:221`, `:420`, `:489`, `OrderCreateController.php:174`, `:263`, `:336`,
  `:432` set `setShopState()` without it — `:336` even selects the state by acronym `'t'`).
  Position add / edit / delete call four further methods (`reduceQtyByStorage`, `addQtyByStorage`,
  `addReservation`, `reduceReservation`). Every forgotten call is a balance that drifts.
- **Counters, not a journal:** `article.stock`, `article.reservation` and
  `storage_location.qty` are incremented in place; nothing records why.
- **Computed against the previous status** (`$wasStockRelevant != $isStockRelevant`): editing a
  status' flag while orders sit in it corrupts every later transition — exactly what ADR-043
  decision 12 rejects.
- **The direction bug, confirmed — two sign conventions for one column:** `ShopArticleStockManager`
  (the 6.2 code, its order calls now commented out) REDUCES `reservation` when an order ENTERS a
  reserving state (`manageStockByOrder`: `$newReservationRel ? 'reduceReservationQty' :
  'addReservationQty'`) and on a new position (`reduceQtyByNewPosition`), i.e. it keeps
  reservations NEGATIVE — while its stock half and wdv-630's `WarehouseManager::manageStockByOrder()`
  ADD on entering (positive). Within each class it is consistent, so it looks right in isolation;
  data written under the old manager and continued under the new one carries reservations of
  both signs, and `Stockable::picOtherReservations()` subtracts them as if positive. The purchase
  side still calls `ShopArticleStockManager` live (`PurchaseOrderAjaxController.php:124`, `:234`,
  `PurchaseOrderCreateController.php:129`, `PurchaseOrderReportController.php:166`). For the
  migration (P8): reservations are NOT imported as numbers — they are re-derived from the open
  orders' targets (§8.1), the balance comes in as an `opening balance` correction.
- Also: the part-list parent is skipped for stock in some methods and not in others; the
  reservation basis is `picStorageSum()` for plain articles and the position quantity for part
  lists.

---

## 9. Screens

Board on the developer canvas BEFORE building (backend-screen checklist 1). *Built 2026-10-14 from the owner's description of the board (area «Aufträge», «+ Neuer Auftrag», the six tabs, the columns, the selection bar, the window with Speichern · Vorschau · Lieferschein · Buchungsdruck …, tabs Auftrag · Verlauf · Belege) — deviations listed in [`order.md`](../topics/order.md) pending (ORDER-UI-002).*

### 9.1 Navigation seed (ADR-050)

`module-order/data/framework/routing/navigation.d/module-order.json`:

| key | name | parent_key | route | sort |
|---|---|---|---|---|
| `auftraege-liste` | Aufträge | `auftraege` | `backend/order/order/list` | 0 |
| `auftragsstatus` | Auftragsstatus | `stammdaten-auftraege` | `backend/order/order-status/list` | 10 |

`module-article/…/module-article.json`: `artikel` «Artikel» under `stammdaten-auftraege`
(`backend/order/article/list`, `sort_key` 11 — built 2026-10-14; the installer added it to z77.ch). The parent is debtor's key and article does not require debtor —
ADR-050: the entry waits until debtor is installed (always the case with order).
**Debtor's seed** moves Rechnungen / Debitoren / Zahlungseingänge / Mahnungen to `sort_key` 1–4.
The merge is add-only: an existing installation keeps its order — z77.ch is changed by hand in its
`navigation.json`, as on 2026-10-13.

### 9.2 Action cells (rows for `css-backend.md` → action cell per area)

| Entry | Action cell | Kind |
|---|---|---|
| Aufträge › Aufträge | «+ Neuer Auftrag» (opens the order window) | create |
| Stammdaten › Auftragsstatus | «+ Status» | create |
| Stammdaten › Artikel | «+ Artikel» | create |

### 9.3 Aufträge › Aufträge — the list

A standard list (`Ui/OrderListing`, [`listing.md`](../topics/listing.md)), 50 per page, fetch region.
**Toolbar (hc2): view tabs derived from FLAGS** (a new status lands in a tab without code), each
with its count: «Offerten» (`offer`) · «In Arbeit» (neither offer, invoiceable, booked nor final) ·
«Zu fakturieren» (`invoiceable`) · «Gebucht» (`invoiceBooked`) · «Erledigt» (`final`) · «Alle».
Columns: state icon (opens the window), Nr. (exact), Datum (day / month / year), Kunde (name
part), Betreff (part), Status, Total (exact), Rechnung (number), Zahlung (offen / teilbezahlt /
bezahlt — from `PaymentStates::of()` for the page's invoice ids, display only; a filter by payment
state comes when asked for). ⋮ per row: «Status ändern …», «Lieferschein …», «Auftrag (PDF)».
**Selection bar** in «Zu fakturieren»: «Buchungsdruck …» (green, above the rows, `{id}:{version}`
per row) → confirm (invoice date, the orders with total and target) → POST into a new tab → PDF;
`data-submit-navigate` sends this tab to «Gebucht».

### 9.4 The order window (ADR-047, mask `order-editor`, entity `sales-order:<id>`)

Actions in ONE row under the title (ADR-049): «Speichern» (confirm, while editable) · «Auftrag»
(PDF: Offerte / Auftragsbestätigung by the `offer` flag) · «Lieferschein …» · «Rechnungsvorschau» ·
«Status ändern …» · «Buchungsdruck …» (only `invoiceable` and no invoice; a plain link that leaves
the window, the DEBTOR-WIN-002 pattern) · «Abbrechen». Radio tabs (one form, one save):

1. **Auftrag** — head: Kunde (the invoice editor's `_debtorSelect`: debtors plus contacts
   «(neu als Debitor)», «+ Kontakt» opens the new-contact window), Betreff, Auftragsdatum,
   Leistung von / bis, Lieferadresse (the contact's addresses of type `delivery` or any, copied as
   snapshot, «keine» = the invoice address), Netto / Brutto, Zahlungsziel; positions: type,
   «unter», Artikel (number with a `<datalist>` of active variants «Nr — Name»), Text, Menge (negative
   allowed), Einheit, Preis, Rabatt, MWST-Code and Konto (free lines: the shared pickers; article
   lines: shown read-only from the article, D3), «Weitere Zeilen» as submit; below the totals and
   the tax summary from the preview, and the preview's refusals as «Vor dem Buchungsdruck beheben».
   Read-only when D7 says so.
2. **Verlauf** — the status log (Datum, Wer, von → nach, Auslöser, Grund).
3. **Belege** — the invoice (number, date, gross, open, payment state — a link to debtor's detail
   window) and its credit notes; empty before the booking print.

Saving answers in place (FETCH-ROW-001): the window re-renders, the list row is replaced.
«Status ändern …» opens a small confirm window: the allowed targets (§6) as a select, and — only
when the stock service demands it — the mandatory reason (§8.1).

### 9.5 Master data

- **Stammdaten › Auftragsstatus** — a short fixed list in `level` order (code, label, level, the
  flags as badges, «Aktiv» switch, ⋮ → edit dialog). The dialog shows the flags as switches; the
  validator's invariants come back as field errors.
- **Stammdaten › Artikel** — a standard list (Nr., Name, Einheit, Preis, MWST, Konto, «führt
  Bestand», «Aktiv»), ⋮ → the article dialog (§4.1); the price history under the form, read-only.
  *Built 2026-10-14 ([`article.md`](../topics/article.md)) with one deviation:* the row's STATE ICON
  opens the article as a WINDOW (ADR-047) and there is no ⋮ — the row has one action besides the
  switch, and a modal popup cannot carry the field help (ADR-048: the help window is unusable
  beside a modal). «Löschen …» (only an unreferenced article) sits in the window's action row.

---

## 10. PDF layouts and help

Partials through the kernel facade (`pdf.md`), reusing `pdf/letterhead` (mandator),
`pdf/addressWindow`, `pdf/table` (Rule 9):

| Layout | In slim | Content |
|---|---|---|
| `pdf/order` | yes | «Offerte {nr}» or «Auftragsbestätigung {nr}» by the `offer` flag; customer, subject, dates, lines with prices, totals and tax summary from the preview; no payment part. Rendered on request, no side effect. |
| `pdf/deliveryNote` | yes | «Lieferschein {nr}»; the delivery address (else the invoice address) in the window; article number, text, quantity, unit — no prices. Printing is the POST of `printDeliveryNote()` (D6), into a new tab. |
| invoice preview | yes | debtor's `pdf/invoice` in preview mode from `preview()` — no new layout |
| invoice | — | debtor's, at the booking print |

**Help (ADR-048):** the order window (general part: the flow quote → order → delivery note →
booking print, what «gebucht» means, why payment is not a status; sections for Kunde, Lieferadresse,
Leistung von/bis — the VAT rate follows it —, Artikel, Menge — negative = return —, Preis, Status);
the status master data (one section per flag, the invariants in words); the article form (Konto —
only «auf Belegen wählbar», führt Bestand — effective with P7b).

---

## 11. Tests

Plain PHP harnesses like the existing ones. **Offline** = runs anywhere; **DB** = needs the
`z77test` credentials (PC1 — they are missing on PC2, see review 2026-10-13 §6).

| Harness / section | Kind | Proves |
|---|---|---|
| `tests/module-article.php` A | offline | validator (number, unit, price string, tax code), soft account check null-safe |
| `tests/module-article.php` B | DB | migration + `diff` clean; save product + default variant + price; price at a date; deactivate vs delete when referenced |
| `tests/module-article.php` K | offline | `StockRequest` shape; `PendingStockLedger` books nothing; article never names order (grep) |
| `tests/module-order.php` S | offline | status invariants, seed passes the validator, exactly one `invoiceBooked` in the seed |
| `tests/module-order.php` N | offline | N1: no code path selects a status by code (grep); N2: debtor and article never name `Z77\Module\Order` |
| `tests/module-order.php` T | offline | transitions (forward / lateral / backward, barred with invoice), stock targets per line incl. negative, free and text lines skipped |
| `tests/module-order.php` D | offline | `OrderInvoiceDraft::of()` mapping (types, parents, subject, service date fallback) |
| `tests/module-order.php` M | DB | migrations + `diff`; range `order`; create / update / version conflict; log rows; stock spy receives the FULL state on create, status change, line add / edit / delete; refusing double → reason flow |
| `tests/module-order.php` B | DB | booking print: invoice final with number, posting, order `booked` + `invoice_id`; ledger refusal → order unchanged; second booking refused; screen reprint idempotent; backward barred afterwards; preview writes nothing |
| `tests/module-order.php` P | DB | payment state open / partial / paid after `PaymentService::record()` and a credit note |
| `tests/module-order.php` L | DB | two booking prints in one month in parallel processes (the persistence-doctrine harness model): gapless, no deadlock |
| `tests/module-debtor.php` new | DB | `preview()`, `invoiceAndFinalize()` (lock order, rollback with the caller), `prepareDocumentRanges()`, `PaymentStates`, `subject`; existing sections unchanged green |
| `tests/navigation-seeds.php` | offline | the two new seeds parse, parents resolve |

---

## 12. What is prepared for later

- **Collective invoice:** `invoiceAndFinalize()` takes drafts, `invoice_id` is not unique, line
  origin carries the order line — a collective draft is one draft with the lines of N orders and
  N orders pointing at one invoice. Needs: a selection in «Zu fakturieren» grouped by customer.
- **Creation from outside (plan §13):** `OrderService::create(OrderDraft)` is already the ONE
  write path (DTO, no request), the origin columns exist, the initial status comes from the flags.
  Added then: a nullable unique `idempotency_key` on `sales_order` (expand migration), the key as
  an optional argument of `create()`, a repeated key with the SAME content returning the existing
  order and with DIFFERENT content refused (`IdempotencyConflictException`, financial's rule), and
  the transport (API module / subscription run).
- **Prepayment** (debtor.md pending): an order before its invoice, plain IBAN + NON with the order
  number — `PaymentSnapshot::forDocument()` is where it plugs in.
- **Return «at one click»** (ADR-043 decision 7): a copy of the order with negated quantities —
  `create()` with a mapped draft.
- **Close check:** `Close/InvoiceableOrdersCheck` (warning, scope `period-close`, plan §5.3) —
  built in step 10 below.

---

## 13. Build steps

Each step: its own commit, an independent review against the ADRs (the P1 working method), the
topic doc updated, `npm run docs:check` green.

| # | Step | Acceptance |
|---|---|---|
| 0 | Owner answers §14; ADR-040 and ADR-043 addenda approved; board on the developer canvas | owner «go» on the board |
| 1 | `module-article` slim: package, entities (abstract + empty), migration, `ArticleService`, Stammdaten › Artikel, nav seed, topic `article.md` | an article with number, name, unit, price, tax code, account saves; an account not «auf Belegen wählbar» is refused; `diff` clean; harness A/B green |
| 2 | Stock seam in article: `StockLedger`, DTOs, `PendingStockLedger`, `StockLedgers::default()` | harness K green; the contract documented in `article.md` |
| 3 | `module-order` skeleton: package, `OrderStatus` + seed + validator + `OrderMasterData`, Stammdaten › Auftragsstatus, nav seed, topic `order.md` | seed appears on install; invariants refuse; a new status is added in the backend without code; deactivate, never delete |
| 4 | Debtor seams: `preview()`, `prepareDocumentRanges()`, `invoiceAndFinalize()`, `PaymentStates`, `subject` (+ the invoice editor field); `debtor.md` rules amended | debtor harness new section green, all existing sections green; manual invoice → booking print unchanged live |
| 5 | Order entities, migrations (schema, then the `order` range in its own file), `OrderService::create` / `update` with `syncStock()` | create draws 1, 2, …; a stale version is refused; the spy sees the full state on every write |
| 6 | Status change, transitions, log, the reason flow | harness T/M green; backward refused once an invoice exists |
| 7 | Screens: list (tabs, action cell, payment column), order window (three tabs, confirm windows), help; css-backend action-cell rows | click-through in z77.ch: create a quote, turn it into an order, edit lines, change status — no page reload except the booking print tab |
| 8 | PDFs: `pdf/order`, `pdf/deliveryNote` (+ status), invoice preview from the order | quote and confirmation print; the delivery note moves «Terminauftrag» → «geliefert», a reprint moves nothing |
| 9 | Booking print from the order (window + selection bar), idempotent reprint, payment state | **P7 slim exit:** order → invoice → journal in one step; a second submit posts nothing; the CAMT payment shows «bezahlt» on the order |
| 10 | `InvoiceableOrdersCheck` under `period-close` | the year-close screen lists invoiceable orders of the year as a warning |
| 11 | Docs and live test: plan §7/§9, ADR addenda, `order.md` / `article.md` / `debtor.md`, README of both packages; the DB harnesses run on PC1 | owner live test passed; `docs:check` green |

**Progress:** steps 0 (docs: §14 answers, ADR addenda, §7 / §9 notes — the canvas board for the
order screens is still open) · 1 and 2 built 2026-10-14 ([`article.md`](../topics/article.md);
harness sections A / K green, B waits for PC1 — ARTICLE-DB-001) · 4 in parallel (debtor seams) · 3, 5 and 6
built 2026-10-14 ([`order.md`](../topics/order.md): `OrderStatus` + Stammdaten › Auftragsstatus, the
schema and the range `order`, `OrderService` create / update / status change / delivery-note status;
harness S / N / T / D green, M waits for PC1 — ORDER-DB-001) · **7, 8, 9 and 10 built 2026-10-14** ([`order.md`](../topics/order.md) «screens, PDFs and the booking print»: the list with the six tabs from the flags, the order window with radio tabs Auftrag · Verlauf · Belege, «Status ändern …», «+ Kontakt», the three PDFs, `OrderService::book()` with the confirmation page and the idempotent reprint, `InvoiceableOrdersCheck`; harness V / I / F / P / C green offline; verified on z77.ch by curl and by a rolled-back `book()` of order 1 — ORDER-UI-001). Order 1 waits in «Zu fakturieren» for the owner's own booking print (step 9's exit) and the P7 slim live test (step 11).

---

## 14. Questions for the owner — answered 2026-10-14

The owner accepted all six recommendations on 2026-10-14 («Alles ok los»). The answers are binding
for the build steps; the column «Answer» records them.

| # | Question | Recommendation | Answer (owner, 2026-10-14) |
|---|---|---|---|
| Q1 | Delivery note: does printing go to a status set ONCE per installation (flag `deliveryNoteTarget`), or does the print ask each time «geliefert / direkt zu fakturieren»? | **The flag** (D6): one setting, no extra click; a service business marks «zu fakturieren», retail «geliefert». The status can still be moved forward in one click. | **The flag** (`deliveryNoteTarget`, seed «Geliefert»). |
| Q2 | Article: keep A3 in the schema (product + hidden default variant, the line references the variant) although the screen shows ONE flat article? | **Yes.** The UI stays flat; the schema does not have to change when the first variant (vintage, size) arrives — order lines already point at variants. | **Yes** — two levels in the schema, one flat mask (built, step 1). |
| Q3 | Price: history with «gültig ab» (A4) now, or one price field? | **History now, one field on screen** («Preis», «gültig ab» = today). A position proposes the price valid at the order date; changing it later is a table change on master data that every installation would need. | **History now, one field in the mask** (built, step 1). |
| Q4 | May an order go to a contact that is not yet a debtor (quote to a prospect), the debtor profile being opened at the booking print? | **Yes** (D9) — no customer number for prospects who never order; the profile opens with the default terms when the first invoice is booked. | **Yes** (D9). |
| Q5 | Order numbers start at 1 (range `order` at 0)? | **Yes**; an installation with history gets the range raised by its import (P8). | **Yes** — the range `order` is created at 0. |
| Q6 | «Erledigt»: set by hand from «gebucht» (window or later a selection action), not automatically on payment? | **By hand.** Automatic on payment would make the receivables side drive the order status (ADR-040 decision 6); the list's «Zahlung» column shows what is paid. | **By hand.** |

---

## 15. Contradictions with the existing plan and ADRs

What the owner decisions of 2026-10-14 change — **recorded 2026-10-14** (owner go): items 1, 3, 4, 9 in
the [ADR-040](../02-decisions/adr-040-business-module-cut.md) and
[ADR-043](../02-decisions/adr-043-order-status-and-stock-movements.md) addenda of 2026-10-14, item 5 in
the ADR-043 addendum (closing paragraph), items 1, 2, 5–8 as notes in plan §4b / §7 / §9
(`order-debtor-financial-bauplan.md`); debtor.md's rule (item 1) is amended with step 4.

1. **Plan §7 «Order status ↔ invoice» (2026-10-10)** and the 2026-10-13 debtor pending: the order
   created an invoice DRAFT («in Fakturierung») booked later from the invoice screen, the booking
   print setting «erledigt». Now: no draft, the booking print from the order, it sets «gebucht».
   Plan §7 «Invoice: … calls `InvoicingService::invoice()` (a draft)» is replaced by
   `invoiceAndFinalize()`; debtor.md's rule «any source MUST call `invoice()` and book with
   `finalize()`» needs the second sanctioned form.
2. **Plan §9 note 2026-10-13** «FREE lines, NO articles (P6)»: articles come with P7 (P6 slim).
3. **ADR-043 decision 1** (seed and `invoiceInProgress`) and **decision 11** («the status change
   is the only trigger»): the seed changes, `invoiceInProgress` → `invoiceBooked`, and position
   changes trigger too (same target computation). Plan §4b says the same as decision 11.
4. **ADR-040 decision 6 / ADR-043 rejected alternatives** list `invoiced` as a forbidden order
   status; «gebucht» is exactly that, but set by order itself in the booking print's unit of work —
   needs the sharpened wording (§2).
5. **Plan §4b A1**: tax code and revenue account on the PRODUCT GROUP; the owner puts them on the
   article. Proposal: the article carries them; when the tree comes it supplies DEFAULTS for new
   articles only.
6. **Plan §2 table**: module-article → kernel, doctrine, vat; now also module-mandator (account check).
7. **Plan §7 table** «`NumberRange` (quote, order)» — two ranges; now ONE range `order` (quote and
   order are one record). The debtor pending's proposal «own series `YYMMnnnn`» is superseded by O2.
8. **Plan §7** «records the invoice per line» → per order (D5).
9. **ADR-043 decision 1** «multilingual label» → German label like all master data until a
   customer-facing consumer exists.
