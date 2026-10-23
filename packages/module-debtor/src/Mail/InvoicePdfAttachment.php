<?php

namespace Z77\Module\Debtor\Mail;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Pdf\InvoiceArchive;
use Z77\Module\Debtor\Pdf\InvoicePdf;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Mailing\Attachments\AttachmentProvider;
use Z77\Shared\Mail\Attachment;

/**
 * The outbox attachment `invoice-pdf:<id>` (step 3 of the mail plan, owner
 * 2026-10-22; ADR-053 decision 7): a BOOKED document (invoice, credit note,
 * fee) as the PDF the booking print printed. Registered in this module's
 * config under `mailAttachments`; module-mailing's registry instantiates it —
 * this class is only ever loaded where module-mailing is installed (debtor
 * does not require it, `composer.json` suggest).
 *
 *   - {@see accepts()} at QUEUE time: the id names an ISSUED document —
 *     `issued` (in the Faktura-Journal, ADR-054 decision 21) or `final`. A draft
 *     has no number and prints only as a preview — never mailed; a void one never;
 *   - {@see resolve()} at SEND time: the ARCHIVED PDF of the document's current
 *     version ({@see InvoiceArchive::pdf()}, owner decision O13 of 2026-10-23) —
 *     byte for byte the file «Fakturieren» stored, SHA-256 checked; a document
 *     posted before the journal renders from its snapshot as before. A missing
 *     or altered archive file throws — a retry of that mail (the outbox's
 *     contract), the cause in the error log. The revision-bound reference
 *     `invoice-pdf:<id>@<revision>` comes with step 8 of the build plan.
 */
final class InvoicePdfAttachment implements AttachmentProvider
{
    public const TYPE = 'invoice-pdf';

    public function accepts(string $id): bool
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            return false;
        }

        return $this->invoices()->find((int) $id)?->isIssued() ?? false;
    }

    public function resolve(string $id): Attachment
    {
        $document = preg_match('/^[1-9]\d{0,9}$/', $id) === 1 ? $this->invoices()->withLines((int) $id) : null;
        if ($document === null || !$document->isIssued()) {
            throw new \RuntimeException("Invoice {$id} is missing or not issued — no PDF to attach.");
        }

        return new Attachment(
            InvoicePdf::fileName($document),
            'application/pdf',
            (new InvoiceArchive(DI::getUnifiedEntityManager()))->pdf($document)
        );
    }

    private function invoices(): InvoiceRepository
    {
        return DI::getUnifiedEntityManager()->getRepository(Invoice::class);
    }
}
