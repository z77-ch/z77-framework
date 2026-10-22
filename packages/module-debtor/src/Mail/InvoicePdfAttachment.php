<?php

namespace Z77\Module\Debtor\Mail;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\Invoice;
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
 *   - {@see accepts()} at QUEUE time: the id names a FINAL document. A draft
 *     has no number and prints only as a preview — never mailed;
 *   - {@see resolve()} at SEND time: the PDF rendered from the document's
 *     SNAPSHOT through {@see InvoicePdf::of()} — deterministic, the same file
 *     as the reprint and as the booking print (up to the PDF's creation
 *     stamp). A throw is a retry of that mail (the outbox's contract).
 */
final class InvoicePdfAttachment implements AttachmentProvider
{
    public const TYPE = 'invoice-pdf';

    public function accepts(string $id): bool
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            return false;
        }

        return $this->invoices()->find((int) $id)?->isFinal() ?? false;
    }

    public function resolve(string $id): Attachment
    {
        $document = preg_match('/^[1-9]\d{0,9}$/', $id) === 1 ? $this->invoices()->withLines((int) $id) : null;
        if ($document === null || !$document->isFinal()) {
            throw new \RuntimeException("Invoice {$id} is missing or not booked — no PDF to attach.");
        }

        return new Attachment(
            InvoicePdf::fileName($document),
            'application/pdf',
            InvoicePdf::of($document, DI::getUnifiedEntityManager())->output()
        );
    }

    private function invoices(): InvoiceRepository
    {
        return DI::getUnifiedEntityManager()->getRepository(Invoice::class);
    }
}
