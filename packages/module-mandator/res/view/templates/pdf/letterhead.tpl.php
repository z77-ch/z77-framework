<?php
/**
 * PDF partial: the mandator as the LETTERHEAD of a printed document — the
 * company block top-left (name in bold, the address lines, the UID — with
 * « MWST» while liable — then phone, e-mail and website in one small line) and the logo top-right when one is
 * stored (the print variant of the logo window — resolved
 * HERE through `Logo\LogoFiles`, the one consumer that prints it, mandator.md). The PDF counterpart of
 * `partials/letterhead` (the HTML block of the report header); unlike it,
 * this one carries the contact line and the logo: a letter needs them, a
 * report does not.
 *
 * No record saved → nothing drawn (an invented company on an invoice is
 * worse than none); the cursor still moves below the header zone. Draws
 * through `$pdf` only — echoes nothing.
 *
 * Context:
 *   - `mandator`   \Z77\Module\Mandator\Entities\Mandator|null
 *   - `x`, `y`     top-left of the block (default 20 / 12)
 *   - `logoWidth`  mm (default 40); the logo keeps its ratio, right-aligned to the text margin
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 */
$x         = $x ?? 20.0;
$y         = $y ?? 12.0;
$logoWidth = $logoWidth ?? 40.0;
$m         = $mandator ?? null;

if ($m === null) {
    $pdf->setY($y + 24);
    return;
}

$lines = array_values(array_filter(
    [
        $m->getAddressSuffixOne(),
        $m->getAddressSuffixTwo(),
        trim($m->getStreet() . ' ' . $m->getHouseNo()),
        trim($m->getZip() . ' ' . $m->getCity()),
    ],
    static fn(string $line): bool => trim($line) !== ''
));
// The UID after zip + city, by the HTML partial's rule: only when stored, « MWST» appended only while
// liable to VAT (never stored). An invoice of a liable supplier must carry it (Art. 26 para. 2 lit. a
// MWSTG) — missing until 2026-11-24 (MANDATOR-PDF-UID-001). Height: with both suffixes and the UID
// the block ends near 42 mm, the envelope window starts at 50 mm (`pdf/addressWindow`).
$uid = trim($m->getUid());
if ($uid !== '') {
    $lines[] = $uid . ($m->isLiableToVat() ? ' MWST' : '');
}
$contact = implode('  ·  ', array_filter([$m->getPhone(), $m->getEmail(), $m->getWebsite()], static fn(string $v): bool => trim($v) !== ''));

$pdf->textColor(0)->font('B', 11)->text($x, $y, $m->getName());
$pdf->font('', 9);
$next = $pdf->lines($x, $y + 5.2, $lines, 4.2);
if ($contact !== '') {
    $pdf->font('', 8)->textColor(90)->text($x, $next + 0.5, $contact)->textColor(0);
    $next += 4.5;
}

// The print logo: the variant the logo window stored in the DMS (Logo\LogoFiles, 2026-11-20; the old
// `logo_path` is no longer read). A file the PDF writer refuses prints the letterhead without it.
$logo = \Z77\Module\Mandator\Logo\LogoFiles::of($m, \Z77\Module\Mandator\Logo\LogoUses::PRINT);
if ($logo !== null) {
    try {
        $pdf->image($logo['path'], $pdf->pageWidth() - $x - $logoWidth, $y, $logoWidth, 0);
    } catch (\Throwable $e) {
        error_log('mandator logo: not printed (' . basename($logo['path']) . '): ' . $e->getMessage());
    }
}

$pdf->setY(max($next, $y + 24));
