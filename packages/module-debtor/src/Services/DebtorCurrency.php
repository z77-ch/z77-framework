<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;

/**
 * The installation's base currency — `systemConfig → baseCurrency`, the
 * SAME key the Doctrine bootstrap hands `MoneyType` and financial reads in
 * `LedgerService::baseCurrency()` (ADR-042 decision 4).
 *
 * The SETTING lives once (Rule 2); this is a reader of it, and debtor needs
 * its own because module-financial is only `suggest`ed — a module that may
 * be absent cannot be where a present module asks. `MoneyType` exposes no
 * getter, and asking it would tie this module to the Doctrine driver having
 * booted first.
 *
 * Q6 decided against foreign-currency invoicing, so in P3 part 1 there is
 * exactly one currency in this module: this one. It is what a
 * `DunningLevel`'s stored minor units become `Money` in.
 */
final class DebtorCurrency
{
    public static function base(): string
    {
        return (string) DI::getConfigManager()
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false)
            ->get('baseCurrency', 'CHF');
    }
}
