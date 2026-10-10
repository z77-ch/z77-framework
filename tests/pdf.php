<?php

/**
 * PDF facade harness (CLI) — `Z77\Shared\Pdf\PdfDocument` over the vendored
 * FPDF (kernel vendored/README.md), and the kernel's PDF partials.
 *
 * What is load-bearing here:
 *
 *   - the facade produces a PDF (header, page count, uncompressed text
 *     readable in the stream for the harness);
 *   - UTF-8 in, cp1252 out: umlauts survive, the Unicode minus of
 *     `AmountFormat` becomes a hyphen, a character cp1252 lacks is
 *     transliterated, never dropped and never a fatal;
 *   - a placed line (`text()`) NEVER breaks the page, a flowed block
 *     (`paragraph()`) does at the automatic margin, `ensureSpace()` breaks
 *     ahead of a block that must not split;
 *   - the QR code is drawn as vector rectangles from the facade's matrix
 *     (no image object), the Swiss cross on request;
 *   - a PDF partial draws and echoes nothing — stray output is refused;
 *   - the page hooks run per page and read the layout's bag;
 *   - the kernel's `pdf/table` flows over pages, repeats its header, leaves
 *     the cursor below; `pdf/addressWindow` places the lines in the window.
 *
 * Run: php tests/pdf.php
 * Needs the Composer autoloader (FPDF classmap, the kernel facade) and a
 * FileFinder for the partials — wired like the module harnesses, against
 * the real kernel package.
 */

require __DIR__ . '/../vendor/autoload.php';

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Shared\Pdf\PdfDocument;
use Z77\Shared\Qr\QrCode;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
}
function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}

// ── a throwaway application root so the FileFinder resolves the kernel's partials ──
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-pdf-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);
$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static fn() => $rm($base));

$kernel = str_replace('\\', '/', dirname(__DIR__)) . '/packages/kernel';
// The FileFinder config the installer generates (ADR-036 split layout): the kernel's shared
// namespace and a harness namespace with its own templates (written in section E).
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$kernel}/shared']],\n'Harness\\\\' => ['sourcePaths' => ['{$base}/harness']],\n]];");
$write('var/cache/.keep', '');

$wired = false;
$wire  = static function () use ($base, &$wired): void {
    if ($wired) { return; }
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true);
    DI::getCacheManager()->setCacheDir($base . '/var/cache');
    $wired = true;
};

echo "A. The document: header, pages, text in the stream\n";
$pdf   = PdfDocument::create('Harness', 'z77')->withoutCompression()->addPage();
$pdf->font('B', 14)->text(20, 20, 'Überschrift');
$bytes = $pdf->output();
check('A1 output() is a PDF with one page and the title set', str_starts_with($bytes, '%PDF-') && str_contains($bytes, '/Count 1') && str_contains($bytes, '(Harness)'));
check('A2 UTF-8 in, cp1252 out: the umlaut is the one cp1252 byte, readable in the uncompressed stream', str_contains($bytes, "(\xDCberschrift)"));
check('A3 A4 portrait by default — 210 × 297 mm', abs($pdf->pageWidth() - 210) < 0.01 && abs($pdf->pageHeight() - 297) < 0.01);

echo "B. Encoding: what cp1252 lacks is mapped or transliterated, never dropped, never fatal\n";
$enc = PdfDocument::create('e');
check('B1 the Unicode minus of AmountFormat becomes a hyphen; the en dash and the typographic quotes keep their cp1252 bytes',
    $enc->enc("\u{2212}1'234.50") === "-1'234.50" && $enc->enc('a – b') === "a \x96 b" && $enc->enc('«x»') === "\xABx\xBB");
check('B2 Ș (Romanian, QR-bill v2.3) is transliterated, not dropped; € is the cp1252 byte', in_array($enc->enc('Ștefan'), ['Stefan', '?tefan'], true) && $enc->enc('5 €') === "5 \x80");
check('B3 CJK does not throw and leaves a placeholder', is_string($enc->enc('日本')) && $enc->enc('日本') !== '');

