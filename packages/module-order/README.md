# z77/module-order

The sales order for z77 business modules (ADR-040, ADR-043, plan `p7-order-plan.md`): quote and
order as ONE record, the order status as data with behaviour flags and a change log, the one
stock call per write, the invoice drafted from the order and booked by module-debtor. Depends on
`z77/kernel`, `z77/persistence-doctrine` (ADR-039), `z77/module-vat`, `z77/module-contact`,
`z77/module-mandator`, `z77/module-article` and `z77/module-debtor`. Developed in the
[z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo (`packages/module-order`);
not yet a split target or on Packagist — projects consume it through a `path` repository until it is.

State: **P7 slim steps 3 and 5–10 (2026-10-14)** — the order, its status and write path, the
backend screens Aufträge › Aufträge (list + order window, `Ui\OrderControllerTrait`), the PDFs
(`pdf/order`, `pdf/deliveryNote`), the booking print `OrderService::book()` and the year-close
warning `Close\InvoiceableOrdersCheck`. Details: `docs/topics/order.md`.

- `OrderStatus` (file, `data/framework/order/order_statuses.json`, seeded once) — code, label,
  one of the five fixed PHASES (`Entities\OrderPhase` — Eröffnet, Termin, Geliefert, Abgeschlossen,
  Storniert; the phase carries all behaviour), position, the start switch and the action default;
  written through `Services\OrderMasterData` (ADR-043 addenda 2026-10-15 / 2026-10-16).
- `SalesOrder` / `SalesOrderLine` / `SalesOrderStatusChange` (Doctrine) — the order head with its
  number from the range `order`, the lines (article or free, text lines, one level of children),
  the status log.
- `Services\OrderService` — the one write path: create / update (with version) / status change /
  the delivery-note status; every write calls the stock seam once with the complete state.
- `Invoicing\OrderInvoiceDraft` — the order as debtor's `InvoiceDraft` (totals, preview, booking).
- Backend: Stammdaten › Aufträge › Auftragsstatus (`/backend/order/order-status/list`), mounted by
  module-backend's `Order/OrderStatusController`.

Topic doc: [`docs/topics/order.md`](../../docs/topics/order.md).
