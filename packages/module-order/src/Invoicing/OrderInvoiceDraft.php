<?php

namespace Z77\Module\Order\Invoicing;

use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Invoicing\LineDraft;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Entities\SalesOrderLine;

/**
 * How an order becomes its invoice (plan §7) — a PURE mapper, the harness checks it offline
 * (section D). The ONE place an order is turned into debtor's {@see InvoiceDraft}: the totals of
 * every save (`InvoicingService::preview()`, D11), the order PDF and invoice preview (step 8) and
 * the booking print (`invoiceAndFinalize()`, step 9) all go through it — so the preview IS what
 * the booking print will issue.
 *
 *   - kind `invoice`; the contact; `address` = the order's BILLING snapshot (2026-10-17), null =
 *     debtor snapshots the customer's INVOICE address at booking (the order's delivery address is
 *     the delivery note's, not the invoice's);
 *   - the invoice date (the booking print's confirm, default today; the order date for the totals);
 *   - the service period from the head; an EMPTY «Leistung von» → the invoice date;
 *   - price mode and currency of the order, its payment target code; payment terms null → the
 *     debtor profile's;
 *   - the subject «Auftrag {number} · {Betreff}» (D10), «Auftrag {number}» without a Betreff;
 *   - the lines 1:1 in document order with type, text, quantity (service only), unit, unit price,
 *     discount, tax code and account; a child beneath its parent (`LineDraft::beneath()`);
 *   - the origin opaque: `order` / the order number, per line `order-line` / the line id.
 */
final class OrderInvoiceDraft
{
    public const SOURCE_TYPE      = 'order';
    public const LINE_SOURCE_TYPE = 'order-line';

    public static function of(SalesOrder $order, \DateTimeImmutable $invoiceDate): InvoiceDraft
    {
        $invoiceDate = $invoiceDate->setTime(0, 0);
        $children    = [];
        foreach ($order->getLines() as $line) {
            if ($line->getParentLine() !== null) {
                $children[spl_object_id($line->getParentLine())][] = self::line($line);
            }
        }
        $lines = [];
        foreach ($order->getLines() as $line) {
            if ($line->getParentLine() !== null) {
                continue;
            }
            $draft   = self::line($line);
            $beneath = $children[spl_object_id($line)] ?? [];
            $lines[] = $beneath === [] ? $draft : $draft->beneath(...$beneath);
        }

        return new InvoiceDraft(
            kind: InvoiceKind::Invoice,
            contactId: (int) $order->getContact()->getId(),
            invoiceDate: $invoiceDate,
            serviceFrom: $order->getServiceFrom() ?? $invoiceDate,
            serviceTo: $order->getServiceTo(),
            currency: $order->getCurrency(),
            priceMode: $order->priceMode(),
            paymentTermsCode: null,
            address: $order->billingAddress(),   // null = debtor snapshots the customer's invoice address
            lines: $lines,
            sourceType: self::SOURCE_TYPE,
            sourceRef: $order->documentNumber(),
            paymentTargetCode: $order->getPaymentTargetCode(),
            subject: self::subject($order),
        );
    }

    /** «Auftrag {number} · {Betreff}» — what the invoice prints under its title (D10). */
    public static function subject(SalesOrder $order): string
    {
        $subject = trim($order->getSubject());

        return 'Auftrag ' . $order->documentNumber() . ($subject === '' ? '' : ' · ' . $subject);
    }

    private static function line(SalesOrderLine $line): LineDraft
    {
        $ref = $line->getId() === null ? null : (string) $line->getId();

        return new LineDraft(
            type: $line->type(),
            text: $line->getText(),
            quantity: $line->getQuantity(),
            unit: $line->getUnit(),
            unitPrice: $line->getUnitPrice(),
            discountPercent: $line->getDiscountPercent(),
            taxCode: $line->getTaxCode(),
            revenueAccount: $line->getRevenueAccount(),
            sourceType: self::LINE_SOURCE_TYPE,
            sourceRef: $ref,
        );
    }
}
