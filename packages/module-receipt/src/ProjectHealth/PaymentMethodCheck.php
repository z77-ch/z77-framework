<?php

namespace Z77\Module\Receipt\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 7 («Ersteinrichtung», owner 2026-11-19): no ACTIVE payment method («Zahlungsart»)
 * exists — «Bezahlt mit» on a receipt names one, so without it no receipt can be captured or booked
 * (module-creditor reads the same master data). One read of the file master data (a handful of rows; the file repository filters by getter, `isActive()` is not one).
 */
final class PaymentMethodCheck extends SetupCheck
{
    public function id(): string
    {
        return 'receipt.payment-method';
    }

    public function order(): int
    {
        return 70;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'payment-method', 'list'];
    }

    public function label(): string
    {
        return 'Zahlungsart erfassen';
    }

    public function isOpen(): bool
    {
        foreach (DI::getUnifiedEntityManager()->getRepository(PaymentMethod::class)->findAll() as $method) {
            if ($method->isActive()) {
                return false;
            }
        }

        return true;
    }
}
