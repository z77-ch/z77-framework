<?php
/**
 * PDF layout: the JOURNAL LIST of the Faktura-Journal (ADR-054 decision 7) — A4 landscape is not
 * needed: portrait, a flowing table (kernel `pdf/table`) with one row per document, then the
 * totals per VAT code, the grand total and the journal entries «Journal verbuchen» books, summed
 * per account. Credit notes count negative (as the receivable takes them). Draws through `$pdf`
 * only — echoes nothing. A project overrides THIS file under `override/`.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents  number order
 * @var array<string, mixed> $totals    JournalPostingService::totals()
 * @var array|null $preview  JournalPostingService::preview() — null: the receivable account is not set
 * @var \Z77\Module\Debtor\Entities\InvoiceJournalPosting|null $posting  null = the open journal
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 * @var string $title
 * @var string $currency
 * @var callable $fmt  Money → «1'234.50»
 */
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Shared\Money\Money;

$left  = 15.0;
$width = 180.0;
$money = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);

$pdf->onPageEnd(static function ($pdf) use ($title, $left, $width): void {
    $pdf->font('', 7.5)->textColor(110)->text($left, 287, $title . '  ·  Seite ' . $pdf->pageNo() . ' von {nb}', 'R', $width)->textColor(0);
});
$pdf->autoPageBreak(18)->addPage();

$pdf->font('B', 13)->text($left, 14, 'Faktura-Journal');
$pdf->font('', 9)->textColor(90)->text($left, 20, ($mandator !== null ? $mandator->getName() . '  ·  ' : '') . ($posting === null
    ? 'nicht verbucht — Stand ' . (new \DateTimeImmutable())->format('d.m.Y H:i')
    : 'verbucht am ' . $posting->getPostedAt()->format('d.m.Y H:i') . ' von ' . $posting->getPostedBy() . '  ·  Nr. ' . $posting->getFirstNumber() . ' – ' . $posting->getLastNumber()))->textColor(0);

$rows = [];
foreach ($documents as $document) {
    $sign  = $document->kind()->sign();
    $hint  = $document->getReissueNeeded() !== null ? 'Neu fakturieren nötig: ' . $document->getReissueNeeded() : '';
    $party = trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName());
    $rows[] = ['cells' => [
        $document->isCreditNote() ? 'Gutschrift ' . $document->getNumber() : (string) $document->getNumber(),
        $document->getInvoiceDate()->format('d.m.Y'),
        $party . ($document->subjectLine() !== null ? "\n" . $document->subjectLine() : '') . ($hint !== '' ? "\n" . $hint : ''),
        $fmt($document->getNetTotal()->multiply($sign)),
        $fmt($document->getTaxTotal()->multiply($sign)),
        $fmt($document->getGrossTotal()->multiply($sign)),
        (string) $document->getDocumentStamp(),
    ], 'bold' => $hint !== ''];
}
if ($rows === []) {
    $pdf->font('', 10)->text($left, 30, 'Das Faktura-Journal ist leer.');
    return;
}
$rows[] = ['cells' => ['Total', '', $totals['count'] . ($totals['count'] === 1 ? ' Dokument' : ' Dokumente'), $fmt($money($totals['net'])), $fmt($money($totals['tax'])), $fmt($money($totals['gross'])), ''], 'bold' => true, 'rule' => true];

$pdf->partial('pdf/table', [
    'columns' => [
        ['label' => 'Nr.', 'width' => 24],
        ['label' => 'Datum', 'width' => 18],
        ['label' => 'Kunde · Betreff', 'width' => 58],
        ['label' => 'Netto', 'width' => 20, 'align' => 'R'],
        ['label' => 'MWST', 'width' => 17, 'align' => 'R'],
        ['label' => 'Total', 'width' => 20, 'align' => 'R'],
        ['label' => 'ID', 'width' => 23],
    ],
    'rows'     => $rows,
    'x'        => $left,
    'y'        => 28,
    'wrap'     => 2,
    'fontSize' => 8,
    'lineHeight' => 4,
], 'Z77\\Shared');

// ── per VAT code ──
if ($totals['vat'] !== []) {
    $pdf->ensureSpace(12 + 4.5 * count($totals['vat']));
    $vatRows = [];
    foreach ($totals['vat'] as $vat) {
        $vatRows[] = ['cells' => [$vat['code'], $vat['label'], TaxRate::formatPercent($vat['rate']) . ' %', $fmt($money($vat['base'])), $fmt($money($vat['tax']))]];
    }
    $pdf->font('B', 9)->text($left, $pdf->y() + 5, 'MWST je Code');
    $pdf->partial('pdf/table', [
        'columns' => [
            ['label' => 'Code', 'width' => 20],
            ['label' => 'Bezeichnung', 'width' => 70],
            ['label' => 'Satz', 'width' => 20, 'align' => 'R'],
            ['label' => 'Basis', 'width' => 35, 'align' => 'R'],
            ['label' => 'MWST', 'width' => 35, 'align' => 'R'],
        ],
        'rows' => $vatRows, 'x' => $left, 'y' => $pdf->y() + 10, 'wrap' => 1, 'fontSize' => 8, 'lineHeight' => 4,
    ], 'Z77\\Shared');
}

// ── what the posting books, per account ──
if ($preview !== null && $preview['rows'] !== []) {
    $pdf->ensureSpace(12 + 4.5 * count($preview['rows']));
    $accountRows = [];
    foreach ($preview['rows'] as $row) {
        $accountRows[] = ['cells' => [$row['account'], $row['debit']->isZero() ? '' : $fmt($row['debit']), $row['credit']->isZero() ? '' : $fmt($row['credit'])]];
    }
    $pdf->font('B', 9)->text($left, $pdf->y() + 5, ($posting === null ? 'Buchungen beim Verbuchen' : 'Verbuchte Buchungen') . ' — ' . $preview['entries'] . ' Journaleinträge, summiert je Konto');
    $pdf->partial('pdf/table', [
        'columns' => [
            ['label' => 'Konto', 'width' => 60],
            ['label' => 'Soll', 'width' => 40, 'align' => 'R'],
            ['label' => 'Haben', 'width' => 40, 'align' => 'R'],
        ],
        'rows' => $accountRows, 'x' => $left, 'y' => $pdf->y() + 10, 'wrap' => null, 'fontSize' => 8, 'lineHeight' => 4,
    ], 'Z77\\Shared');
}
