<?php

namespace Z77\Module\Debtor\Close;

use Z77\Core\DI;
use Z77\Module\Debtor\Accounting\AccountingGateways;
use Z77\Module\Debtor\Accounting\NullAccountingGateway;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\PaymentAllocation;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\PaymentAllocationRepository;
use Z77\Module\Debtor\Services\AccountNotConfiguredException;
use Z77\Module\Debtor\Services\DebtorAccounts;
use Z77\Module\Debtor\Services\DebtorCurrency;
use Z77\Module\Financial\Close\SubledgerBalance;
use Z77\Module\Financial\Close\SubledgerReconciliationInterface;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * debtor's answer to financial's subledger reconciliation at the year close (owner decision
 * 2026-10-13, financial.md «The subledger reconciliation»): the open items of debtor, as the
 * receivable account should show them at the end of `$to`. Financial's
 * `SubledgerReconciliationCheck` compares it with the account's balance in the journal and
 * warns on a difference, naming the manual entries on the account.
 *
 * The SECOND class in debtor that names module-financial (after `LedgerAccountingGateway`):
 * it implements financial's port. Financial is only `suggest`ed — the class is loaded only
 * when financial's registry (`SubledgerReconciliations`) reads the config key
 * `subledgerReconciliations`, i.e. only where financial runs; debtorConfig names it as a
 * `::class` string, which loads nothing.
 *
 * The balance, debit − credit, exactly what debtor posts onto the receivable account:
 *
 *   + gross of every BOOKED invoice and fee document dated ≤ `$to`
 *   − gross of every BOOKED credit note dated ≤ `$to`
 *   − every BOOKED allocation (payment, discount, loss) whose payment is dated ≤ `$to`
 *
 * «Booked» = the document / allocation carries its `ledger_entry_ref`. Cumulative from the
 * first document, not from `$from`: until P5 brings the opening entry, a later year's journal
 * lacks the carried balance (FIN-REPORT-001) — financial's check reports that as a warning.
 *
 * Nothing to reconcile → yields nothing: financial not a registered module, the installation
 * books elsewhere (`NullAccountingGateway`), or the mandator names no receivable account.
 * A plain read, no lock, in the close's snapshot; writes nothing.
 */
final class ReceivablesReconciliation implements SubledgerReconciliationInterface
{
    /** What the subledger is called in financial's sentence («… stimmt nicht mit den offenen Posten Debitoren überein»). */
    public const LABEL = 'offenen Posten Debitoren';

    public function __construct(private readonly ?UnifiedEntityManager $em = null) {}

    public function balances(string $fiscalYear, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $em = $this->em ?? DI::getUnifiedEntityManager();
        if (!(new LedgerAccountCheck($em))->available() || self::booksElsewhere()) {
            return;
        }
        try {
            $account = (new DebtorAccounts($em))->number('receivable');
        } catch (AccountNotConfiguredException) {
            return;
        }

        /** @var InvoiceRepository $invoices */
        $invoices = $em->getRepository(Invoice::class);
        /** @var PaymentAllocationRepository $allocations */
        $allocations = $em->getRepository(PaymentAllocation::class);
        $currency    = DebtorCurrency::base();

        $balance = Money::fromDecimal($invoices->sumBookedUpTo($to), $currency)
            ->subtract(Money::fromDecimal($allocations->sumBookedUpTo($to), $currency));

        yield new SubledgerBalance($account, self::LABEL, $balance);
    }

    /** debtorConfig → accountingGateway names the NullAccountingGateway: debtor posts nothing into this ledger. */
    private static function booksElsewhere(): bool
    {
        $class = DI::getModuleManager()->getModuleConfig('debtor')?->get(AccountingGateways::CONFIG_KEY);

        return is_string($class) && ltrim(trim($class), '\\') === NullAccountingGateway::class;
    }
}
