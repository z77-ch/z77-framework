<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Core\DI;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Selects the {@see AccountingGateway} an installation runs (plan §6.6):
 * `debtorConfig → accountingGateway`, a class name — the `memberConfig`
 * hook pattern (ADR-038: configuration names a class, the module never
 * looks for one). The package config names {@see LedgerAccountingGateway};
 * a project without z77 bookkeeping writes {@see NullAccountingGateway} into
 * its override (which, BOOT-CONFIG-001, must carry the full config).
 *
 * Read at the point of use (`InvoicingService` builds its gateway when it
 * is constructed without one), never at boot. Fails loudly on a missing,
 * malformed or unusable value — a wrong setting must not book nothing.
 */
final class AccountingGateways
{
    public const CONFIG_KEY = 'accountingGateway';

    /**
     * @param string $actor the author's name, handed to the gateway (the ledger stamps it)
     * @throws \UnexpectedValueException        the key is missing or not a string
     * @throws AccountingUnavailableException  the class is missing, not a gateway, or cannot run here
     */
    public static function fromConfig(UnifiedEntityManager $em, string $actor): AccountingGateway
    {
        $config = DI::getModuleManager()->getModuleConfig('debtor');
        $class  = $config?->has(self::CONFIG_KEY) ? $config->get(self::CONFIG_KEY) : null;
        if (!is_string($class) || trim($class) === '') {
            throw new \UnexpectedValueException(
                'debtorConfig: ' . self::CONFIG_KEY . ' must name the AccountingGateway class to run (' . LedgerAccountingGateway::class
                . ' or ' . NullAccountingGateway::class . '), got ' . var_export($class, true) . '.'
            );
        }
        $class = ltrim(trim($class), '\\');
        if (!class_exists($class)) {
            throw new AccountingUnavailableException("debtorConfig → accountingGateway names '{$class}', which does not exist.");
        }
        if (!is_subclass_of($class, AccountingGateway::class)) {
            throw new AccountingUnavailableException("debtorConfig → accountingGateway names '{$class}', which does not implement " . AccountingGateway::class . '.');
        }

        return new $class($em, $actor);
    }
}
