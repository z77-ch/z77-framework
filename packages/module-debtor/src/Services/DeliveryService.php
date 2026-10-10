<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\DeliveryChannel;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «Fakturiert» — the one write path of the delivery fact (owner 2026-10-10).
 *
 * The workflow of a document reads «In Fakturierung» → «Verbucht» →
 * «Fakturiert»: booking (`InvoicingService::finalize()`) makes it immutable
 * and posts it; delivery records that it actually reached the customer. The
 * two are independent facts — delivery is NOT a posting state: it changes
 * nothing in the ledger, the open amount, payments or dunning, and it does
 * not bump the document's `version`. Only a FINAL document can be delivered.
 *
 *   - {@see recordPdf()} — the FIRST time a document's PDF is produced in the
 *     backend (the detail's «PDF», the combined PDF of a booked batch): the
 *     final ones among the ids not yet marked are marked `pdf`; a later PDF
 *     and a document in `invoicing` change nothing. Silent by design — it is
 *     a side effect of opening the PDF, never a refusal;
 *   - {@see markManually()} — «Als fakturiert markieren» (sent another way);
 *   - {@see unmark()} — «Markierung entfernen», only for a MANUAL mark: a
 *     misclick is undone, a mark the system recorded is a fact.
 *
 * The e-mail dispatch (later) records through this class too, with its own
 * channel (`debtor.md` pending).
 */
final class DeliveryService
{
    private readonly string $actor;

    /** @param string|null $actor the author's name; null = the session identity ({@see Actor::current()}) */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : Actor::current();
    }

    /**
     * The PDF of these documents was produced: mark the FINAL ones not yet
     * delivered (first one wins). Answers how many were marked now.
     *
     * @param list<int> $ids
     */
    public function recordPdf(array $ids): int
    {
        return $this->invoices()->markDelivered($ids, new \DateTimeImmutable(), $this->actor, DeliveryChannel::Pdf->value);
    }

    /**
     * «Als fakturiert markieren».
     *
     * @throws InvoiceRefusedException `not-found`, `not-final` (still in invoicing), `already-delivered`
     */
    public function markManually(int $id): void
    {
        $document = $this->document($id);
        if (!$document->isFinal()) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NOT_FINAL, $document->documentName() . ' ist noch in Fakturierung — erst verbuchen, dann fakturieren.');
        }
        if ($this->invoices()->markDelivered([$id], new \DateTimeImmutable(), $this->actor, DeliveryChannel::Manual->value) === 0) {
            throw new InvoiceRefusedException(InvoiceRefusedException::ALREADY_DELIVERED, $document->documentName() . ' ist bereits als fakturiert markiert.');
        }
    }

    /**
     * «Markierung entfernen» — only a mark set by hand.
     *
     * @throws InvoiceRefusedException `not-found`, `not-manual` (not marked, or recorded by the PDF)
     */
    public function unmark(int $id): void
    {
        $document = $this->document($id);
        if ($this->invoices()->unmarkDelivered($id, DeliveryChannel::Manual->value) === 0) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NOT_MANUAL, $document->isDelivered()
                ? $document->documentName() . ' wurde beim Öffnen des PDFs als fakturiert festgehalten — diese Markierung bleibt bestehen.'
                : $document->documentName() . ' ist nicht als fakturiert markiert.');
        }
    }

    private function document(int $id): Invoice
    {
        $document = $id > 0 ? $this->invoices()->find($id) : null;
        if ($document === null) {
            throw new InvoiceRefusedException(InvoiceRefusedException::NOT_FOUND, 'Dokument nicht gefunden.');
        }

        return $document;
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em->getRepository(Invoice::class);
    }
}
