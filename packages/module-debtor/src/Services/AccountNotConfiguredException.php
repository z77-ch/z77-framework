<?php

namespace Z77\Module\Debtor\Services;

/**
 * A `debtorConfig → debtorAccounts` key names no account, or names one the
 * bookkeeping will not take a posting on (missing, a group, inactive).
 *
 * Raised at the POINT OF USE, never at boot: a key nobody reads must not
 * stop an installation that does not use it. {@see getMessage()} is German
 * and names the key, so the screen can print it as it is
 * ({@see DebtorAccounts}).
 */
final class AccountNotConfiguredException extends DebtorException
{
    public function __construct(
        public readonly string $key,
        string $message,
    ) {
        parent::__construct($message);
    }
}
