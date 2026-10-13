<?php

namespace Z77\Module\Financial\Close;

/**
 * The port a module implements when it keeps a SUBLEDGER whose total belongs on a collective
 * account of the ledger (owner decision 2026-10-13, review 2026-10-13 §2) — debtor's open items
 * on the receivables 1100, later a creditor module's on 2000. Financial knows no module: it asks
 * every registered reconciliation at the year close ({@see SubledgerReconciliationCheck}, scope
 * `period-close`) and compares each answer with the account's balance in the journal.
 *
 * Registered by class name under the module config key `subledgerReconciliations` (a list —
 * {@see SubledgerReconciliations}), or in an extension file
 * `App/Config/subledgerReconciliationsConfig.inc.php`. Instantiated without arguments: the
 * implementation reaches its services through DI, like an open-work check.
 *
 * What to answer: per collective account the module keeps, the balance (debit − credit) the
 * ledger SHOULD show at the end of `$to` — for debtor the open items at that day (invoices and
 * fees booked up to `$to`, minus credit notes, payments, discounts and losses booked up to it).
 * Nothing to reconcile (the module books elsewhere, its account is not configured) → yield
 * nothing. The answer is read in the close's snapshot; the implementation MUST NOT write.
 *
 * Until P5 brings the opening entry, the journal of a fiscal year carries that year's lines
 * only (FIN-REPORT-001): in a later year the comparison differs by the balance carried from the
 * year before. The check therefore reports a WARNING, never blocks — see the check.
 */
interface SubledgerReconciliationInterface
{
    /**
     * @param string             $fiscalYear the code of the year being closed
     * @param \DateTimeImmutable $from       its first day
     * @param \DateTimeImmutable $to         its last day — the balance is asked AT the end of this day
     * @return iterable<SubledgerBalance>
     */
    public function balances(string $fiscalYear, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}
