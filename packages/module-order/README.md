# z77/module-order

The sales order of the z77 business modules: **quote and order are one record** that climbs a
fixed ladder of phases — quote, confirmed, delivered, closed — prints its own documents
(quote, order confirmation, delivery note), sends them by e-mail, and is invoiced through
[`z77/module-debtor`](../module-debtor). The status is data (labels the installation maintains);
all behaviour hangs on five phases fixed in code.

Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-order`); not yet a split target or on Packagist — projects consume it through a
`path` repository until it is. MIT licence.

## What it does

- **One record from quote to invoice** (`Entities\SalesOrder`, `SalesOrderLine`,
  `SalesOrderStatusChange`): number from the range `order`, customer (a contact with a debtor
  profile), billing and delivery address as snapshots, lines that reference an **article**
  (every priced line is an article; text lines; one level of indented child lines), a status log.
- **Phases and statuses** (`Entities\OrderPhase`, `Entities\OrderStatus`): five fixed phases —
  `new` «Eröffnet», `confirmed` «Termin», `delivered` «Geliefert», `closed` «Abgeschlossen»,
  `cancelled` «Abgelegt» — carry every rule (printable, invoiceable, locked, stock target). The
  statuses are file master data (`data/framework/order/order_statuses.json`, seeded once from
  `order_statuses.default.json`) with a start status and one status per action (order
  confirmation, delivery note, invoicing, journal posting). See ADR-043 and its addenda.
- **One write path** (`Services\OrderService`): create, update with optimistic version, status
  change, the two prints that move the order (`printConfirmation()`, `printDeliveryNote()`),
  invoicing (`book()` → debtor's Faktura-Journal), re-invoicing (`reinvoice()`), delete with a
  reason (kept, tab «Gelöscht»). Totals always come from debtor's invoice preview (no VAT or
  rounding logic in this package).
- **PDFs** (`Pdf\OrderPdf`, layouts `pdf/order`, `pdf/deliveryNote` — overridable under
  `override/`): quote / order confirmation, delivery note, invoice preview.
- **E-mail** (`Mail\OrderMails` on debtor's `DocumentMailer`): quote, order confirmation and
  delivery note to the billing address's e-mail, the PDF rendered at send time
  (`Mail\OrderPdfAttachment`). Only where `z77/module-mailing` is installed.
- **Hooks into other modules** (`src/App/Config/orderConfig.inc.php`): the year-close warning for
  orders not yet invoiced (`openWorkChecks` → `Close\InvoiceableOrdersCheck`), debtor's journal
  posting moves invoiced orders to «Gebucht» (`invoicePostedListeners` →
  `Services\OrderInvoicePostedListener`), dashboard entries (`Dashboard\OrderDashboard`), the
  document-mail kinds `quote`, `order-confirmation`, `delivery-note`.
- **The stock seam**: every write calls article's `StockLedger::bringTo()` once with the order's
  complete target state (reserved in `confirmed`, consumed from `delivered` on). The ledger behind
  it books nothing yet — see «Not built yet».

## Requirements

PHP 8.4 and `z77/kernel`, `z77/persistence-doctrine`, `z77/module-vat`, `z77/module-contact`,
`z77/module-mandator`, `z77/module-article`, `z77/module-debtor`. Optional: `z77/module-mailing`
(«Senden ▾»), `z77/module-financial` (through debtor — posting the invoices).

## Enabling it in a project

1. Require the package (`composer require z77/module-order`, or the monorepo's `path` repository).
   `composer install` / `update` adds the navigation entries of
   `data/framework/routing/navigation.d/module-order.json` (ADR-050) and the installer seeds
   `order_statuses.json` once.
2. Apply the migrations (`res/migrations`, namespace `Z77\Module\Order\Migrations`):
   `php vendor/bin/z77-db migrate`.
3. Maintain the statuses under Stammdaten › Auftragswesen › Auftragsstatus if the seed does not
   fit; check that a status in phase «Abgeschlossen» carries «Wird gesetzt beim Verbuchen».
4. Customise the PDF layouts by copying `res/view/templates/pdf/*.tpl.php` under `override/`
   (CE principle — never edit `vendor/`).

## Backend screens

Mounted by `z77/module-backend` (thin hosts in `src/Ui/Controllers/Order/`, logic in this
package's `Ui\*ControllerTrait`):

| Menu | URL | What |
|---|---|---|
| Auftragswesen › Aufträge | `/backend/order/order/list` | the list (tabs Eröffnet · Termin · Geliefert · Fakturiert · Gebucht · Erledigt · Abgelegt · Alle · Gelöscht), «+ Neuer Auftrag», «Fakturieren …» for a selection |
| (window) | `/backend/order/order/edit?id=` | the order: head, addresses, lines with the article picker, status, «Drucken ▾», «Senden ▾», «Fakturieren …», «Neu fakturieren …», «Löschen …», history and documents |
| Stammdaten › Auftragswesen › Auftragsstatus | `/backend/order/order-status/list` | the status master data with its window |

The order list, the order window and both status screens carry German help
(`res/view/templates/Backend/*/*.help.tpl.php`, ADR-048; the confirmation dialogs need none); the
workflow guides under `res/view/templates/Backend/Guide/` («Ablauf eines Auftrags», «Neuen
Kunden erfassen») feed the backend assistant (ADR-055).

## Not built yet

- **Stock** — the journal ledger (plan step P7b) is not built: `StockLedgers::default()` returns
  `PendingStockLedger`, which books nothing and never refuses. No reservation, no stock level, no
  reason asked when consumed stock would come back. The help says «Lager: noch nicht verfügbar —
  folgt».
- **The quote is not archived** (owner decision 2026-11-13): its PDF is always rendered from the
  order as it stands. To keep what was offered, a second order with the quote's content is
  captured by hand and parked in a status of phase «Abgelegt»; there is no copy action.
- Collective invoice (several orders, one invoice), article variants with options, orders created
  by a shop or an import screen, a sticky totals line in the window.

## Documentation

- Topic: [`docs/topics/order.md`](../../docs/topics/order.md) — model, rules, known issues, pending.
- Decisions: ADR-040 (business module cut), ADR-043 (order status and stock movements, with its
  addenda), ADR-054 (Faktura-Journal); plan `docs/03-development/p7-order-plan.md`.
- Harness: `tests/module-order.php` (offline sections; section M needs the `z77test` database).
