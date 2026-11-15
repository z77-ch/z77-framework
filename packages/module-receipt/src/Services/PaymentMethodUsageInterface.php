<?php

namespace Z77\Module\Receipt\Services;

use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Another module's use of the payment-method codes («Bezahlt mit») — one contribution to the usage
 * count of the master data «Zahlungsarten» ({@see PaymentMethodUsage}, RECEIPT-PM-COUNT-001).
 * Registered by class name under the module config key `paymentMethodUsage`; module-receipt names
 * no module. The class has a constructor without arguments.
 */
interface PaymentMethodUsageInterface
{
    /**
     * How many of the module's records (any state) carry each payment method code.
     *
     * @return array<string, int> code → count, only codes in use
     */
    public function countByPaymentMethod(UnifiedEntityManager $em): array;
}
