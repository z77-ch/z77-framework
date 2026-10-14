# order

2026-10-14

## entry

1. `packages/module-order/src/Services/OrderService.php` — the ONE write path of an order: create / update / status change / delivery-note status, the lock order, the one stock call, the totals from debtor's preview
2. `packages/module-order/src/Services/OrderStatuses.php` — the status rules of plan §6: selection by FLAG (initial, delivery-note target, booked), the transition rule
3. `tests/module-order.php` — the harness: status invariants (S), selection by flag and module direction (N), transitions and stock targets (T), the invoice mapping (D) offline; the write path against a throwaway schema (M)

## file map

SOURCE=/packages/module-order/composer.json
SOURCE=/packages/module-order/README.md
SOURCE=/packages/module-order/src/App/Config/orderConfig.inc.php
SOURCE=/packages/module-order/data/framework/order/order_statuses.default.json
SOURCE=/packages/module-order/data/framework/routing/navigation.d/module-order.json
SOURCE=/packages/module-order/src/Entities/OrderStatus.php
SOURCE=/packages/module-order/src/Entities/SalesOrder.php
SOURCE=/packages/module-order/src/Entities/SalesOrderLine.php
SOURCE=/packages/module-order/src/Entities/SalesOrderStatusChange.php
SOURCE=/packages/module-order/src/Repositories/OrderStatusRepository.php
SOURCE=/packages/module-order/src/Repositories/SalesOrderRepository.php
SOURCE=/packages/module-order/src/Repositories/SalesOrderStatusChangeRepository.php
SOURCE=/packages/module-order/src/Validators/OrderStatusValidator.php
SOURCE=/packages/module-order/src/Services/OrderMasterData.php
SOURCE=/packages/module-order/src/Services/OrderStatuses.php
SOURCE=/packages/module-order/src/Services/OrderService.php
SOURCE=/packages/module-order/src/Services/OrderDraft.php
SOURCE=/packages/module-order/src/Services/OrderLineDraft.php
SOURCE=/packages/module-order/src/Services/OrderException.php
SOURCE=/packages/module-order/src/Services/InvalidOrderException.php
SOURCE=/packages/module-order/src/Services/OrderConflictException.php
SOURCE=/packages/module-order/src/Services/OrderRefusedException.php
SOURCE=/packages/module-order/src/Services/InvalidOrderStatusException.php
SOURCE=/packages/module-order/src/Services/OrderStatusCodeChangedException.php
SOURCE=/packages/module-order/src/Stock/OrderStockTargets.php
SOURCE=/packages/module-order/src/Invoicing/OrderInvoiceDraft.php
SOURCE=/packages/module-order/src/Ui/OrderStatusControllerTrait.php
SOURCE=/packages/module-order/src/Ui/OrderStatusLayout.php
SOURCE=/packages/module-order/src/Ui/OrderStatusListing.php
SOURCE=/packages/module-order/res/migrations/Version20261014140000.php
SOURCE=/packages/module-order/res/migrations/Version20261014140001.php
SOURCE=/packages/module-order/res/view/templates/Backend/OrderStatusController/listAction.tpl.php
SOURCE=/packages/module-order/res/view/templates/Backend/OrderStatusController/_row.tpl.php
SOURCE=/packages/module-order/res/view/templates/Backend/OrderStatusController/act.tpl.php
SOURCE=/packages/module-order/res/view/templates/Backend/OrderStatusController/edit.tpl.php
SOURCE=/packages/module-order/res/view/templates/Backend/OrderStatusController/edit.help.tpl.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Order/OrderStatusController.php
SOURCE=/packages/module-backend/src/Ui/Config/Order/orderStatusControllerConfig.inc.php
SOURCE=/packages/module-article/src/Stock/StockLedger.php
SOURCE=/packages/module-debtor/src/Services/InvoicingService.php
SOURCE=/packages/module-debtor/src/Validators/ValidatesMasterDataRow.php
SOURCE=/tests/module-order.php
SOURCE=/docs/03-development/p7-order-plan.md
SOURCE=/docs/02-decisions/adr-043-order-status-and-stock-movements.md
SOURCE=/docs/02-decisions/adr-040-business-module-cut.md

