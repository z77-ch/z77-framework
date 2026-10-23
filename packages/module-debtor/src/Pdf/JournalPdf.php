<?php

namespace Z77\Module\Debtor\Pdf;

use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceJournalPosting;
use Z77\Module\Debtor\Services\Creditor;
use Z77\Module\Debtor\Services\JournalPostingService;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Pdf\PdfDocument;

/**
 * The JOURNAL LIST as a PDF («Journal drucken», ADR-054 decision 7, owner 2026-10-23): every
 * document of the open Faktura-Journal — or of one posted batch («Verbuchte Journale») — with
 * number, date, customer, net, VAT, gross, stamp and the hints («Neu fakturieren nötig»), then the
 * totals per VAT code, the grand total and the journal entries the posting books (posts) per
 * account, as `PostingBuilder` builds them. Rendered on request (a working list, not a document
 * of the customer — nothing is archived). The layout is the partial `pdf/invoiceJournal` of this
 * module (overridable under `override/`).
 */
final class JournalPdf
{
    public const NS     = 'Z77\Module\Debtor';
    public const LAYOUT = 'pdf/invoiceJournal';

    /**
     * @param list<Invoice> $documents in number order, lines loaded
     * @param array<string, mixed> $totals {@see JournalPostingService::totals()}
     * @param array{entries: int, rows: list<array{account: string, debit: Money, credit: Money}>}|null $preview null = the receivable account is not set
     */
    public static function render(array $documents, array $totals, ?array $preview, ?InvoiceJournalPosting $posting, ?\Z77\Module\Mandator\Entities\Mandator $mandator, string $currency): PdfDocument
    {
        $title = $posting === null
            ? 'Faktura-Journal — nicht verbucht, Stand ' . (new \DateTimeImmutable())->format('d.m.Y H:i')
            : 'Faktura-Journal — verbucht am ' . $posting->getPostedAt()->format('d.m.Y H:i') . ' von ' . $posting->getPostedBy();

        return PdfDocument::create($title, $mandator?->getName() ?? '')->partial(self::LAYOUT, [
            'documents' => $documents,
            'totals'    => $totals,
            'preview'   => $preview,
            'posting'   => $posting,
            'mandator'  => $mandator,
            'title'     => $title,
            'currency'  => $currency,
            'fmt'       => static fn(?Money $m) => AmountFormat::of($m),
        ], self::NS);
    }

    /** The context a controller gathers: the documents, totals and preview of the open journal or of $posting. */
    public static function of(?InvoiceJournalPosting $posting, UnifiedEntityManager $em, JournalPostingService $journal, string $currency): PdfDocument
    {
        $documents = $em->getRepository(Invoice::class)->journalDocuments($posting?->getId());
        try {
            $preview = $journal->preview($documents);
        } catch (\Z77\Module\Debtor\Services\AccountNotConfiguredException) {
            $preview = null;
        }

        return self::render($documents, $journal->totals($posting?->getId()), $preview, $posting, Creditor::mandator($em), $currency);
    }

    /** «faktura-journal.pdf» / «faktura-journal-7.pdf» (a posted batch by its protocol id). */
    public static function fileName(?InvoiceJournalPosting $posting): string
    {
        return $posting === null ? 'faktura-journal.pdf' : 'faktura-journal-' . (int) $posting->getId() . '.pdf';
    }
}
