<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Debtor\Services\InvoicePostedListener;
use Z77\Module\Order\Entities\OrderPhase;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Entities\SalesOrderStatusChange;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Module\Order\Repositories\SalesOrderRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «Journal verbuchen» moves the orders of the posted invoices to the posting status (owner
 * 2026-10-24): «Fakturiert» (the default of `closed`, set by «Fakturieren») → «Gebucht» (the row
 * flagged «Wird gesetzt beim Verbuchen», {@see OrderStatuses::postingTarget()}). Registered in
 * `orderConfig` under debtor's `invoicePostedListeners`; debtor calls it inside the posting's unit of
 * work — the orders move with the posting or not at all.
 *
 * Per order (not deleted, linked to one of the posted invoices), locked by id after debtor's invoice
 * rows: a status in phase `closed` other than the target moves to it — log row `journal-posting`,
 * the version moves, NO stock call (same phase, the stock stays consumed). An order already in the
 * target (invoiced before 2026-10-24, when «Fakturieren» set «Gebucht») is left as it is. Without an
 * active posting status (an installation whose status file is not upgraded, or the switch is off)
 * nothing moves.
 *
 * Reads the status file as it is — the file upgrade ({@see OrderStatusUpgrade}) never runs here, it
 * would write a file inside a unit of work that can roll back.
 */
final class OrderInvoicePostedListener implements InvoicePostedListener
{
    public function invoicesPosted(array $invoiceIds, UnifiedEntityManager $em, string $actor): void
    {
        /** @var OrderStatusRepository $statusRepo */
        $statusRepo = $em->getRepository(OrderStatus::class);
        $rules      = new OrderStatuses($statusRepo->allInOrder());
        $target     = $rules->postingTarget();
        if ($target === null) {
            return;
        }
        /** @var SalesOrderRepository $orders */
        $orders = $em->getRepository(SalesOrder::class);
        $ids    = $orders->idsByInvoices($invoiceIds);
        if ($ids === []) {
            return;
        }
        $orders->lockIds($ids);
        $actor = mb_substr(trim($actor) !== '' ? trim($actor) : 'system', 0, SalesOrder::ACTOR_LENGTH);
        $now   = new \DateTimeImmutable();
        foreach ($ids as $id) {
            $order = $orders->withLines($id);
            if ($order === null || $order->getStatusCode() === $target->getCode()) {
                continue;
            }
            $from = $rules->byCode($order->getStatusCode());
            if ($from === null || $from->getPhase() !== OrderPhase::Closed) {
                continue;   // not where «Fakturieren» put it — a status the posting does not own
            }
            $order->moveTo($target->getCode());
            $order->touch($actor, $now);
            $em->persist(new SalesOrderStatusChange($order, $from->getCode(), $target->getCode(), SalesOrderStatusChange::TRIGGER_JOURNAL_POSTING,
                $order->getInvoice()?->documentName() . ' verbucht', $actor, $now));
        }
    }
}
