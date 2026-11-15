<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Receipt\Services\PaymentMethodUsageInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The creditor invoices' share of the payment methods' usage count (module-receipt's
 * `paymentMethodUsage` registry, RECEIPT-PM-COUNT-001): an invoice's «Bezahlt mit», any state.
 */
final class CreditorPaymentMethodUsage implements PaymentMethodUsageInterface
{
    public function countByPaymentMethod(UnifiedEntityManager $em): array
    {
        return $em->getRepository(CreditorInvoice::class)->countByPaymentMethod();
    }
}
