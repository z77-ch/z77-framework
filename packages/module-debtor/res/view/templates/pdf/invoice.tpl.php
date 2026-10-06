<?php
/**
 * PDF layout: an invoice or a credit note (P3 part 3) — A4 portrait, from
 * the document's SNAPSHOT only. The page: the mandator's letterhead
 * (module-mandator `pdf/letterhead`), the recipient in the envelope window
 * (kernel `pdf/addressWindow`), the title and the document facts, the lines
 * as a flowing table (kernel `pdf/table`, continued on further pages with
 * a running header), the tax summary and the totals, the terms sentence —
 * and, on an invoice with a printable QR-bill, the PAYMENT PART at the foot
 * of the last page (`pdf/qrBill`, the 105 mm zone the guidelines reserve;
 * nothing else is printed there, the footer included).
 *
 * A project that wants another look overrides THIS file under `override/`
 * (and only this file: the facts come from the context, the blocks are the
 * shared partials). Draws through `$pdf` only — echoes nothing.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Debtor\Entities\Invoice $document  lines loaded
 * @var \Z77\Module\Debtor\Invoicing\QrBill $bill
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 * @var int $customerNumber  0 = unknown
 * @var callable $fmt  Money → «1'234.50»
 */
use Z77\Module\Vat\Entities\TaxRate;

$left   = 20.0;
$right  = 190.0;
$width  = $right - $left;
$name   = $document->documentName();
$isCredit = $document->isCreditNote();

// ── running header (pages 2+) and footer (not on the page with the payment part) ──
$pdf->onPageStart(static function ($pdf) use ($name, $left, $right): void {
    if ($pdf->pageNo() > 1) {
        $pdf->font('', 8)->textColor(110)->text($left, 12, $name . '  ·  Seite ' . $pdf->pageNo() . ' von {nb}')->textColor(0);
        $pdf->setY(24);
    }
});
$pdf->onPageEnd(static function ($pdf) use ($name, $left, $width): void {
    if ($pdf->get('paymentPart') === true) {
        return;
    }
    $pdf->font('', 7.5)->textColor(110)->text($left, 284, $name . '  ·  Seite ' . $pdf->pageNo() . ' von {nb}', 'R', $width)->textColor(0);
});

$pdf->autoPageBreak(30)->addPage();

// ── letterhead and recipient ───────────────────────────────────────────
$pdf->partial('pdf/letterhead', ['mandator' => $mandator, 'x' => $left, 'y' => 12], 'Z77\\Module\\Mandator');
$pdf->partial('pdf/addressWindow', ['lines' => $document->getAddress()->lines(), 'x' => $left, 'y' => 50], 'Z77\\Shared');

// ── title and facts ────────────────────────────────────────────────────
$place = $mandator !== null && trim($mandator->getCity()) !== '' ? $mandator->getCity() . ', ' : '';
$pdf->font('', 9)->text($left, 92, $place . $document->getInvoiceDate()->format('d.m.Y'));
$pdf->font('B', 14)->text($left, 100, $name);

$period = $document->getServiceFrom()->format('d.m.Y') . ($document->getServiceTo() !== null ? ' – ' . $document->getServiceTo()->format('d.m.Y') : '');
$facts  = [];
if ($customerNumber > 0) {
    $facts[] = ['Kundennummer', (string) $customerNumber];
}
$facts[] = ['Leistung', $period];
if ($isCredit && $document->getCreditNoteOf() !== null) {
    $facts[] = ['Gutschrift zu', $document->getCreditNoteOf()->documentName() . ' vom ' . $document->getCreditNoteOf()->getInvoiceDate()->format('d.m.Y')];
} else {
    $facts[] = ['Zahlbar bis', $document->getDueDate()->format('d.m.Y')];
}
$facts[] = ['Preise', $document->getPriceMode() === 'gross' ? 'inkl. MWST' : 'zzgl. MWST'];
$y = 108;
foreach ($facts as [$label, $value]) {
    $pdf->font('', 9)->textColor(110)->text($left, $y, $label)->textColor(0)->text($left + 32, $y, $value);
    $y += 4.4;
}

// ── the lines, then the summary rows in the same table ─────────────────
$rows = [];
foreach ($document->getLines() as $line) {
    $isText = $line->type()->value === 'text';
    $text   = $line->getText();
    if ($line->getDiscountPercent() > 0) {
        $text .= ' (Rabatt ' . TaxRate::formatPercent($line->getDiscountPercent()) . ' %)';
    }
    $rows[] = [
        'cells'  => [
            $text,
            $line->getQuantity() !== null ? rtrim(rtrim($line->getQuantity(), '0'), '.') . ' ' . (string) $line->getUnit() : '',
            $line->getUnitPrice() !== null ? $fmt($line->getUnitPrice()) : '',
            $line->getTaxCode() !== null ? TaxRate::formatPercent((int) $line->getTaxRate()) . ' %' : '',
            $isText ? '' : $fmt($line->getAmount()),
        ],
        'indent' => $line->getParentLine() !== null ? 4 : 0,
    ];
}
$rows[] = ['cells' => ['Netto', '', '', '', $fmt($document->getNetTotal())], 'rule' => true];
foreach ($document->getTaxes() as $tax) {
    $rows[] = ['cells' => ['MWST ' . TaxRate::formatPercent($tax->getTaxRate()) . ' % auf ' . $fmt($tax->getBase()), '', '', $tax->getTaxCode(), $fmt($tax->getTax())], 'muted' => true];
}
if (!$document->getRounding()->isZero()) {
    $rows[] = ['cells' => ['Rundung', '', '', '', $fmt($document->getRounding())], 'muted' => true];
}
$rows[] = ['cells' => ['Total ' . $document->getCurrency(), '', '', '', $fmt($document->getGrossTotal())], 'bold' => true, 'rule' => true];

$pdf->partial('pdf/table', [
    'x'       => $left,
    'y'       => $y + 6,
    'columns' => [
        ['label' => 'Bezeichnung', 'width' => 88],
        ['label' => 'Menge',       'width' => 22, 'align' => 'R'],
        ['label' => 'Preis',       'width' => 22, 'align' => 'R'],
        ['label' => 'MWST',        'width' => 14, 'align' => 'R'],
        ['label' => $document->getCurrency(), 'width' => 24, 'align' => 'R'],
    ],
    'rows'    => $rows,
], 'Z77\\Shared');

// ── terms ──────────────────────────────────────────────────────────────
$y = $pdf->y() + 6;
if (!$isCredit && $document->getTermsText() !== null && trim($document->getTermsText()) !== '') {
    $pdf->ensureSpace(14);
    $y = $pdf->paragraph($left, $pdf->y() + 6, $width, $document->getTermsText(), 4.4);
}
if (!$isCredit && !$bill->isPrintable() && $document->getPayment()->hasPaymentPart() === false) {
    $pdf->ensureSpace(10);
    $pdf->font('', 9)->paragraph($left, $pdf->y() + 4, $width, 'Zahlbar mit Vermerk «' . $name . '».', 4.4);
}

// ── the payment part at the foot of the last page ──────────────────────
if (!$isCredit && $bill->isPrintable()) {
    $top = $pdf->pageHeight() - 105;
    if ($pdf->y() + 4 > $top) {
        $pdf->addPage();
    }
    $pdf->set('paymentPart', true);
    $pdf->partial('pdf/qrBill', ['bill' => $bill, 'document' => $document, 'top' => $top], 'Z77\\Module\\Debtor');
}
