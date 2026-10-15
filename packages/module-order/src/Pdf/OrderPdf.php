<?php

namespace Z77\Module\Order\Pdf;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Services\Creditor;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The order's two documents (plan §10, step 8) — rendered ON REQUEST, never stored, through the
 * kernel's PDF facade and two layouts of this module (overridable per project under `override/`):
 *
 *   - `pdf/order` — «Offerte {nr}» when the order's status carries the flag `offer`, else
 *     «Auftragsbestätigung {nr}» (one layout, the title by FLAG — no code is named). The lines,
 *     amounts, VAT per code, rounding and total come from debtor's PREVIEW of the order
 *     (`OrderService::preview()`, D11 — the one computation; the booking print will issue the
 *     same), the recipient is the invoice address the preview resolved. No payment part. No side
 *     effect: printing a quote or a confirmation changes nothing.
 *   - `pdf/deliveryNote` — «Lieferschein {nr}»: the delivery address of the order (else the
 *     customer's invoice address) in the window, the article number, text, quantity and unit —
 *     no prices. Printing it is the POST of `OrderService::printDeliveryNote()` (D6); this class
 *     only draws.
 *
 * Both reuse the shared blocks (Rule 9): module-mandator's `pdf/letterhead`, the kernel's
 * `pdf/addressWindow` and `pdf/table`.
 */
final class OrderPdf
{
    public const NS = 'Z77\Module\Order';

    public const LAYOUT_ORDER         = 'pdf/order';
    public const LAYOUT_DELIVERY_NOTE = 'pdf/deliveryNote';

    /** «Offerte» or «Auftragsbestätigung» — by the PHASE of the order's status (`new` is a quote). */
    public static function orderTitle(?OrderStatus $status): string
    {
        return $status !== null && $status->getPhase()->isQuote() ? 'Offerte' : 'Auftragsbestätigung';
    }

    /**
     * The quote / order confirmation. $preview = `OrderService::preview($order)` (lines loaded).
     */
    public static function order(SalesOrder $order, ?OrderStatus $status, Invoice $preview, UnifiedEntityManager $em): PdfDocument
    {
        $mandator = Creditor::mandator($em);
        $title    = self::orderTitle($status) . ' ' . $order->documentNumber();
        $numbers  = [];
        foreach ($order->getLines() as $line) {
            if ($line->getVariant() !== null && $line->getId() !== null) {
                $numbers[(string) $line->getId()] = (string) $line->getArticleNumber();
            }
        }

        return PdfDocument::create($title, $mandator?->getName() ?? '')->partial(self::LAYOUT_ORDER, [
            'order'          => $order,
            'title'          => $title,
            'preview'        => $preview,
            'articleNumbers' => $numbers,
            'mandator'       => $mandator,
            'customerNumber' => self::customerNumber($order, $em),
            'today'          => new \DateTimeImmutable('today'),
            'fmt'            => static fn(?Money $m) => AmountFormat::of($m),
        ], self::NS);
    }

    /** The delivery note to $address (the order's delivery address, else the invoice address). */
    public static function deliveryNote(SalesOrder $order, ?AddressSnapshot $address, UnifiedEntityManager $em): PdfDocument
    {
        $mandator = Creditor::mandator($em);
        $title    = 'Lieferschein ' . $order->documentNumber();

        return PdfDocument::create($title, $mandator?->getName() ?? '')->partial(self::LAYOUT_DELIVERY_NOTE, [
            'order'          => $order,
            'title'          => $title,
            'address'        => $address,
            'mandator'       => $mandator,
            'customerNumber' => self::customerNumber($order, $em),
            'today'          => new \DateTimeImmutable('today'),
        ], self::NS);
    }

    /** «offerte-12.pdf», «auftragsbestaetigung-12.pdf», «lieferschein-12.pdf» — kebab-case lower (Rule 6). */
    public static function fileName(string $title): string
    {
        return Naming::toSlug($title) . '.pdf';
    }

    private static function customerNumber(SalesOrder $order, UnifiedEntityManager $em): int
    {
        /** @var DebtorProfileRepository $profiles */
        $profiles = $em->getRepository(DebtorProfile::class);

        return $profiles->findByContact($order->getContact())?->getCustomerNumber() ?? 0;
    }
}
