<?php

namespace Z77\Module\Receipt\Services;

use Z77\Core\Services\ModuleManager;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * How often each payment method code («Bezahlt mit») is used — the column «Verwendet» of the master
 * data «Zahlungsarten» and the line in its window (RECEIPT-PM-COUNT-001). The receipts count
 * themselves; every OTHER module that stores the codes declares a {@see PaymentMethodUsageInterface}
 * under its config key `paymentMethodUsage` (the pattern of `openWorkChecks` and `contactRoles`:
 * registration is configuration, receipt names no module — module-creditor's invoices are one):
 *
 * ```php
 * // creditorConfig.inc.php
 * 'paymentMethodUsage' => [CreditorPaymentMethodUsage::class],
 * ```
 *
 * A bad entry (unknown class, not the interface) fails when asked, naming the module.
 */
final class PaymentMethodUsage
{
    public const CONFIG_KEY = 'paymentMethodUsage';

    /** @param list<PaymentMethodUsageInterface> $others the other modules' contributions */
    public function __construct(private readonly array $others = []) {}

    /** The contributions every module declares under `paymentMethodUsage`. */
    public static function fromModules(ModuleManager $modules): self
    {
        $others = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            foreach ((array) ($modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []) ?? []) as $class) {
                if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, PaymentMethodUsageInterface::class)) {
                    throw new \RuntimeException("❌ " . self::CONFIG_KEY . " of module '{$moduleKey}' names " . (is_string($class) ? "'{$class}'" : get_debug_type($class))
                        . ' — expected a class implementing ' . PaymentMethodUsageInterface::class . '.');
                }
                $others[$class] ??= new $class();
            }
        }

        return new self(array_values($others));
    }

    /**
     * code → how many receipts and other records carry it (only codes in use).
     *
     * @return array<string, int>
     */
    public function count(UnifiedEntityManager $em): array
    {
        $total = $em->getRepository(Receipt::class)->countByPaymentMethod();
        foreach ($this->others as $other) {
            foreach ($other->countByPaymentMethod($em) as $code => $n) {
                $total[(string) $code] = ($total[(string) $code] ?? 0) + (int) $n;
            }
        }

        return $total;
    }
}
