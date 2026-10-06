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
        $author = $mandator?->getName() ?? '';

        return PdfDocument::create($document->documentName(), $author)
            ->partial(self::LAYOUT, [
                'document'       => $document,
                'bill'           => QrBill::of($document),
                'mandator'       => $mandator,
                'customerNumber' => $customerNumber,
                'fmt'            => static fn(?Money $m) => AmountFormat::of($m),
            ], self::NS);
    }

    /** The context a controller gathers: the mandator through the one reader debtor has, the customer number from the profile. */
    public static function of(Invoice $document, UnifiedEntityManager $em): PdfDocument
    {
        /** @var DebtorProfileRepository $profiles */
        $profiles = $em->getRepository(DebtorProfile::class);
        $profile  = $profiles->findByContact($document->getContact());

        return self::render($document, Creditor::mandator($em), $profile?->getCustomerNumber() ?? 0);
    }

    /** «rechnung-12.pdf» — the browser-reachable name, kebab-case lower (conventions: file names follow the layer). */
    public static function fileName(Invoice $document): string
    {
        return Naming::toSlug($document->documentName()) . '.pdf';
    }
}
