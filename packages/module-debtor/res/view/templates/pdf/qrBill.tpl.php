<?php
/**
 * PDF partial: the PAYMENT PART of a Swiss QR-bill (SIX «Swiss
 * Implementation Guidelines QR-bill», style guide v2.x) — the 210 × 105 mm
 * zone at the foot of the page: the RECEIPT (Empfangsschein, 62 mm) on the
 * left, the PAYMENT PART (Zahlteil, 148 mm) on the right, a perforation
 * line above and between them with the separation hint the guidelines ask
 * for on plain paper. The QR code is 46 × 46 mm with the Swiss cross, drawn
 * as vectors by the facade; Helvetica in the prescribed sizes (titles
 * 11 pt bold; receipt headings 6 pt bold / values 8 pt; payment-part
 * headings 8 pt bold / values 10 pt; «Zusätzliche Informationen» 10 pt /
 * 7 pt for a long message). Everything printed comes from the SNAPSHOT
 * through `QrBill` — the account, the reference, the creditor block, the
 * debtor block (left out when it does not fit, DEBTOR-QR-DEBTOR-001: the
 * payer's box is then drawn empty, corner marks, as the guidelines
 * prescribe). Draws through `$pdf` only — echoes nothing.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Debtor\Invoicing\QrBill $bill  printable
 * @var \Z77\Module\Debtor\Entities\Invoice $document
 * @var float $top  the y of the zone's upper edge (page height − 105)
 */
use Z77\Shared\Money\AmountFormat;

$top      = $top ?? ($pdf->pageHeight() - 105);
$payment  = $document->getPayment();
$amount   = str_replace("'", ' ', AmountFormat::of($document->getGrossTotal()));   // «1 234.50» — the guidelines group with a space
$currency = $document->getCurrency();
$blockOf  = static fn(array $b): array => array_values(array_filter([
    $b['name'] ?? '',
    trim(($b['street'] ?? '') . ' ' . ($b['house_no'] ?? '')),
    trim(($b['zip'] ?? '') . ' ' . ($b['city'] ?? '')),
], static fn(string $l): bool => trim($l) !== ''));
$creditor = $blockOf($bill->creditor());
$debtor   = $bill->debtor() !== null ? $blockOf($bill->debtor()) : null;
$hasRef   = $payment->getReferenceType() === 'QRR';
$message  = trim($payment->getMessage());

/** An empty payer box with corner marks (guidelines: 52 × 20 mm on the receipt, 65 × 25 mm on the payment part). */
$cornerBox = static function (float $x, float $y, float $w, float $h) use ($pdf): void {
    $m = 3.0;
    $pdf->drawColor(0)->lineWidth(0.25);
    foreach ([[$x, $y, 1, 1], [$x + $w, $y, -1, 1], [$x, $y + $h, 1, -1], [$x + $w, $y + $h, -1, -1]] as [$cx, $cy, $dx, $dy]) {
        $pdf->line($cx, $cy, $cx + $dx * $m, $cy)->line($cx, $cy, $cx, $cy + $dy * $m);
    }
};

// ── perforation and the separation hint ────────────────────────────────
$pdf->drawColor(0)->lineWidth(0.2)->dashedLine(0, $top, 210, $top)->dashedLine(62, $top, 62, $top + 105);
$pdf->font('', 7)->textColor(0)->text(0, $top - 4, 'Vor der Einzahlung abzutrennen', 'C', 210);

// ── receipt (x 0–62) ───────────────────────────────────────────────────
$rx = 5.0;
$pdf->font('B', 11)->text($rx, $top + 5, 'Empfangsschein');
$y = $top + 12;
$pdf->font('B', 6)->text($rx, $y, 'Konto / Zahlbar an');
$pdf->font('', 8);
$y = $pdf->lines($rx, $y + 2.8, array_merge([$bill->formattedAccount()], $creditor), 3.3) + 1.2;
if ($hasRef) {
    $pdf->font('B', 6)->text($rx, $y, 'Referenz');
    $pdf->font('', 8)->text($rx, $y + 2.8, $bill->formattedReference());
    $y += 2.8 + 3.3 + 1.2;
}
$pdf->font('B', 6)->text($rx, $y, $debtor !== null ? 'Zahlbar durch' : 'Zahlbar durch (Name/Adresse)');
if ($debtor !== null) {
    $pdf->font('', 8)->lines($rx, $y + 2.8, $debtor, 3.3);
} else {
    $cornerBox($rx, $y + 3, 52, 20);
}
$pdf->font('B', 6)->text($rx, $top + 68, 'Währung')->text($rx + 15, $top + 68, 'Betrag');
$pdf->font('', 8)->text($rx, $top + 71, $currency)->text($rx + 15, $top + 71, $amount);
$pdf->font('B', 6)->text($rx, $top + 82, 'Annahmestelle', 'R', 52);

// ── payment part (x 62–210) ────────────────────────────────────────────
$px = 67.0;                                   // 5 mm inside the part
$pdf->font('B', 11)->text($px, $top + 5, 'Zahlteil');
$pdf->qrCode($bill->payload(), $px, $top + 17, 46, 'M', true);
$pdf->font('B', 8)->text($px, $top + 68, 'Währung')->text($px + 20, $top + 68, 'Betrag');
$pdf->font('', 10)->text($px, $top + 72, $currency)->text($px + 20, $top + 72, $amount);

$ix = 118.0;                                  // the information section
$y  = $top + 5;
$pdf->font('B', 8)->text($ix, $y, 'Konto / Zahlbar an');
$pdf->font('', 10);
$y = $pdf->lines($ix, $y + 3.5, array_merge([$bill->formattedAccount()], $creditor), 3.9) + 1.5;
if ($hasRef) {
    $pdf->font('B', 8)->text($ix, $y, 'Referenz');
    $pdf->font('', 10)->text($ix, $y + 3.5, $bill->formattedReference());
    $y += 3.5 + 3.9 + 1.5;
}
if ($message !== '') {
    $pdf->font('B', 8)->text($ix, $y, 'Zusätzliche Informationen');
    $pdf->font('', 10);
    $y = $pdf->paragraph($ix, $y + 3.5, 87, $message, 3.9) + 1.5;
}
$pdf->font('B', 8)->text($ix, $y, $debtor !== null ? 'Zahlbar durch' : 'Zahlbar durch (Name/Adresse)');
if ($debtor !== null) {
    $pdf->font('', 10)->lines($ix, $y + 3.5, $debtor, 3.9);
} else {
    $cornerBox($ix, $y + 4, 65, 25);
}
$pdf->textColor(0)->font('', 10);