echo "C. Page breaks: a placed line never breaks, a flowed block does, ensureSpace() breaks ahead\n";
$pdf = PdfDocument::create('breaks')->withoutCompression()->autoPageBreak(30)->addPage();
$pdf->font('', 9)->text(20, 290, 'foot');
check('C1 text() at y = 290 with a 30 mm break margin stays on page 1 — a placed line never triggers the writer\'s break', $pdf->pageNo() === 1 && abs($pdf->breakAt() - 267) < 0.01);
$y = $pdf->paragraph(20, 250, 100, str_repeat('Fliesstext der umbricht. ', 40), 4.5);
check('C2 paragraph() flows over the margin onto page 2 and answers the y below the block there', $pdf->pageNo() === 2 && $y > 0 && $y < 100);
$pdf->setY(200)->ensureSpace(80);
check('C3 ensureSpace(80) at y = 200 (break at 267) adds a page; at y = 100 it does not', $pdf->pageNo() === 3 && $pdf->setY(100)->ensureSpace(80)->pageNo() === 3);
$pdf->autoPageBreak(0);
check('C4 autoPageBreak(0) switches the break off: breakAt() is the page height', abs($pdf->breakAt() - 297) < 0.01);

echo "D. The QR code as vectors, the Swiss cross, the matrix of the facade\n";
$matrix = QrCode::matrix('SPC');
check('D1 QrCode::matrix() is a square of booleans with the finder pattern top-left (dark corner, light ring)', count($matrix) === count($matrix[0]) && $matrix[0][0] === true && $matrix[0][6] === true && $matrix[1][1] === false);
$pdf = PdfDocument::create('qr')->withoutCompression()->addPage();
$pdf->qrCode('SPC', 10, 10, 46, 'M', true);
$bytes = $pdf->output();
check('D2 the code is rectangles in the content stream (re … f), no image; the cross adds white fills', substr_count($bytes, ' re f') > 20 && !str_contains($bytes, '/Subtype /Image') && str_contains($bytes, '1.000 1.000 1.000 rg'));
check('D3 an empty payload is refused', throws(fn() => PdfDocument::create('x')->addPage()->qrCode('', 0, 0, 10), \InvalidArgumentException::class));

echo "E. Partials: draw, echo nothing; the hooks and the bag\n";
// A namespace is only known to the FileFinder when its `src/` exists — like a real package.
$write('harness/src/.keep', '');
$write('harness/res/view/templates/draws.tpl.php', '<?php $pdf->text(20, 20, "aus dem Partial: " . $greeting);');
$write('harness/res/view/templates/prints.tpl.php', '<?php echo "oops";');
$wire();
$pdf = PdfDocument::create('partials')->withoutCompression()->addPage()->partial('draws', ['greeting' => 'hallo'], 'Harness');
check('E1 a partial receives $pdf and its context and draws', str_contains($pdf->output(), '(aus dem Partial: hallo)'));
check('E2 a partial that prints is refused (LogicException naming it)', throws(fn() => PdfDocument::create('p')->addPage()->partial('prints', [], 'Harness'), \LogicException::class));
$seen = [];
$pdf  = PdfDocument::create('hooks')->withoutCompression()
    ->onPageStart(function ($pdf) use (&$seen) { $seen[] = 'start' . $pdf->pageNo(); })
    ->onPageEnd(function ($pdf) use (&$seen) { $seen[] = ($pdf->get('quiet') ? 'quiet' : 'end') . $pdf->pageNo(); });
$pdf->addPage()->addPage()->set('quiet', true)->output();
check('E3 the start hook runs per page, the end hook when a page is left and at output; the bag reaches the hooks', $seen === ['start1', 'end1', 'start2', 'quiet2']);

