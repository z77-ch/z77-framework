<?php

namespace Z77\Module\Debtor\Pdf;

use Z77\Core\DI;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Entities\DunningNotice;
use Z77\Module\Debtor\Invoicing\QrBill;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Services\Creditor;
use Z77\Module\Debtor\Services\DocumentText;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The dunning notice as a PDF (plan §6.5, P4 part 3) — rendered on request
 * from the notice row, the invoice's SNAPSHOT, the level's current text
 * and the mandator; nothing stored (the invoice PDF's model). The layout
 * `pdf/dunningNotice` of this module (overridable under `override/`): the
 * letterhead, the recipient in the window, the level's title, its text in
 * the document's language (`DunningLevel::documentText`, the default
 * language as fallback — an empty text prints nothing), the invoice with
 * its open amount, the fee issued with the notice, the total — and the
 * QR-bill over open + fee under the INVOICE's reference (`QrBill::of()`
 * with the amount): the customer pays once, the CAMT booking places the
 * fee's share on the fee document.
 */
final class DunningPdf
{
    public const NS     = 'Z77\Module\Debtor';
    public const LAYOUT = 'pdf/dunningNotice';

    public static function render(DunningNotice $notice, ?DunningLevel $level, ?Mandator $mandator, int $customerNumber = 0): PdfDocument
    {
        $invoice  = $notice->getInvoice();
        $fee      = $notice->getFeeInvoice();
        $language = $invoice->getLanguage();
        $i18n     = DI::getI18n();
        $title    = $level?->getLabel() ?? $notice->getLevelCode();
        $text     = $level === null ? '' : DocumentText::resolve($level->getDocumentText(), $language, $i18n->getDefaultLanguage());
        $total    = $notice->getOpenAmount()->add($fee?->getGrossTotal() ?? Money::zero($invoice->getCurrency()));

        return PdfDocument::create($title . ' ' . $invoice->documentName(), $mandator?->getName() ?? '')
            ->partial(self::LAYOUT, [
                'notice'         => $notice,
                'invoice'        => $invoice,
                'fee'            => $fee,
                'title'          => $title,
                'text'           => $text,
                'total'          => $total,
                'bill'           => QrBill::of($invoice, $total),
                'mandator'       => $mandator,
                'customerNumber' => $customerNumber,
                'fmt'            => static fn(?Money $m) => AmountFormat::of($m),
            ], self::NS);
    }

    /** The context a controller gathers: the level by code, the mandator, the customer number. */
    public static function of(DunningNotice $notice, ?DunningLevel $level, UnifiedEntityManager $em): PdfDocument
    {
        /** @var DebtorProfileRepository $profiles */
        $profiles = $em->getRepository(DebtorProfile::class);
        $profile  = $profiles->findByContact($notice->getInvoice()->getContact());

        return self::render($notice, $level, Creditor::mandator($em), $profile?->getCustomerNumber() ?? 0);
    }

    /** «mahnung-1-rechnung-12.pdf» */
    public static function fileName(DunningNotice $notice, ?DunningLevel $level): string
    {
        return Naming::toSlug(($level?->getLabel() ?? $notice->getLevelCode()) . ' ' . $notice->getInvoice()->documentName()) . '.pdf';
    }
}
