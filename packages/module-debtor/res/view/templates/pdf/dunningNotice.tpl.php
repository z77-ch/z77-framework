<?php
/**
 * PDF layout: a dunning notice (P4 part 3) — A4 portrait: the mandator's
 * letterhead, the recipient in the window (the invoice's address
 * snapshot), the level's title («1. Mahnung»), the notice date, the
 * customer number, the level's text in the document's language, the
 * table: the invoice (number, date, due date, open amount), the fee issued
 * with the notice, the total — and the PAYMENT PART over the total under
 * the invoice's reference at the foot (`pdf/qrBill`), when the bill is
 * printable. A project that wants another look overrides THIS file under
 * `override/`. Draws through `$pdf` only — echoes nothing.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Debtor\Entities\DunningNotice $notice
 * @var \Z77\Module\Debtor\Entities\Invoice $invoice
 * @var \Z77\Module\Debtor\Entities\Invoice|null $fee
 * @var string $title
 * @var string $text
 * @var \Z77\Shared\Money\Money $total
 * @var \Z77\Module\Debtor\Invoicing\QrBill $bill
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 * @var int $customerNumber
 * @var callable $fmt
 */
$left  = 20.0;
$right = 190.0;
$width = $right - $left;
$name  = $title . ' · ' . $invoice->documentName();

$pdf->onPageStart(static function ($pdf) use ($name, $left): void {
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
$pdf->partial('pdf/letterhead', ['mandator' => $mandator, 'x' => $left, 'y' => 12], 'Z77\\Module\\Mandator');
$pdf->partial('pdf/addressWindow', ['lines' => $invoice->getAddress()->lines(), 'x' => $left, 'y' => 50], 'Z77\\Shared');

$place = $mandator !== null && trim($mandator->getCity()) !== '' ? $mandator->getCity() . ', ' : '';
$pdf->font('', 9)->text($left, 92, $place . $notice->getRun()->getRunDate()->format('d.m.Y'));
$pdf->font('B', 14)->text($left, 100, $title);
$y = 108;
foreach (array_filter([
    ['Kundennummer', $customerNumber > 0 ? (string) $customerNumber : ''],
    ['Betrifft', $invoice->documentName() . ' vom ' . $invoice->getInvoiceDate()->format('d.m.Y') . ', fällig am ' . $invoice->getDueDate()->format('d.m.Y')],
], static fn(array $f) => $f[1] !== '') as [$label, $value]) {
    $pdf->font('', 9)->textColor(110)->text($left, $y, $label)->textColor(0)->text($left + 32, $y, $value);
    $y += 4.4;
}

if (trim($text) !== '') {
    $y = $pdf->font('', 10)->paragraph($left, $y + 4, $width, $text, 4.6) + 2;
}

$rows = [[
    'cells' => [$invoice->documentName() . ' vom ' . $invoice->getInvoiceDate()->format('d.m.Y') . ', fällig ' . $invoice->getDueDate()->format('d.m.Y'), $fmt($invoice->getGrossTotal()), $fmt($notice->getOpenAmount())],
]];
if ($fee !== null) {
    $rows[] = ['cells' => [$fee->documentName() . ': ' . ($fee->getLines()[0] ?? null)?->getText(), $fmt($fee->getGrossTotal()), $fmt($fee->getGrossTotal())]];
}
$rows[] = ['cells' => ['Total zu zahlen ' . $invoice->getCurrency(), '', $fmt($total)], 'bold' => true, 'rule' => true];
$pdf->partial('pdf/table', [
    'x'       => $left,
    'y'       => $y + 4,
    'columns' => [
        ['label' => 'Beleg',  'width' => 110],
        ['label' => 'Betrag', 'width' => 30, 'align' => 'R'],
        ['label' => 'Offen',  'width' => 30, 'align' => 'R'],
    ],
    'rows'    => $rows,
], 'Z77\\Shared');

if ($bill->isPrintable()) {
    $top = $pdf->pageHeight() - 105;
    if ($pdf->y() + 4 > $top) {
        $pdf->addPage();
    }
    $pdf->set('paymentPart', true);
    $pdf->partial('pdf/qrBill', ['bill' => $bill, 'document' => $invoice, 'top' => $top, 'amount' => $total], 'Z77\\Module\\Debtor');
}