echo "F. The kernel partials: pdf/table flows and repeats its header, pdf/addressWindow places the block\n";
$rows = [];
for ($i = 1; $i <= 60; $i++) {
    $rows[] = ['cells' => ["Zeile {$i}" . ($i % 7 === 0 ? ' mit einem längeren Text, der in der ersten Spalte umbricht und zwei Zeilen braucht' : ''), (string) $i, '1.00'], 'indent' => $i % 5 === 0 ? 4 : 0];
}
$rows[] = ['cells' => ['Total', '', '60.00'], 'bold' => true, 'rule' => true];
$pdf = PdfDocument::create('table')->withoutCompression()->autoPageBreak(25)->addPage();
$pdf->partial('pdf/table', ['x' => 20, 'y' => 30, 'columns' => [['label' => 'Text', 'width' => 100], ['label' => 'Nr', 'width' => 20, 'align' => 'R'], ['label' => 'Betrag', 'width' => 30, 'align' => 'R']], 'rows' => $rows]);
$after = $pdf->y();
$bytes = $pdf->output();
check('F1 sixty rows flow over more than one page, the header row is repeated (one «Betrag» per page), the cursor is left below the last row',
    $pdf->pageNo() >= 2 && substr_count($bytes, '(Betrag)') === $pdf->pageNo() && $after > 30 && $after < $pdf->breakAt());
check('F2 the totals row and the wrapped rows are there', str_contains($bytes, '(Total)') && str_contains($bytes, '(60.00)') && str_contains($bytes, 'umbricht'));
$pdf = PdfDocument::create('window')->withoutCompression()->addPage();
$pdf->partial('pdf/addressWindow', ['lines' => ['Frau', 'Anna Ébauche', '', 'Rue du Lac 1', '1000 Lausanne']]);
check('F3 addressWindow: the lines at the window (y from 50), the empty one skipped, the cursor below the block', str_contains($pdf->output(), "(Anna \xC9bauche)") && abs($pdf->y() - (50 + 4 * 4.6)) < 0.01);


// ── G: the shared report layout (FIN-PDF-001) ─────────────────────────────────────────────
$cols = [['label' => 'Konto', 'width' => 20], ['label' => 'Bezeichnung', 'width' => 130], ['label' => 'Betrag', 'width' => 30, 'align' => 'R']];
$many = [];
for ($i = 0; $i < 90; $i++) {
    $many[] = ['cells' => [(string) (1000 + $i), 'Konto ' . $i, '1.00']];
}
$report = PdfDocument::create('Bilanz')->withoutCompression()->partial('pdf/report', [
    'title'     => 'Bilanz',
    'subtitle'  => 'Geschäftsjahr 2026 · per 31.12.2026',
    'issuer'    => 'Muster AG',
    'printedAt' => '10.10.2026 12:27',
    'notice'    => 'Aktiven ≠ Passiven — Differenz 12.00',
    'blocks'    => [
        ['title' => 'Aktiven', 'columns' => $cols, 'rows' => $many],
        ['columns' => $cols, 'rows' => [['cells' => ['', 'Total Aktiven', '90.00'], 'bold' => true, 'rule' => true]]],
    ],
]);
$bytes = $report->output();
check('G1 report: a PDF, more than one page for 90 rows, the head on every page', str_starts_with($bytes, '%PDF') && $report->pageNo() >= 2 && substr_count($bytes, '(Muster AG)') === $report->pageNo());
check('G2 report: the page count is resolved («Seite 1 von n», no {nb} left), the printed-at line and the notice are there', !str_contains($bytes, '{nb}') && str_contains($bytes, '(Seite 1 von ' . $report->pageNo() . ')') && str_contains($bytes, '(Gedruckt 10.10.2026 12:27)') && str_contains($bytes, 'Differenz 12.00'));
check('G3 report: the block title and the total are drawn', str_contains($bytes, '(Aktiven)') && str_contains($bytes, '(Total Aktiven)'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
