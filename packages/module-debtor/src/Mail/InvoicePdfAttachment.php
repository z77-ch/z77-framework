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
 * The outbox attachment `invoice-pdf:<id>@<revision>` (step 3 of the mail plan, owner 2026-10-22;
 * ADR-053 decision 7; the revision since step 8 of the Faktura-Journal, ADR-054 decision 21): an
 * ISSUED document (invoice, credit note, fee) as the PDF of the VERSION the mail was queued with.
 * Registered in this module's config under `mailAttachments`; module-mailing's registry
 * instantiates it — this class is only ever loaded where module-mailing is installed (debtor does
 * not require it, `composer.json` suggest).
 *
 *   - {@see accepts()} at QUEUE time: the id names an ISSUED document — `issued` (in the
 *     Faktura-Journal) or `final` — and, with `@<revision>`, one of its archived versions. A draft has
 *     no number and prints only as a preview — never mailed; a void one never;
 *   - {@see resolve()} at SEND time: the ARCHIVED PDF of the named revision
 *     ({@see InvoiceArchive::pdfOfRevision()}, owner decision O13) — byte for byte the file stored when
 *     that version was issued, SHA-256 checked — so a mail queued before a revision sends what it was
 *     queued with. A mail the job already claimed of a document replaced since still sends its
 *     version (a waiting one is cancelled by the replacement). The plain `invoice-pdf:<id>` (queued
 *     before step 8) is the current version; a document posted before the journal renders from its
 *     snapshot as before. A missing or altered archive file throws — a retry of that mail (the
 *     outbox's contract), the cause in the error log.
 */
final class InvoicePdfAttachment implements AttachmentProvider
{
    public const TYPE = 'invoice-pdf';

    private const PATTERN = '/^([1-9]\d{0,9})(?:@([1-9]\d{0,5}))?$/';

    public function accepts(string $id): bool
    {
        if (preg_match(self::PATTERN, $id, $m) !== 1) {
            return false;
        }
        $document = $this->invoices()->find((int) $m[1]);
        if ($document === null || !$document->isIssued()) {
            return false;
        }

        return !isset($m[2]) || (int) $m[2] <= $document->getRevision();
    }

    public function resolve(string $id): Attachment
    {
        $document = preg_match(self::PATTERN, $id, $m) === 1 ? $this->invoices()->withLines((int) $m[1]) : null;
        $revision = isset($m[2]) ? (int) $m[2] : null;
        // A revision-bound reference resolves for any numbered document with that archived version (a
        // claimed mail of a document replaced since sends what it was queued with); the plain one only
        // for an issued document.
        if ($document === null || $document->isDraft() || ($revision === null && !$document->isIssued())) {
            throw new \RuntimeException("Invoice {$id} is missing or not issued — no PDF to attach.");
        }
        $archive = new InvoiceArchive(DI::getUnifiedEntityManager());
        $bytes   = $revision === null || $revision === $document->getRevision() ? $archive->pdf($document) : $archive->pdfOfRevision($document, $revision);
        if ($bytes === null) {
            throw new \RuntimeException("Invoice {$id}: revision {$revision} was never archived — no PDF to attach.");
        }

        return new Attachment(InvoicePdf::fileName($document), 'application/pdf', $bytes);
    }

    private function invoices(): InvoiceRepository
    {
        return DI::getUnifiedEntityManager()->getRepository(Invoice::class);
    }
}
