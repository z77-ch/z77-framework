<?php
/**
 * PDF layout: the delivery note («Lieferschein») of an order (plan §10, D6, step 8) — A4
 * portrait, NO prices. The page: the mandator's letterhead (module-mandator `pdf/letterhead`),
 * the delivery address — the order's own snapshot, else the customer's invoice address — in the
 * envelope window (kernel `pdf/addressWindow`), the title, the subject, the facts, and the lines
 * as a flowing table (kernel `pdf/table`): article number, text, quantity, unit. A text line
 * prints its text; a priced line its first text line bold (`lead`); a child line is indented under its parent. Ends with a line for the
 * recipient's signature.
 *
 * Printing it is the POST of `OrderService::printDeliveryNote()` (the status moves to the
 * `deliveryNoteTarget` status when that lies ahead) — this layout only draws. A project that
 * wants another look overrides THIS file under `override/`. Draws through `$pdf` only.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Order\Entities\SalesOrder $order  lines loaded
 * @var string $title  «Lieferschein 12»
 * @var \Z77\Module\Debtor\Entities\AddressSnapshot|null $address  null = the customer has no address
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 * @var int $customerNumber  0 = none
 * @var \DateTimeImmutable $today
 */
use Z77\Module\Debtor\Entities\LineType;

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

$pdf->partial('pdf/letterhead', ['mandator' => $mandator, 'x' => $left, 'y' => 12], 'Z77\\Module\\Mandator');
$pdf->partial('pdf/addressWindow', ['lines' => $address?->lines() ?? [$order->getContact()->displayName()], 'x' => $left, 'y' => 50], 'Z77\\Shared');

$place = $mandator !== null && trim($mandator->getCity()) !== '' ? $mandator->getCity() . ', ' : '';
$pdf->font('', 9)->text($left, 92, $place . $today->format('d.m.Y'));
$pdf->font('B', 14)->text($left, 100, $title);
$y = 108;
if ($order->subjectLine() !== null) {
    // «Betreffname: Betreff» on one line (owner 2026-10-21, debtor's SubjectLabel::line()); no label = the subject alone.
    $y = $pdf->font('B', 10)->paragraph($left, 106.5, $width, $order->subjectLine(), 4.6) + 1.5;
}
$facts = [];
if ($customerNumber > 0) {
    $facts[] = ['Kundennummer', (string) $customerNumber];
}
$facts[] = ['Auftrag', $order->documentNumber() . ' vom ' . $order->getOrderDate()->format('d.m.Y')];
$facts[] = ['Lieferdatum', ($order->getServiceFrom() ?? $today)->format('d.m.Y')];   // the service date — the note fills it when empty (owner 2026-10-16)
foreach ($facts as [$label, $value]) {
    $pdf->font('', 9)->textColor(110)->text($left, $y, $label)->textColor(0)->text($left + 32, $y, $value);
    $y += 4.4;
}

$rows = [];
foreach ($order->getLines() as $line) {
    $isText   = $line->type() === LineType::Text;
    $quantity = $line->type() === LineType::LumpSum ? '1' : (string) $line->getQuantity();
    $rows[] = [
        'cells'  => [
            $line->getText(),
            $line->getVariant() !== null ? (string) $line->getArticleNumber() : '',
            $isText ? '' : rtrim(rtrim($quantity, '0'), '.'),
            $isText ? '' : (string) $line->getUnit(),
        ],
        'indent' => $line->getParentLine() !== null ? 4 : 0,
        'lead'   => true,   // the first line (the title) bold, the description below — text lines too (owner 2026-10-18/19)
        // The line code (owner 2026-10-22) — OFFERED to an override (of this layout or of `pdf/table`),
        // never drawn here: whether it prints is the designer's decision. Null = none.
        'code'   => $line->getCode(),
    ];
}

$pdf->partial('pdf/table', [
    'x'       => $left,
    'y'       => $y + 6,
    'columns' => [
        ['label' => 'Bezeichnung', 'width' => 104],
        ['label' => 'Artikel',     'width' => 28],
        ['label' => 'Menge',       'width' => 20, 'align' => 'R'],
        ['label' => 'Einheit',     'width' => 18],
    ],
    'rows'    => $rows,
], 'Z77\\Shared');

// ── the recipient's confirmation ───────────────────────────────────────
$pdf->ensureSpace(24);
$y = $pdf->y() + 16;
$pdf->line($left, $y, $left + 70, $y)->line($left + 90, $y, $right, $y);
$pdf->font('', 8)->textColor(110)->text($left, $y + 2, 'Ort, Datum')->text($left + 90, $y + 2, 'Ware erhalten (Unterschrift)')->textColor(0);
$pdf->setY($y + 8);
