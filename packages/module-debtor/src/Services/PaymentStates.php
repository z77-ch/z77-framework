<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * The READ PORT through which a source asks the receivables side how far its booked documents
 * are settled (ADR-040 decision 6, plan §7): an order's list and window show «offen /
 * teilbezahlt / bezahlt / gutgeschrieben» without debtor ever calling the source and without
 * «bezahlt» becoming a status of the source (owner 2026-10-14, O1).
 *
 * ONE query for a whole page of ids (`InvoiceRepository::settlementFiguresOf()`, the
 * aggregates of the open-item list — DEBTOR-OPEN-001), no lock, no write. Derived exactly as
 * `InvoicingService::openAmount()` is: gross − final credit notes − allocations.
 */
final class PaymentStates
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * @param list<int> $invoiceIds the documents a page shows
     * @return array<int, PaymentState> by id — only BOOKED payable documents (invoice, fee); an unknown id, a
     *                                  draft or a credit note has no payment state and is left out
     */
    public function of(array $invoiceIds): array
    {
        /** @var InvoiceRepository $invoices */
        $invoices = $this->em->getRepository(Invoice::class);
        $states   = [];
        foreach ($invoices->settlementFiguresOf($invoiceIds) as $id => $f) {
            $states[$id] = PaymentState::of(
                Money::fromDecimal($f['gross'], $f['currency']),
                Money::fromDecimal($f['credited'], $f['currency']),
                Money::fromDecimal($f['allocated'], $f['currency']),
            );
        }

        return $states;
    }
}
