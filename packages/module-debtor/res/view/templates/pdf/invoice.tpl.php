<?php
/**
 * PDF layout: an invoice or a credit note (P3 part 3) — A4 portrait, from
 * the document's SNAPSHOT only. The page: the mandator's letterhead
 * (module-mandator `pdf/letterhead`), the recipient in the envelope window
 * (kernel `pdf/addressWindow`), the title and the document facts, the lines
 * as a flowing table (kernel `pdf/table`; a priced line's first text line
 * bold, `lead`; continued on further pages with
 * a running header), the tax summary and the totals, the terms sentence —
 * and, on an invoice with a printable QR-bill, the PAYMENT PART at the foot
 * of the last page (`pdf/qrBill`, the 105 mm zone the guidelines reserve;
 * nothing else is printed there, the footer included).
 *
 * A DRAFT (`invoicing`, no number yet) prints as a PREVIEW (owner
 * 2026-10-10): every page carries a large light diagonal «VORSCHAU» behind
 * the content and a red line at the top, the title has no number, and instead
 * of the payment part a short note says it follows with the booking print.
 * Only a booked document prints its number and its QR-bill.
 *
 * Drawn alone (`InvoicePdf::render()`) or as one SECTION of a combined file
 * (`InvoicePdf::combined()`, the booking print of a batch) — so it starts with
 * its own `addPage()`, resets its bag entry and numbers its pages per
 * document (`sectionPageNo()` / `sectionPageCount()`).
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
$isCredit = $document->isCreditNote();
$preview  = !$document->isFinal();   // a draft: no number, no payment part (owner 2026-10-10)
$name     = $preview ? $document->kind()->label() . ' — Vorschau' : $document->documentName();

// ── running header (pages 2+) and footer (not on the page with the payment part) ──
// The pages are counted per DOCUMENT (sectionPageNo / sectionPageCount): in the combined PDF of a
// batch (InvoicePdf::combined()) each document numbers its own pages; alone it is pageNo() / {nb}.
$pdf->set('paymentPart', false);
$pdf->onPageStart(static function ($pdf) use ($name, $left, $right, $width, $preview): void {
    if ($preview) {
        // Drawn FIRST on every page (the page-start hook runs before any content), so the content
        // lies on top of the light watermark — diagonal across the page centre, 45° bottom-left to
        // top-right (owner 2026-10-13).
        $pdf->font('B', 110)->textColor(228)->rotatedText($pdf->pageWidth() / 2, $pdf->pageHeight() / 2, 'VORSCHAU', 45)->textColor(0);
        $pdf->font('B', 8)->textColor(180, 0, 0)->text($left, 6, 'VORSCHAU — kein gültiges Dokument: ohne Belegnummer und ohne Zahlteil. Beides kommt mit dem Buchungsdruck.', 'C', $width)->textColor(0);
    }
    if ($pdf->sectionPageNo() > 1) {
        $pdf->font('', 8)->textColor(110)->text($left, 12, $name . '  ·  Seite ' . $pdf->sectionPageNo() . ' von ' . $pdf->sectionPageCount())->textColor(0);
        $pdf->setY(24);
    }
});
$pdf->onPageEnd(static function ($pdf) use ($name, $left, $width): void {
    if ($pdf->get('paymentPart') === true) {
        return;
    }
    $pdf->font('', 7.5)->textColor(110)->text($left, 284, $name . '  ·  Seite ' . $pdf->sectionPageNo() . ' von ' . $pdf->sectionPageCount(), 'R', $width)->textColor(0);
});

$pdf->autoPageBreak(30)->addPage();

// ── letterhead and recipient ───────────────────────────────────────────
$pdf->partial('pdf/letterhead', ['mandator' => $mandator, 'x' => $left, 'y' => 12], 'Z77\\Module\\Mandator');
$pdf->partial('pdf/addressWindow', ['lines' => $document->getAddress()->lines(), 'x' => $left, 'y' => 50], 'Z77\\Shared');

// ── title and facts ────────────────────────────────────────────────────
$place = $mandator !== null && trim($mandator->getCity()) !== '' ? $mandator->getCity() . ', ' : '';
$pdf->font('', 9)->text($left, 92, $place . $document->getInvoiceDate()->format('d.m.Y'));
$pdf->font('B', 14)->text($left, 100, $name);
$y = 108;
if ($document->getSubject() !== null) {
    // The optional «Betreff» (P7 slim, 2026-10-14) directly under the title; the facts move down.
    $y = $pdf->font('B', 10)->paragraph($left, 106.5, $width, $document->getSubject(), 4.6) + 1.5;
}

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
foreach ($facts as [$label, $value]) {
    $pdf->font('', 9)->textColor(110)->text($left, $y, $label)->textColor(0)->text($left + 32, $y, $value);
    $y += 4.4;
}

// ── the lines, then the summary rows in the same table ─────────────────
$rows = [];
foreach ($document->getLines() as $line) {
    // The rounding line prints ONCE, in the summary below (owner's booking print 2026-10-19: it
    // stood twice — bold among the positions and again above the total).
    if ($line->type()->value === 'rounding') {
        continue;
    }
    $isText = $line->type()->value === 'text';
    $text   = $line->getText();
    if ($line->getDiscountPercent() > 0) {
        $text .= ' (Rabatt ' . TaxRate::formatPercent($line->getDiscountPercent()) . ' %)';
    }
    $rows[] = [
        'cells'  => [
            $text,
            // Quantity and unit in two columns (owner 2026-10-19): the numbers right-aligned under each
            // other, the unit left beside them — one right-aligned «2 h» stood off «1 Stk.».
            $line->getQuantity() !== null ? rtrim(rtrim($line->getQuantity(), '0'), '.') : '',
            $line->getQuantity() !== null ? (string) $line->getUnit() : '',
            $line->getUnitPrice() !== null ? $fmt($line->getUnitPrice()) : '',
            $line->getTaxCode() !== null ? TaxRate::formatPercent((int) $line->getTaxRate()) . ' %' : '',
            $isText ? '' : $fmt($line->getAmount()),
        ],
        'indent' => $line->getParentLine() !== null ? 4 : 0,
        'lead'   => !$isText,   // a priced line: its first line (the title) bold, the description below (owner 2026-10-18)
    ];
}
$rows[] = ['cells' => ['Netto', '', '', '', '', $fmt($document->getNetTotal())], 'rule' => true];
foreach ($document->getTaxes() as $tax) {
    $rows[] = ['cells' => ['MWST ' . TaxRate::formatPercent($tax->getTaxRate()) . ' % auf ' . $fmt($tax->getBase()), '', '', '', $tax->getTaxCode(), $fmt($tax->getTax())], 'muted' => true];
}
if (!$document->getRounding()->isZero()) {
    $rows[] = ['cells' => ['Rundung', '', '', '', '', $fmt($document->getRounding())], 'muted' => true];
}
$rows[] = ['cells' => ['Total ' . $document->getCurrency(), '', '', '', '', $fmt($document->getGrossTotal())], 'bold' => true, 'rule' => true];

$pdf->partial('pdf/table', [
    'x'       => $left,
    'y'       => $y + 6,
    'columns' => [
        ['label' => 'Bezeichnung', 'width' => 88],
        ['label' => 'Menge',       'width' => 12, 'align' => 'R'],
        ['label' => 'Einheit',     'width' => 10],
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
if ($preview && !$isCredit) {
    // The preview has no payment part: the reference and the message come with the number.
    $pdf->ensureSpace(10);
    $pdf->font('I', 9)->textColor(110)->paragraph($left, $pdf->y() + 4, $width, $document->getPayment()->hasPaymentPart()
        ? 'Zahlteil folgt mit dem Buchungsdruck.'
        : 'Ohne Zahlteil — Zahlungsvermerk folgt mit der Belegnummer beim Buchungsdruck.', 4.4);
    $pdf->textColor(0);
} elseif (!$isCredit && !$bill->isPrintable() && $document->getPayment()->hasPaymentPart() === false) {
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
