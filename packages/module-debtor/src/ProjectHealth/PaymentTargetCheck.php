<?php

namespace Z77\Module\Debtor\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 5 («Ersteinrichtung», owner 2026-11-19): no ACTIVE payment target carries an IBAN or a
 * QR-IBAN — a QR-bill cannot be printed without one (the bank connection is debtor's, not the
 * mandator's, owner 2026-09-23). Moved here from `DebtorDashboard` («Kein Zahlungsziel mit IBAN»),
 * so the line exists once. One read of the file master data (a handful of rows; the file repository filters by getter, `isActive()` is not one).
 */
final class PaymentTargetCheck extends SetupCheck
{
    public function id(): string
    {
        return 'debtor.payment-target';
    }

    public function order(): int
    {
        return 50;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'payment-target', 'list'];
    }

    public function label(): string
    {
        return 'Zahlungsziel mit IBAN erfassen';
    }

    public function isOpen(): bool
    {
        foreach (DI::getUnifiedEntityManager()->getRepository(PaymentTarget::class)->findAll() as $target) {
            if ($target->isActive() && ($target->getIban() !== '' || $target->getQrIban() !== '')) {
                return false;
            }
        }

        return true;
    }
}
