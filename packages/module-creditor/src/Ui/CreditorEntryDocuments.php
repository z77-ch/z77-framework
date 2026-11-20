<?php

namespace Z77\Module\Creditor\Ui;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Financial\Ledger\EntryDocument;
use Z77\Module\Financial\Ledger\EntryDocumentProviderInterface;
use Z77\Module\Receipt\Ui\ReceiptPhoto;

/**
 * Creditor's answer to the finance journal's «which document stands behind this entry»
 * (`creditorConfig → entryDocuments`, owner 2026-11-20): an entry of origin `creditor` / the
 * invoice id ({@see \Z77\Module\Creditor\Posting\DirectPosting}) opens the supplier's PDF — a
 * reversal inherits the origin, so it opens the same PDF. The address is the DMS's read-gated
 * preview, taken from module-receipt's {@see ReceiptPhoto} like the invoice window does; a PDF the
 * session may not read, or one that is gone, is left out — no icon.
 *
 * One SQL query for the page (invoice ids → document ids), then the DMS gate per PDF.
 */
final class CreditorEntryDocuments implements EntryDocumentProviderInterface
{
    public function documentsFor(array $entries): array
    {
        $invoiceOf = [];
        foreach ($entries as $entry) {
            if ($entry['sourceType'] === CreditorInvoice::SOURCE_TYPE && ctype_digit($entry['sourceRef'])) {
                $invoiceOf[$entry['id']] = (int) $entry['sourceRef'];
            }
        }
        if ($invoiceOf === []) {
            return [];
        }
        /** @var CreditorInvoiceRepository $invoices */
        $invoices  = DI::getUnifiedEntityManager()->getRepository(CreditorInvoice::class);
        $documents = $invoices->documentIdsOf(array_values($invoiceOf));
        $pdfs      = ReceiptPhoto::of(array_values($documents));
        $out       = [];
        foreach ($invoiceOf as $entryId => $invoiceId) {
            $pdf = $pdfs[$documents[$invoiceId] ?? 0] ?? null;
            if ($pdf !== null && $pdf['state'] === 'doc' && $pdf['previewUrl'] !== null) {
                $out[$entryId] = new EntryDocument($pdf['previewUrl'], 'Rechnungs-PDF');
            }
        }

        return $out;
    }
}
