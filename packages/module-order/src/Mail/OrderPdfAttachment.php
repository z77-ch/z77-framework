<?php

namespace Z77\Module\Order\Mail;

use Z77\Core\DI;
use Z77\Module\Mailing\Attachments\AttachmentProvider;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Pdf\OrderPdf;
use Z77\Module\Order\Repositories\OrderStatusRepository;
use Z77\Module\Order\Repositories\SalesOrderRepository;
use Z77\Module\Order\Services\OrderService;
use Z77\Shared\Mail\Attachment;

/**
 * The outbox attachment `order-pdf:<order id>@<kind>` (owner 2026-11-13) — the order's documents,
 * one provider for its PDFs: `@quote` «Offerte», `@order-confirmation` «Auftragsbestätigung»
 * (`pdf/order`, lines and totals from debtor's preview — D11), `@delivery-note` «Lieferschein»
 * (`pdf/deliveryNote`, to the delivery address, no prices). Registered in `orderConfig` under
 * `mailAttachments`; only ever loaded where module-mailing is installed.
 *
 *   - {@see accepts()} at QUEUE time: the order exists, is not deleted, the kind is one of the three;
 *   - {@see resolve()} at SEND time: the PDF of the order AS IT THEN STANDS — rendered like the
 *     window's prints, nothing stored, no side effect (a mail never moves the order). The title is
 *     the KIND's, not the phase's: a quote mailed before the order was confirmed still attaches an
 *     «Offerte». A deleted order, a preview debtor refuses (the order no longer computes) throw — a
 *     retry of that mail, the cause in `last_error` (the outbox's contract).
 */
final class OrderPdfAttachment implements AttachmentProvider
{
    public const TYPE = 'order-pdf';

    private const PATTERN = '/^([1-9]\d{0,9})@(quote|order-confirmation|delivery-note)$/';

    /** The reference of $order's document $kind — `order-pdf:12@quote`. */
    public static function reference(SalesOrder $order, string $kind): string
    {
        return self::TYPE . ':' . $order->getId() . '@' . $kind;
    }

    public function accepts(string $id): bool
    {
        if (preg_match(self::PATTERN, $id, $m) !== 1) {
            return false;
        }
        $order = $this->orders()->find((int) $m[1]);

        return $order !== null && !$order->isDeleted();
    }

    public function resolve(string $id): Attachment
    {
        $order = preg_match(self::PATTERN, $id, $m) === 1 ? $this->orders()->withLines((int) $m[1]) : null;
        if ($order === null || $order->isDeleted()) {
            throw new \RuntimeException("Order document {$id}: the order is missing or deleted — no PDF to attach.");
        }
        $em      = DI::getUnifiedEntityManager();
        $service = new OrderService($em, 'mail-outbox');   // reads only — the preview and the address
        $kind    = $m[2];
        if ($kind === OrderMails::DELIVERY_NOTE) {
            $title = OrderMails::documentName($kind, $order);

            return new Attachment(OrderPdf::fileName($title), 'application/pdf', OrderPdf::deliveryNote($order, $service->deliveryAddressOf($order), $em)->output());
        }
        /** @var OrderStatusRepository $statuses */
        $statuses = $em->getRepository(OrderStatus::class);
        $status   = $statuses->findByCode($order->getStatusCode());
        try {
            $preview = $service->preview($order);
        } catch (\Z77\Module\Debtor\Services\DebtorException | \Z77\Module\Vat\Services\VatException $e) {
            throw new \RuntimeException(OrderMails::documentName($kind, $order) . ' lässt sich nicht erstellen: ' . $e->getMessage(), 0, $e);
        }

        return new Attachment(
            OrderPdf::fileName(OrderMails::documentName($kind, $order)),
            'application/pdf',
            OrderPdf::order($order, $status, $preview, $em, OrderMails::NAMES[$kind])->output(),
        );
    }

    private function orders(): SalesOrderRepository
    {
        return DI::getUnifiedEntityManager()->getRepository(SalesOrder::class);
    }
}
