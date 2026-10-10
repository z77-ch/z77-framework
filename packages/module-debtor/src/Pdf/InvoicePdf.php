<?php

namespace Z77\Module\Debtor\Pdf;

use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Invoicing\QrBill;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Services\Creditor;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The document as a PDF (P3 part 3, the part that waited for the PDF
 * library — owner 2026-10-06: FPDF through the kernel facade, the layouts
 * as partials). Rendered ON REQUEST from the document's immutable SNAPSHOT
 * — deterministic, the same document yields the same file — and NOT stored
 * (owner 2026-09-30; the Drive storage is a pending of `debtor.md`).
 *
 * This class only assembles the CONTEXT and hands it to the layout
 * `pdf/invoice` of this module (overridable per project under
 * `override/`): the document with its lines and taxes, its QR-bill
 * ({@see QrBill} — printable or not: an unprintable bill leaves the
 * payment part off, the document still prints), the mandator for the
 * letterhead (null = none saved, the letterhead stays empty), the customer
 * number of the party, and the amount formatter. Nothing is resolved from
 * master data — what prints is what was issued.
 *
 * {@see combined()} (owner 2026-10-10) draws SEVERAL documents into one
 * file — the batch just booked, opened once and printed or sent in one go:
 * each document is a section of its own (`PdfDocument::startSection()`),
 * starts on a new page, numbers its own pages and carries its own payment
 * part.
 */
final class InvoicePdf
{
    public const NS = 'Z77\Module\Debtor';

    /** The layout and the payment-part partial, in this module's `res/view/templates/`. */
    public const LAYOUT       = 'pdf/invoice';
    public const PAYMENT_PART = 'pdf/qrBill';

    /** The document rendered; call `output()` on it for the bytes. $document with its lines loaded. */
    public static function render(Invoice $document, ?Mandator $mandator, int $customerNumber = 0): PdfDocument
    {
        return self::draw(PdfDocument::create($document->documentName(), $mandator?->getName() ?? ''), $document, $mandator, $customerNumber);
    }

    /** The context a controller gathers: the mandator through the one reader debtor has, the customer number from the profile. */
    public static function of(Invoice $document, UnifiedEntityManager $em): PdfDocument
    {
        return self::render($document, Creditor::mandator($em), self::customerNumber($document, $em));
    }

    /**
     * Several documents in ONE file, in the order given — each a section of
     * its own (a new page, its own page count, its own payment part). The
     * mandator is read once. $documents with their lines loaded, at least one.
     *
     * @param list<Invoice> $documents
     */
    public static function combined(array $documents, UnifiedEntityManager $em): PdfDocument
    {
        if ($documents === []) {
            throw new \InvalidArgumentException('A combined PDF needs at least one document');
        }
        $mandator = Creditor::mandator($em);
        $pdf      = PdfDocument::create(self::combinedTitle($documents), $mandator?->getName() ?? '');
        foreach ($documents as $document) {
            self::draw($pdf->startSection(), $document, $mandator, self::customerNumber($document, $em));
        }

        return $pdf;
    }

    /** «rechnung-12.pdf» — the browser-reachable name, kebab-case lower (conventions: file names follow the layer). */
    public static function fileName(Invoice $document): string
    {
        return Naming::toSlug($document->documentName()) . '.pdf';
    }

    /**
     * The name of a combined file: one document → its own name; several →
     * «dokumente-26100001-bis-26100003.pdf» (lowest and highest number).
     *
     * @param list<Invoice> $documents
     */
    public static function combinedFileName(array $documents): string
    {
        return count($documents) === 1 ? self::fileName($documents[0]) : Naming::toSlug(self::combinedTitle($documents)) . '.pdf';
    }

    /** @param list<Invoice> $documents */
    private static function combinedTitle(array $documents): string
    {
        if (count($documents) === 1) {
            return $documents[0]->documentName();
        }
        $numbers = array_map(static fn(Invoice $d) => $d->getNumber(), $documents);

        return 'Dokumente ' . min($numbers) . ' bis ' . max($numbers);
    }

    private static function draw(PdfDocument $pdf, Invoice $document, ?Mandator $mandator, int $customerNumber): PdfDocument
    {
        return $pdf->partial(self::LAYOUT, [
            'document'       => $document,
            'bill'           => QrBill::of($document),
            'mandator'       => $mandator,
            'customerNumber' => $customerNumber,
            'fmt'            => static fn(?Money $m) => AmountFormat::of($m),
        ], self::NS);
    }

    private static function customerNumber(Invoice $document, UnifiedEntityManager $em): int
    {
        /** @var DebtorProfileRepository $profiles */
        $profiles = $em->getRepository(DebtorProfile::class);

        return $profiles->findByContact($document->getContact())?->getCustomerNumber() ?? 0;
    }
}
