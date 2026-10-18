<?php

namespace Z77\Module\Order\Services;

use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Order\Entities\SalesOrder;

/**
 * What a caller hands {@see OrderService} to create or rewrite an order (plan §5) — plain data, no
 * `Request`, so the backend window, a later API or a subscription run write the same way (§12).
 * The values are TYPED as they come (dates `Y-m-d`, amounts and quantities as decimal strings);
 * the service judges them and answers {@see InvalidOrderException} with a German message per field.
 *
 *   - `contactId` — the customer, a CONTACT (a debtor profile is not required — D9);
 *   - `orderDate` (required), `serviceFrom` / `serviceTo` (optional; «bis» needs «von») — the
 *     VAT rate follows the service date, the article price the ORDER date;
 *   - `priceMode` — `net` | `gross`; `paymentTargetCode` — the payment part of the booked invoice,
 *     null / '' = none; `paymentTermsCode` — the payment terms of the booked invoice (debtor's
 *     `PaymentTerms`, owner 2026-10-18), null / '' = the customer's debtor terms;
 *   - `deliveryAddress` — the snapshot to print on the delivery note; null = the invoice address;
 *   - `billingAddress` — the snapshot the booking print invoices to; null = the customer's invoice
 *     address (debtor's rule);
 *   - `notes` — internal, never printed;
 *   - `lines` — {@see OrderLineDraft}, in document order, children inside their parent;
 *   - `statusCode` — CREATE only: the status to start in (an active, not booked, not final one);
 *     null = the initial status (the active one with the lowest level). Ignored by `update()` — a
 *     status moves through `changeStatus()` only;
 *   - `sourceType` / `sourceRef` — the opaque origin (`backend`; plan §12).
 */
final class OrderDraft
{
    /** @param list<OrderLineDraft> $lines */
    public function __construct(
        public readonly int $contactId,
        public readonly string $orderDate,
        public readonly string $subject = '',
        public readonly string $serviceFrom = '',
        public readonly string $serviceTo = '',
        public readonly string $priceMode = 'net',
        public readonly ?string $paymentTargetCode = null,
        public readonly ?AddressSnapshot $deliveryAddress = null,
        public readonly string $notes = '',
        public readonly array $lines = [],
        public readonly ?string $statusCode = null,
        public readonly string $sourceType = SalesOrder::SOURCE_BACKEND,
        public readonly ?string $sourceRef = null,
        public readonly ?AddressSnapshot $billingAddress = null,
        public readonly ?string $paymentTermsCode = null,
    ) {}
}
