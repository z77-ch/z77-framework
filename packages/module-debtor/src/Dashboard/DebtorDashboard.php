<?php

namespace Z77\Module\Debtor\Dashboard;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Repositories\BankTransactionRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\InvoiceSearch;
use Z77\Module\Debtor\Repositories\OpenItemSearch;
use Z77\Module\Debtor\Services\DebtorCurrency;
use Z77\Module\Debtor\Services\DunningRefusedException;
use Z77\Module\Debtor\Services\DunningService;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\DashboardContributor;
use Z77\Shared\Dashboard\DashboardEntry;
use Z77\Shared\Dashboard\Severity;
use Z77\Shared\Money\Money;

/**
 * debtor's pending work on the backend dashboard (ADR-052), registered under
 * `dashboard` in `debtorConfig`. Every entry is one aggregate read; the
 * targets are the host mounts under `/backend/finance/…` (module-backend).
 *
 *   - overdue final documents — URGENT, count + open amount → Debitoren,
 *     view «überfällig» (the open-item list's existing view);
 *   - a dunning proposal is ready (`DunningService::dueList()` as of today) —
 *     todo → Mahnungen; without an active dunning level there is no proposal;
 *   - bank transactions received, not booked (`matched` / `unmatched`) —
 *     todo → Zahlungseingänge;
 *   - drafts in `invoicing` (they still need the booking print) — todo →
 *     Rechnungen (its default view «in Fakturierung»);
 *   - the open Faktura-Journal — urgent while «Neu fakturieren» blocks it, else
 *     todo → Faktura-Journal ({@see journalEntry()}, shared with the order list).
 *
 * «No active payment target with an IBAN» is NOT here any more: it is the setup step
 * `debtor.payment-target` (`ProjectHealth\PaymentTargetCheck`, «Ersteinrichtung» 2026-11-19).
 */
final class DebtorDashboard implements DashboardContributor
{
    public function entries(AuthUser $user): iterable
    {
        $em    = DI::getUnifiedEntityManager();
        $today = new \DateTimeImmutable('today');
        /** @var InvoiceRepository $invoices */
        $invoices = $em->getRepository(Invoice::class);

        $totals = $invoices->openItemTotals(new OpenItemSearch($today, OpenItemSearch::VIEW_OVERDUE));
        if ($totals['overdueCount'] > 0) {
            yield DashboardEntry::pending(Severity::Urgent, 'Rechnungen überfällig', ['backend', 'finance', 'debtor', 'list'], ['view' => OpenItemSearch::VIEW_OVERDUE],
                $totals['overdueCount'], Money::fromDecimal($totals['overdue'], DebtorCurrency::base()));
        }

        try {
            $due = count((new DunningService($em, $user->getUserName()))->dueList($today));
        } catch (DunningRefusedException) {
            $due = 0;   // no active dunning level (or a run dated after today): no proposal to show
        }
        if ($due > 0) {
            yield DashboardEntry::pending(Severity::Todo, 'Mahnvorschlag bereit', ['backend', 'finance', 'dunning', 'list'], [], $due);
        }

        /** @var BankTransactionRepository $transactions */
        $transactions = $em->getRepository(BankTransaction::class);
        $unbooked     = $transactions->countUnbooked();
        if ($unbooked > 0) {
            yield DashboardEntry::pending(Severity::Todo, 'Zahlungseingänge nicht verbucht', ['backend', 'finance', 'bank-import', 'list'], [], $unbooked);
        }

        $drafts = $invoices->countSearch(new InvoiceSearch(InvoiceSearch::VIEW_INVOICING));
        if ($drafts > 0) {
            yield DashboardEntry::pending(Severity::Todo, 'Rechnungsentwürfe nicht fakturiert', ['backend', 'finance', 'invoice', 'list'], [], $drafts);
        }

        $journal = self::journalEntry($invoices->journalSummary());
        if ($journal !== null) {
            yield $journal;
        }
    }

    /**
     * ADR-054 step 9: the Faktura-Journal waits for «Journal verbuchen» — urgent while «Neu fakturieren»
     * blocks it, else todo; null when the journal is empty. The ONE decision of that weight: the
     * dashboard row and the order list's step box (`OrderControllerTrait::orderJournalStep()`, owner
     * 2026-11-27) both read it.
     *
     * @param array{count: int, blocked: int} $summary InvoiceRepository::journalSummary()
     */
    public static function journalEntry(array $summary): ?DashboardEntry
    {
        if ($summary['count'] <= 0) {
            return null;
        }
        $blocked = $summary['blocked'] > 0;

        return DashboardEntry::pending($blocked ? Severity::Urgent : Severity::Todo,
            $blocked ? 'Faktura-Journal gesperrt (neu fakturieren)' : 'Faktura-Journal nicht verbucht',
            ['backend', 'finance', 'invoice-journal', 'list'], [], $summary['count']);
    }
}
