<?php

namespace Z77\Module\Order\Dashboard;

use Z77\Core\DI;
use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Repositories\OrderSearch;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Module\Order\Repositories\SalesOrderRepository;
use Z77\Module\Order\Ui\OrderListing;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;

/**
 * order's entries on the backend dashboard (ADR-052), registered under
 * `dashboard` in `orderConfig`:
 *
 *   - orders ready to invoice — todo, one entry per BOOKABLE phase
 *     (`OrderPhase::isBookable()`: «Termin», «Geliefert» — the phases
 *     `InvoiceableOrdersCheck` counts), each linking to its list tab, so the
 *     number on the dashboard is the number of rows the tab shows;
 *   - open quotes («Eröffnet») — todo «Offerten nachfassen» (owner
 *     2026-10-22: one has to follow up);
 *   - «+ Neuer Auftrag» — the order window, as the list's action cell opens it.
 *
 * Which statuses fall into a phase is read from the status master data (a
 * handful of rows), never a status code (ADR-043); each count is ONE query.
 */
final class OrderDashboard implements DashboardContributor
{
    private const LIST = ['backend', 'order', 'order', 'list'];

    public function entries(AuthUser $user): iterable
    {
        $em = DI::getUnifiedEntityManager();
        /** @var OrderStatusRepository $statusRepo */
        $statusRepo = $em->getRepository(OrderStatus::class);
        $statuses   = $statusRepo->allInOrder();
        /** @var SalesOrderRepository $orders */
        $orders = $em->getRepository(SalesOrder::class);
        $count  = static fn(OrderPhase $phase): int => $orders->countSearch(new OrderSearch(OrderListing::codesOf($phase->value, $statuses)));

        foreach (OrderPhase::cases() as $phase) {
            if (!$phase->isBookable()) {
                continue;
            }
            $n = $count($phase);
            if ($n > 0) {
                yield DashboardEntry::pending(Severity::Todo, 'Aufträge bereit zur Verrechnung (' . $phase->label() . ')', self::LIST, ['view' => $phase->value], $n);
            }
        }

        $quotes = $count(OrderPhase::New);
        if ($quotes > 0) {
            yield DashboardEntry::pending(Severity::Todo, 'Offerten nachfassen', self::LIST, ['view' => OrderPhase::New->value], $quotes);
        }

        yield DashboardEntry::create('Neuer Auftrag', ['backend', 'order', 'order', 'add'], DashboardEntry::OPEN_WINDOW);
    }
}
