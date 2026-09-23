<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Module\Debtor\Services\DebtorException;

/**
 * `debtorConfig → accountingGateway` names a gateway that cannot run here:
 * the class does not exist, does not implement {@see AccountingGateway}, or
 * — the default {@see LedgerAccountingGateway} — needs module-financial and
 * `financial` is not a registered module. Loud by design: an installation
 * that keeps its books elsewhere SAYS so by configuring
 * {@see NullAccountingGateway}; silently booking nothing would be the worst
 * of both.
 */
final class AccountingUnavailableException extends DebtorException
{
}