## mental model

`z77/module-order` holds the sales order of P7 slim (plan [`p7-order-plan.md`](../03-development/p7-order-plan.md), owner go 2026-10-14): quote and order are ONE record (D1), the status is DATA with behaviour flags (O4, ADR-043), every write ends in ONE call of the stock seam (O5), the invoice is drafted from the order by a pure mapper and booked by debtor (O1). Depends on kernel, persistence-doctrine, module-vat, module-contact, module-mandator, module-article and module-debtor; nothing depends on it (debtor and article never name it — harness N2). Built 2026-10-14: steps 3, 5 and 6 of plan §13. **Not yet built**: the order list and window (step 7), the PDFs (step 8), the booking print `book()` (step 9), the close check (step 10) — see «seams for steps 7–10».

- **Order status** (`Entities\OrderStatus`, file `framework/order/order_statuses.json`, seed-once from `order_statuses.default.json`): `code` (key, immutable), German `label`, `level` (not unique — equal = lateral), the flags `offer`, `reservesStock`, `consumesStock`, `invoiceable`, `invoiceBooked`, `deliveryNoteTarget`, `final`, `active`. Seed: offer 10 · scheduled 20 (reserves) · delivered 30 (consumes, delivery-note target) · to-invoice 40 (consumes, invoiceable) · booked 50 (consumes, invoice booked) · done 90 (consumes, final) · done-no-stock 90 (final). Write path `Services\OrderMasterData::save()` / `setActive()` — no remove; the code of an existing row refused (`OrderStatusCodeChangedException`).
- **Invariants** (`Validators\OrderStatusValidator`, field errors on the flag that breaks them): `final ⇒ ¬invoiceable ∧ ¬invoiceBooked`; `consumesStock ⇒ ¬reservesStock`; `offer ⇒ ¬reservesStock ∧ ¬consumesStock ∧ ¬invoiceable`; `invoiceBooked ⇒ ¬invoiceable`; `invoiceBooked` and `deliveryNoteTarget` each on at most ONE ACTIVE row; the active booked row's level above every active invoiceable row's level. `flagProblems()` / `crossRowProblem()` are pure (harness S). `setActive(false)` is a pure state change (the debtor model); `setActive(true)` checks the cross-row rules — an activation must not make the selection by flag ambiguous.
- **Selection by flag** (`Services\OrderStatuses`, pure over the rows): `initial()` = the active status with the lowest level (then id); `startable()` = active, not booked, not final (the create form's choice); `deliveryNoteTarget()`, `invoiceBooked()` by flag. A code comes only from OUTSIDE (the order's own status, a target the user picked) — no code path selects a status by a literal code (harness N1).
- **Transitions** (`OrderStatuses::refusal()`): forward / lateral (level ≥) always; backward only while the order has NO invoice (`backward-barred`); never to the booked status (`booked-by-print-only` — the booking print's alone); never to an inactive one (`inactive-target`); the same status is `same-status`. An order keeps a deactivated status. `targets()` is the confirm list.
- **Schema** (`Version20261014140000`, schema only; `Version20261014140001` the range `order` at 0 — schema and data apart, debtor's rule): `sales_order` (number unique — range `order`, owner O2/Q5: 1, 2, …; `status_code` by code; subject 200; order date; service from / to nullable; contact FK; price mode; currency; payment target code nullable; `ship_*` = debtor's `AddressSnapshot` embeddable, all empty = none; `notes` internal; `total_net/tax/gross` nullable; `invoice_id` FK nullable, NOT unique (D5); `source_type` / `source_ref`; actor stamps; `version`), `sales_order_line` (order FK, position, `parent_line_id` one level, `type` = debtor's `LineType` without rounding, `variant_id` FK nullable (D2), `article_number` snapshot, text, `quantity` DECIMAL(12,3) negative allowed, unit, unit price, discount hundredths, tax code, revenue account), `sales_order_status_change` (order FK, from / to code, `change_trigger` — `create` · `status` · `delivery-note` · `booking-print`; the column is not `trigger`, a reserved word —, reason, who, when). Named after article's migration (the line references `article_variant`).
- **The write path** (`Services\OrderService`, actor named by the caller, optional `StockLedger` and `InvoicingService` injected for the harness): `create(OrderDraft)`, `update(id, version, OrderDraft, ?StockConfirmation)`, `changeStatus(id, version, toCode, ?StockConfirmation)`, `printDeliveryNote(id, version, ?StockConfirmation)`; reads `statuses()`, `editRefusal(order)`. Refusals: `InvalidOrderException` (German message per field, `lines.{path}.{field}`), `OrderConflictException` (stale version, gone, a line id that is not the order's), `OrderRefusedException` with a stable `reason` (`no-status`, `status-unknown`, `not-startable`, `not-editable` + the four transition reasons). Every write flushes before it returns — a caller that joins the unit of work reads the written state and version.
- **The draft** (`OrderDraft` / `OrderLineDraft`, typed strings, no `Request`): an ARTICLE line takes text / unit / unit price from the article when empty — the price valid at the ORDER date (`PriceRepository::validAt()`), converted to the order's price mode with the rate of the article's tax code on the SERVICE date (`VatRates`) — and ALWAYS tax code and revenue account from the article (D3). A FREE line names its tax code (active, or kept by the order) and account (digits + `ArticleService::accountProblem()` — soft, the binding check stays debtor's at the booking print). Contact, payment target, variant and tax code that the order already holds stay valid although deactivated since (ADR-043 decision 19).
- **Line identity is stable** (decided at build): `update()` rewrites a line IN PLACE when the draft carries its `id`, inserts the new ones, removes the ones left out (orphan removal). The line id is the stock seam's line reference; a «replace all lines» save (plan §5's `reinvoice()` model) would have given every line a new id on every edit, and in a consuming status the journal would have seen consumed stock come back on every save (`StockReasonRequiredException`).
- **Lock order** (plan §5): `order` range (create only — the first write) → `sales_order` row EXCLUSIVE (`SalesOrderRepository::lockIds()` returns the version; compared there) → the order re-read REFRESHED (`withLines()`, `HINT_REFRESH`) → the line rows flushed (new rows; their ids are the stock references) → the stock call → the totals → the commit. What the draft needs is read and judged BEFORE the unit of work; under the lock D7 and the transition rule are judged again. A lock conflict propagates — the screen answers it with debtor's `LockConflict::is()` / `MESSAGE`.
- **Stock** (`private syncStock()`, the only `bringTo()` in the package — harness N4): the COMPLETE state through `Stock\OrderStockTargets::of(lines, status)` — every article line (children too) with `reserved` = quantity if the status reserves, `consumed` = quantity if it consumes, else `0.000`; negative stays negative; a LUMP-SUM article line counts as one unit (decided at build); free and text lines not sent. Triggers: `create`, `lines`, `status:{from}>{to}` (also for the delivery note). A `StockReasonRequiredException` rolls everything back; with `StockConfirmation` the reason is written to the status log row (a pure line edit has no log row — the journal of P7b keeps the reason there).
- **Totals** (D11): `OrderInvoiceDraft::of($order, $order->getOrderDate())` → `InvoicingService::preview(…, openProfile: true)` inside the unit of work, after the order's lock (preview reads, writes nothing); net / tax / gross stored for the list; a refusal (`DebtorException`, `VatException`) leaves them null.
- **Editable** (D7): no invoice and the status not final — else `not-editable`; with an invoice only a forward status change. No delete (D8). «Erledigt» by hand (Q6) through `changeStatus()`.
- **Delivery note** (D6): `printDeliveryNote()` moves to the active `deliveryNoteTarget` status only when its level is HIGHER; at or past it — or without such a status — nothing is written and the order is answered as it is (no version needed: a double click prints twice, moves once).
- **Invoice mapping** (`Invoicing\OrderInvoiceDraft::of(order, invoiceDate)`, pure, harness D): kind invoice, contact, invoice date (a day), service from = head or the invoice date, service to, price mode, currency, payment target, terms null (the profile's), address null (debtor snapshots the invoice address), subject «Auftrag {nr} · {Betreff}» / «Auftrag {nr}», lines 1:1 with children beneath their parent, origin `order` / number, lines `order-line` / id.
- **Screen** Stammdaten › Aufträge › Auftragsstatus (`/backend/order/order-status/list`, host `Order/OrderStatusController`, group `order`): a standard list in level order (state icon · Code · Bezeichnung · Stufe · Verhalten = the flags as badges · Aktiv switch), action cell «+ Status». The status opens as a WINDOW with help (code fixed after creation, label, level, the seven flags as switches; the invariants as field errors) — deviation from plan §9.5's «⋮ → edit dialog», the article's reason: a modal popup cannot carry the field help (ADR-048). The switch answers with its row; a refused activation says which row holds the flag.
- **Navigation**: seed `module-order.json` — `auftragsstatus` «Auftragsstatus» under debtor's `stammdaten-auftraege`, sort 10. The area entry `auftraege-liste` «Aufträge» (route `backend/order/order/list`, sort 0 under `auftraege`) and `groupDefaults['order']` → `order` come with step 7, when the list exists.

## seams for steps 7–10

- **Step 7 (list + window)**: the window builds an `OrderDraft` from its form (no `fromArray()` yet — the form's shape is step 7's; nested `children` or a flat «unter» column mapped to `beneath()`), carries each existing line's `id` back, and passes the `version`; field errors come keyed `contact_id`, `order_date`, … and `lines.{index}.{field}` / `lines.{parent}.{child}.{field}`. Read-only per `editRefusal()`. «Status ändern …» lists `statuses()->targets($status, $order->getInvoice() !== null)`; on `StockReasonRequiredException` the confirm re-opens with a mandatory reason and resubmits with `new StockConfirmation($reason)`. The «Verlauf» tab: `SalesOrderStatusChangeRepository::historyOf()`. List tabs by flag over `OrderStatuses::all()` (the codes of the statuses carrying a flag → `status_code IN (…)`); index `idx_sales_order_status`. Totals for the list: `total_gross` (null = «–», the preview refuses). Then the nav entry `auftraege-liste` and `groupDefaults['order'] = 'order'`.
- **Step 8 (PDFs)**: order PDF / invoice preview from `preview(OrderInvoiceDraft::of($order, today), openProfile: true)`; the delivery-note POST calls `printDeliveryNote()` first, then renders; the delivery address is `SalesOrder::deliveryAddress()` (null = the contact's invoice address).
- **Step 9 (booking print)**: `book(list<{id, version}>, invoiceDate, ?StockConfirmation)` in `OrderService` — before the unit of work `InvoicingService::openDebtorProfile()` per contact and `prepareDocumentRanges([$invoiceDate])`, the drafts from `OrderInvoiceDraft::of()`; inside: `SalesOrderRepository::lockIds()` (versions compared), refuse an order with an invoice or a status not `invoiceable`, the booked status from `statuses()->invoiceBooked()` (null → refuse naming what to mark), `invoiceAndFinalize($drafts)` joined, per order `linkInvoice()`, a log row with `SalesOrderStatusChange::TRIGGER_BOOKING_PRINT` (a `move()` with that trigger), the stock call. Payment state: debtor's `PaymentStates::of($invoiceIds)`.
- **Step 10**: `Close/InvoiceableOrdersCheck` (warning, scope `period-close`) — the codes of the `invoiceable` statuses, `order_date` in the year.

## rules

- When code needs «the» status for a step (a new order, the delivery note, the booking print) → MUST select it by FLAG through `OrderStatuses` (`initial()`, `deliveryNoteTarget()`, `invoiceBooked()`); MUST NOT name a status code in code (harness N1)
- When an order, its lines or its status change → MUST go through `OrderService` with a named actor and the version the caller saw; MUST NOT persist `SalesOrder`, `SalesOrderLine` or `SalesOrderStatusChange` anywhere else, and MUST NOT call `moveTo()`, `describe()`, `write()` or `linkInvoice()` outside the service
- When an order write changes what the order holds in stock terms → MUST end in the ONE private `syncStock()` with the COMPLETE state of the order under its CURRENT status; MUST NOT call `StockLedger::bringTo()` from a controller, template, repository or a second place (harness N4)
- When a screen rewrites an order → MUST send every existing line back with its `id`; MUST NOT rebuild the lines without ids (every line would get a new stock reference)
- When an order status row is written → MUST go through `OrderMasterData::save()` / `setActive()`; MUST NOT delete a row or change an existing row's code, and MUST NOT edit `order_statuses.json` through Windows PowerShell (DATA-JSON-001)
- When the order's totals or its invoice are needed → MUST map with `OrderInvoiceDraft::of()` and ask debtor (`preview()`, later `invoiceAndFinalize()`); MUST NOT compute VAT, line amounts or the 0.05 rounding in module-order (D11)
- When module-order needs debtor or article → MUST use their public seams (`InvoicingService`, `PaymentStates`, the repositories, `StockLedgers::default()`); debtor and article MUST NOT name `Z77\Module\Order` (harness N2)
- When an order unit of work locks → MUST take the `sales_order` rows with `SalesOrderRepository::lockIds()` and compare the version there before reading the order with `withLines()`; MUST NOT read the order plainly inside the unit of work before that lock

## known issues

- **ORDER-DB-001** — added 2026-10-14. Don't assume section M of `tests/module-order.php` has run: it needs the `z77test` credentials, which are missing on PC2 (the ARTICLE-DB-001 situation); `php -l` only. Steps 3, 5 and 6 were verified on the z77.ch dev database instead: migrations applied, `z77-db diff` «No changes», `status` 0 new / 0 unavailable, the range `order` at 0; a scratch script with a stock spy inside ONE rolled-back unit of work (28 checks: numbers 1, 2; article defaults and gross conversion 150 → 162.15; totals 345.50 / 373.50 from the preview; per-field refusal without a number drawn; status offer → scheduled → delivered (delivery note) → done-no-stock with a reason → to-invoice; update keeping line identity; a stale version and a foreign line id refused; D7 with a linked invoice; afterwards no row left, the range at 0); the Auftragsstatus screen by curl (list with the seven rows and badges, window with help, the four invariant refusals, a new status, the activation refused for a second «Ziel des Lieferscheins», the code not changeable — `order_statuses.json` restored byte for byte afterwards).

## pending

- Run `tests/module-order.php` section M on PC1 (ORDER-DB-001).
- Steps 7–10 of plan §13 (see «seams for steps 7–10»); the board on the developer canvas for the order screens (backend-screen.md checklist 1) is still open.
- The actor: `OrderService` takes the actor from its caller, like `ArticleService` (article.md pending: a shared actor resolution when a third module needs it).

## see also

- [`article.md`](article.md) — the stock seam `StockLedger` the order calls, the article an order line references
- [`debtor.md`](debtor.md) — «The seams for module-order»: `preview()`, `prepareDocumentRanges()`, `openDebtorProfile()`, `invoiceAndFinalize()`, `PaymentStates`, `subject`; debtor's lock order the booking print joins
- [`persistence-doctrine.md`](persistence-doctrine.md) — `NumberRange`, the transaction port, migrations (schema and data apart)
- [`persistence-file.md`](persistence-file.md) — the seed-once file master data `OrderStatus` lives in
- [`listing.md`](listing.md) — the standard list of the status screen
- [`css-backend.md`](css-backend.md) — the action-cell rows «Stammdaten › Auftragsstatus» and «Aufträge › Aufträge»
- [`navigation.md`](navigation.md) — the seed `module-order.json`
