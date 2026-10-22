<?php

namespace Z77\Module\Debtor\Dashboard;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Repositories\BankTransactionRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\InvoiceSearch;
use Z77\Module\Debtor\Repositories\OpenItemSearch;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
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
 *   - no active payment target with an IBAN — todo → Zahlungsziele: a
 *     QR-bill cannot be printed without one (the bank connection is debtor's,
 *     not the mandator's — owner 2026-09-23).
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
            yield DashboardEntry::pending(Severity::Todo, 'Rechnungsentwürfe nicht verbucht', ['backend', 'finance', 'invoice', 'list'], [], $drafts);
        }

        /** @var PaymentTargetRepository $targets */
        $targets = $em->getRepository(PaymentTarget::class);
        $payable = array_filter($targets->allInOrder(), static fn(PaymentTarget $t) => $t->isActive() && ($t->getIban() !== '' || $t->getQrIban() !== ''));
        if ($payable === []) {
            yield DashboardEntry::pending(Severity::Todo, 'Kein Zahlungsziel mit IBAN', ['backend', 'finance', 'payment-target', 'list']);
        }
    }
}
