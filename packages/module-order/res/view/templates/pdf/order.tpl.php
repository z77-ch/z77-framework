<?php
/**
 * PDF layout: the quote («Offerte») or the order confirmation («Auftragsbestätigung») of an order
 * (plan §10, step 8) — A4 portrait. One layout for both: the title comes from the controller,
 * chosen by the PHASE — `new` is a quote (`OrderPdf::orderTitle()`). The page: the mandator's
 * letterhead (module-mandator `pdf/letterhead`), the recipient — the invoice address debtor's
 * preview resolved — in the envelope window (kernel `pdf/addressWindow`), the title, the subject
 * in bold under it, the facts, the lines as a flowing table (kernel `pdf/table`) with the article
 * number in front of the text (a priced line's first text line bold, the description below —
 * the table's `lead`), the tax summary and the totals. NO payment part — the invoice
 * carries it at the booking print.
 *
 * Every amount is the PREVIEW's (`OrderService::preview()` → debtor's `InvoicingService::preview()`,
 * D11): the order computes nothing itself, and what the quote shows is what the booking print
 * will invoice (at the same service date). The rounding line of the preview is shown once, as
 * the «Rundung» row of the totals.
 *
 * A project that wants another look overrides THIS file under `override/`. Draws through `$pdf`
 * only — echoes nothing.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Order\Entities\SalesOrder $order
 * @var string $title  «Offerte 12» / «Auftragsbestätigung 12»
 * @var \Z77\Module\Debtor\Entities\Invoice $preview  the transient invoice of the order, lines loaded
 * @var array<string, string> $articleNumbers  order line id → article number (article lines only)
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 * @var int $customerNumber  0 = none yet (a prospect — D9)
 * @var \DateTimeImmutable $today
 * @var callable $fmt  Money → «1'234.50»
 */
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Vat\Entities\TaxRate;

$left  = 20.0;
$right = 190.0;
$width = $right - $left;

$pdf->onPageStart(static function ($pdf) use ($title, $left): void {
    if ($pdf->pageNo() > 1) {
        $pdf->font('', 8)->textColor(110)->text($left, 12, $title . '  ·  Seite ' . $pdf->pageNo() . ' von {nb}')->textColor(0);
        $pdf->setY(24);
    }
});
$pdf->onPageEnd(static function ($pdf) use ($title, $left, $width): void {
    $pdf->font('', 7.5)->textColor(110)->text($left, 284, $title . '  ·  Seite ' . $pdf->pageNo() . ' von {nb}', 'R', $width)->textColor(0);
});

$pdf->autoPageBreak(25)->addPage();

// ── letterhead and recipient ───────────────────────────────────────────
$pdf->partial('pdf/letterhead', ['mandator' => $mandator, 'x' => $left, 'y' => 12], 'Z77\\Module\\Mandator');
$pdf->partial('pdf/addressWindow', ['lines' => $preview->getAddress()->lines(), 'x' => $left, 'y' => 50], 'Z77\\Shared');

// ── title, subject and facts ───────────────────────────────────────────
$place = $mandator !== null && trim($mandator->getCity()) !== '' ? $mandator->getCity() . ', ' : '';
$pdf->font('', 9)->text($left, 92, $place . $today->format('d.m.Y'));
$pdf->font('B', 14)->text($left, 100, $title);
$y = 108;
if (trim($order->getSubject()) !== '') {
    $y = $pdf->font('B', 10)->paragraph($left, 106.5, $width, $order->getSubject(), 4.6) + 1.5;
}
$period = ($order->getServiceFrom() ?? $order->getOrderDate())->format('d.m.Y') . ($order->getServiceTo() !== null ? ' – ' . $order->getServiceTo()->format('d.m.Y') : '');
$facts  = [];
if ($customerNumber > 0) {
    $facts[] = ['Kundennummer', (string) $customerNumber];
}
$facts[] = ['Auftragsdatum', $order->getOrderDate()->format('d.m.Y')];
$facts[] = ['Leistung', $period];   // one word for goods and services (owner 2026-10-16: a project may word it its own way)
$facts[] = ['Preise', $order->priceMode()->value === 'gross' ? 'inkl. MWST' : 'zzgl. MWST'];
foreach ($facts as [$label, $value]) {
    $pdf->font('', 9)->textColor(110)->text($left, $y, $label)->textColor(0)->text($left + 32, $y, $value);
    $y += 4.4;
}

// ── the lines, then the summary rows in the same table ─────────────────
$rows = [];
foreach ($preview->getLines() as $line) {
    if ($line->type() === LineType::Rounding) {
        continue;   // shown once, as the «Rundung» row below
    }
    $isText = $line->type() === LineType::Text;
    $number = $articleNumbers[(string) $line->getSourceRef()] ?? '';
    $text   = ($number !== '' ? $number . '  ' : '') . $line->getText();
    $rows[] = [
        'cells'  => [
            $text,
            // Quantity and unit in two columns (owner 2026-10-19): the numbers right-aligned under each
            // other, the unit left beside them — one right-aligned «2 h» stood off «1 Stk.».
            $line->getQuantity() !== null ? rtrim(rtrim($line->getQuantity(), '0'), '.') : '',
            $line->getQuantity() !== null ? (string) $line->getUnit() : '',
            $line->getUnitPrice() !== null ? $fmt($line->getUnitPrice()) : '',
            $line->getDiscountPercent() > 0 ? TaxRate::formatPercent($line->getDiscountPercent()) . ' %' : '',
            $line->getTaxCode() !== null ? TaxRate::formatPercent((int) $line->getTaxRate()) . ' %' : '',
            $isText ? '' : $fmt($line->getAmount()),
        ],
        'indent' => $line->getParentLine() !== null ? 4 : 0,
        'lead'   => true,   // the first line (the title) bold, the description below — text lines too (owner 2026-10-18/19)
    ];
}
$rows[] = ['cells' => ['Netto', '', '', '', '', '', $fmt($preview->getNetTotal())], 'rule' => true];
foreach ($preview->getTaxes() as $tax) {
    $rows[] = ['cells' => ['MWST ' . TaxRate::formatPercent($tax->getTaxRate()) . ' % auf ' . $fmt($tax->getBase()), '', '', '', '', $tax->getTaxCode(), $fmt($tax->getTax())], 'muted' => true];
}
if (!$preview->getRounding()->isZero()) {
    $rows[] = ['cells' => ['Rundung', '', '', '', '', '', $fmt($preview->getRounding())], 'muted' => true];
}
$rows[] = ['cells' => ['Total ' . $preview->getCurrency(), '', '', '', '', '', $fmt($preview->getGrossTotal())], 'bold' => true, 'rule' => true];

// The line table of debtor's documents — the same columns, the discount column only when used.
[$columns, $rows] = \Z77\Module\Debtor\Pdf\DocumentColumns::table($preview->getCurrency(), $rows);
$pdf->partial('pdf/table', [
    'x'       => $left,
    'y'       => $y + 6,
    'columns' => $columns,
    'rows'    => $rows,
], 'Z77\\Shared');
