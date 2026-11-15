<?php

/**
 * Creditor recognition harness (CLI, offline, no DI, no database) — stage 2 core of the creditor
 * plan §4: the text layer of a supplier's PDF → the Swiss QR-bill's payment part.
 *
 * What is load-bearing:
 *
 *   (T) tools degrade, never fail (owner answer §11): a missing or wrong pdftotext path is a
 *       German reason, `canReadText()` false, the recogniser answers «unavailable» — no
 *       exception; the probe finds a present pdftotext by running it;
 *   (R) the process runner: an argument array reaches the tool verbatim (no shell, no
 *       injection), the timeout stops a hanging tool, output above the cap is refused, never cut;
 *   (P) `PdfText`: bytes that are no PDF, a broken PDF and a PDF without text layer (a scan)
 *       come back as reasons; its temp files are gone afterwards;
 *   (E) END TO END on real PDFs: the payment part drawn by module-debtor's OWN QR-bill partial
 *       (`pdf/qrBill.tpl.php`, DE, QR-IBAN + QRR) and slips drawn here in FR / IT / EN — SCOR,
 *       NON, blank amount, EUR, Swico S1 wrapped over lines, the slip on page 2 behind an
 *       invoice body with its own «Betrag» column, a receipt with a different IBAN (warning),
 *       «NICHT ZUR ZAHLUNG VERWENDEN», a plain invoice (not found) — rendered with the
 *       kernel's `PdfDocument`, read back by pdftotext (`-layout` and `-raw`). Skipped with a
 *       note where pdftotext is absent;
 *   (X) text fixtures (`tests/fixtures/creditor-recognition/`): poppler-style layout with
 *       shifted columns and apostrophe grouping, two titles run together, a value line run
 *       together with the receipt by a single space, xpdf default mode (address joined onto the
 *       IBAN line), a plain invoice whose body names IBAN, «Betrag» and «Referenz» (→ null);
 *   (A) amounts: «1 234.50», «1'234.50», «1234.50», blank, 0.00, out of range, garbage;
 *   (H) the header above the slip (owner 2026-11-15): labels per language (number, dates, «zahlbar
 *       innert N Tagen»), a bare «Datum» only once, «Kundennummer» never, S1 wins, a disagreeing
 *       total is a warning, the letterhead street only on an exact name + postcode + town, the QR
 *       message as line text, the probing PHP named in a tool reason;
 *   (S) Swico S1: the fields the plan names, the due date from the net term, an invalid UID or
 *       date dropped with a warning, an escaped slash, the S1 string broken over lines.
 *
 * Example data only (SIX sample IBANs, «Muster AG», «Beispiel GmbH») — no customer data.
 *
 * Run: php tests/creditor-recognition.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "creditor-recognition: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Creditor\Recognition\PdfText;
use Z77\Module\Creditor\Recognition\RecognitionTools;
use Z77\Module\Creditor\Recognition\RecognizedPayment;
use Z77\Module\Creditor\Recognition\SwicoBillingInfo;
use Z77\Module\Creditor\Recognition\SwissQrBillText;
use Z77\Module\Creditor\Recognition\TextLayerOutcome;
use Z77\Module\Creditor\Recognition\TextLayerRecognizer;
use Z77\Module\Creditor\Recognition\ToolRunner;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\QrReference;
use Z77\Shared\Payment\ScorReference;
use Z77\Shared\Pdf\PdfDocument;

$pass  = 0;
$fail  = 0;
$notes = [];

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function note(string $text): void
{
    global $notes;
    $notes[] = $text;
    echo "  NOTE {$text}\n";
}

function dump(mixed $value): string
{
    if ($value instanceof RecognizedPayment) {
        $value = get_object_vars($value);
    }
    if (is_array($value)) {
        array_walk_recursive($value, static function (&$v): void {
            $v = $v instanceof Money ? $v->toDecimal() . ' ' . $v->currency : ($v instanceof SwicoBillingInfo ? $v->raw : $v);
        });
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function decimal(?Money $money): ?string
{
    return $money?->toDecimal();
}

/** Temp files this module leaves behind (it must leave none). */
function tempLeftovers(): array
{
    $dir = sys_get_temp_dir();

    return array_merge(glob($dir . '/z77pdf*') ?: [], glob($dir . '/z77to*') ?: [], glob($dir . '/z77te*') ?: []);
}

// Example data (SIX sample IBANs; references built with the kernel's own helpers).
const QR_IBAN    = 'CH44 3199 9123 0008 8901 2';
const IBAN       = 'CH93 0076 2011 6238 5295 7';
const OTHER_IBAN = 'CH56 0483 5012 3456 7800 9';
$qrr  = QrReference::withCheckDigit('21000000000313947143000901');
$scor = 'RF18539007547034';

echo "\n(X0) the example data is valid\n";
check('the QR reference of the examples holds its check digit', QrReference::isValid($qrr) && $qrr === '210000000003139471430009017', $qrr);
check('the RF reference of the examples holds', ScorReference::isValid($scor));

// ─────────────────────────────────────────────────────────────────────────
echo "\n(T) tools degrade, never fail\n";
$missing = RecognitionTools::detect('z77-no-such-pdftotext');
check('T1 a pdftotext that does not exist: canReadText() false, a German reason', !$missing->canReadText() && str_contains((string) $missing->reason, 'pdftotext nicht gefunden'), (string) $missing->reason);
$outcome = null;
$thrown  = null;
try {
    $outcome = (new TextLayerRecognizer($missing))->recognize("%PDF-1.4\n%%EOF\n");
} catch (\Throwable $e) {
    $thrown = $e;
}
check('T2 … the recogniser answers «unavailable» with the reason, no exception', $thrown === null && $outcome?->state === TextLayerOutcome::UNAVAILABLE && $outcome->reason === $missing->reason && $outcome->payment === null, $thrown?->getMessage() ?? dump([$outcome?->state, $outcome?->reason]));
$read = (new PdfText($missing))->read("%PDF-1.4\n%%EOF\n");
check('T3 … PdfText answers the same reason instead of running', !$read->hasText() && $read->reason === $missing->reason, (string) $read->reason);
$empty = RecognitionTools::detect('   ');
check('T4 an empty configured path: a reason, no run', !$empty->canReadText() && str_contains((string) $empty->reason, 'kein gültiger Pfad'), (string) $empty->reason);
$shell = RecognitionTools::detect('pdftotext -v; echo pwned');
check('T5 a configured value with a shell command in it is a binary NAME (not found), never run through a shell', !$shell->canReadText(), (string) $shell->version);

$tools = RecognitionTools::probe();
check('T6 probe() is cached per binary (the same object twice)', RecognitionTools::probe() === $tools);
$haveTool = $tools->canReadText();
if ($haveTool) {
    check('T7 the probe found pdftotext by running it: version and flavour', $tools->version !== null && in_array($tools->flavour, ['poppler', 'xpdf', null], true), dump([$tools->version, $tools->flavour]));
    echo "       pdftotext {$tools->version} ({$tools->flavour})\n";
} else {
    note('pdftotext not found on this host (' . $tools->reason . ') — the PDF checks (P, E) are skipped; the text checks run');
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n(R) the process runner\n";
if (ToolRunner::unavailableReason() !== null) {
    note('proc_open is disabled here — runner checks skipped');
} else {
    $hostile = 'a & echo pwned | x "y" $(z) `w` %PATH%';
    $run     = ToolRunner::run([PHP_BINARY, '-r', 'echo $argv[1];', $hostile], 10, 4096);
    check('R1 an argument with shell metacharacters reaches the tool verbatim (argument array, no shell)', $run->succeeded() && $run->stdout === $hostile, dump($run));
    $t0  = microtime(true);
    $run = ToolRunner::run([PHP_BINARY, '-r', 'sleep(20);'], 0.5, 4096);
    check('R2 the timeout stops a hanging tool (0.5 s, not 20)', $run->timedOut && !$run->succeeded() && microtime(true) - $t0 < 5, dump([$run->timedOut, microtime(true) - $t0]));
    $run = ToolRunner::run([PHP_BINARY, '-r', 'echo str_repeat("x", 200000);'], 10, 1000);
    check('R3 output above the cap is refused (never a cut text)', $run->tooLarge && !$run->succeeded(), dump([$run->tooLarge, strlen($run->stdout)]));
    $run = ToolRunner::run([PHP_BINARY, '-r', 'fwrite(STDERR, "boom"); exit(3);'], 10, 4096);
    check('R4 the exit code and stderr come back', $run->exitCode === 3 && $run->stderr === 'boom', dump($run));
    check('R5 the runner leaves no temp file', tempLeftovers() === [], implode(', ', tempLeftovers()));
}

// ─────────────────────────────────────────────────────────────────────────
// PDFs drawn with the kernel's PdfDocument.

/** A payment part's labels per language (SIX style guide v2.x). */
const LABELS = [
    'de' => ['Empfangsschein', 'Zahlteil', 'Konto / Zahlbar an', 'Referenz', 'Zusätzliche Informationen', 'Zahlbar durch', 'Zahlbar durch (Name/Adresse)', 'Währung', 'Betrag', 'Annahmestelle', 'Vor der Einzahlung abzutrennen'],
    'fr' => ['Récépissé', 'Section paiement', 'Compte / Payable à', 'Référence', 'Informations supplémentaires', 'Payable par', 'Payable par (nom/adresse)', 'Monnaie', 'Montant', 'Point de dépôt', 'A détacher avant le versement'],
    'it' => ['Ricevuta', 'Sezione pagamento', 'Conto / Pagabile a', 'Riferimento', 'Informazioni supplementari', 'Pagabile da', 'Pagabile da (nome/indirizzo)', 'Valuta', 'Importo', 'Punto di accettazione', 'Da staccare prima del versamento'],
    'en' => ['Receipt', 'Payment part', 'Account / Payable to', 'Reference', 'Additional information', 'Payable by', 'Payable by (name/address)', 'Currency', 'Amount', 'Acceptance point', 'Separate before paying in'],
];

/**
 * Draws a payment part at the foot of the current page with the coordinates of module-debtor's
 * `pdf/qrBill.tpl.php` — in any language, with what that partial cannot print (SCOR, a blank
 * amount, a different receipt IBAN, the «not for payment» note).
 *
 * @param array{iban: string, creditor: list<string>, reference?: ?string, message?: string, debtor?: ?list<string>, currency?: string, amount?: string, receiptIban?: string, notForPayment?: bool} $d
 */
function drawSlip(PdfDocument $pdf, string $lang, array $d): void
{
    [$tReceipt, $tPayment, $hAccount, $hRef, $hInfo, $hBy, $hByBlank, $hCur, $hAmt, $hAccept, $hSeparate] = LABELS[$lang];
    $top      = $pdf->pageHeight() - 105;
    $ref      = $d['reference'] ?? null;
    $debtor   = $d['debtor'] ?? null;
    $currency = $d['currency'] ?? 'CHF';
    $amount   = $d['amount'] ?? '';
    $message  = $d['message'] ?? '';
    $pdf->drawColor(0)->lineWidth(0.2)->dashedLine(0, $top, 210, $top)->dashedLine(62, $top, 62, $top + 105);
    $pdf->font('', 7)->textColor(0)->text(0, $top - 4, $hSeparate, 'C', 210);
    // receipt
    $pdf->font('B', 11)->text(5, $top + 5, $tReceipt);
    $y = $top + 12;
    $pdf->font('B', 6)->text(5, $y, $hAccount);
    $pdf->font('', 8);
    $y = $pdf->lines(5, $y + 2.8, array_merge([$d['receiptIban'] ?? $d['iban']], $d['creditor']), 3.3) + 1.2;
    if ($ref !== null) {
        $pdf->font('B', 6)->text(5, $y, $hRef);
        $pdf->font('', 8)->text(5, $y + 2.8, $ref);
        $y += 7.3;
    }
    $pdf->font('B', 6)->text(5, $y, $debtor !== null ? $hBy : $hByBlank);
    if ($debtor !== null) {
        $pdf->font('', 8)->lines(5, $y + 2.8, $debtor, 3.3);
    }
    $pdf->font('B', 6)->text(5, $top + 68, $hCur)->text(20, $top + 68, $hAmt);
    $pdf->font('', 8)->text(5, $top + 71, $currency)->text(20, $top + 71, $amount);
    $pdf->font('B', 6)->text(5, $top + 82, $hAccept, 'R', 52);
    // payment part
    $pdf->font('B', 11)->text(67, $top + 5, $tPayment);
    $pdf->qrCode("SPC\n0200\n1", 67, $top + 17, 46, 'M', true);
    if (!empty($d['notForPayment'])) {
        $pdf->font('B', 10)->text(67, $top + 40, 'NICHT ZUR ZAHLUNG VERWENDEN');
    }
    $pdf->font('B', 8)->text(67, $top + 68, $hCur)->text(87, $top + 68, $hAmt);
    $pdf->font('', 10)->text(67, $top + 72, $currency)->text(87, $top + 72, $amount);
    $y = $top + 5;
    $pdf->font('B', 8)->text(118, $y, $hAccount);
    $pdf->font('', 10);
    $y = $pdf->lines(118, $y + 3.5, array_merge([$d['iban']], $d['creditor']), 3.9) + 1.5;
    if ($ref !== null) {
        $pdf->font('B', 8)->text(118, $y, $hRef);
        $pdf->font('', 10)->text(118, $y + 3.5, $ref);
        $y += 8.9;
    }
    if ($message !== '') {
        $pdf->font('B', 8)->text(118, $y, $hInfo);
        $pdf->font('', 10);
        $y = $pdf->paragraph(118, $y + 3.5, 87, $message, 3.9) + 1.5;
    }
    $pdf->font('B', 8)->text(118, $y, $debtor !== null ? $hBy : $hByBlank);
    if ($debtor !== null) {
        $pdf->font('', 10)->lines(118, $y + 3.5, $debtor, 3.9);
    }
}

function invoicePage(PdfDocument $pdf, string $title): PdfDocument
{
    $pdf->addPage();
    $pdf->font('B', 14)->text(20, 30, $title);

    return $pdf;
}

/** module-debtor's own payment-part partial, drawn with stand-ins for the invoice and its bill (the partial reads only these methods). */
function debtorSlip(PdfDocument $pdf, string $qrr): void
{
    $bill = new class ($qrr) {
        public function __construct(private string $qrr) {}
        public function creditor(): array { return ['name' => 'Muster AG', 'street' => 'Musterstrasse', 'house_no' => '1', 'zip' => '8000', 'city' => 'Zürich', 'country' => 'CH']; }
        public function debtor(): ?array { return ['name' => 'Beispiel GmbH', 'street' => 'Beispielweg', 'house_no' => '12', 'zip' => '3000', 'city' => 'Bern', 'country' => 'CH']; }
        public function formattedAccount(): string { return QR_IBAN; }
        public function formattedReference(): string { return QrReference::format($this->qrr); }
        public function payload(): string { return "SPC\n0200\n1"; }
    };
    $payment = new class {
        public function getReferenceType(): string { return 'QRR'; }
        public function getMessage(): string { return 'Rechnung Nr. 3139 für Gartenarbeiten und Entsorgung Grünmaterial'; }
    };
    $document = new class ($payment) {
        public function __construct(private object $payment) {}
        public function getPayment(): object { return $this->payment; }
        public function getGrossTotal(): Money { return Money::of(123450, 'CHF'); }
        public function getCurrency(): string { return 'CHF'; }
    };
    (static function (PdfDocument $pdf, object $bill, object $document): void {
        $top    = null;
        $amount = null;
        include __DIR__ . '/../packages/module-debtor/res/view/templates/pdf/qrBill.tpl.php';
    })($pdf, $bill, $document);
}

$creditor = ['Muster AG', 'Musterstrasse 1', '8000 Zürich'];
$debtorLn = ['Beispiel GmbH', 'Beispielweg 12', '3000 Bern'];
$s1       = '//S1/10/10201409/11/190512/20/1400.000-53/30/106017086/31/180508/32/7.7/40/2:10;0:30';

if ($haveTool) {
    $recognizer = new TextLayerRecognizer($tools);
    $pdfText    = new PdfText($tools);

    // ── (P) PdfText ─────────────────────────────────────────────────────
    echo "\n(P) PdfText\n";
    $r = $pdfText->read('GIF89a not a pdf');
    check('P1 bytes that are no PDF are refused before any run', !$r->hasText() && $r->reason === 'Datei ist kein PDF', (string) $r->reason);
    $r = $pdfText->read("%PDF-1.4\nthis is not a pdf body\n");
    check('P2 a broken PDF: a reason, no exception', !$r->hasText() && $r->reason !== null, (string) $r->reason);
    $scan = PdfDocument::create('scan');
    $scan->addPage();
    $scan->fillColor(120)->rect(20, 20, 170, 250, true, false);
    $o = $recognizer->recognize($scan->output());
    check('P3 a PDF without text layer (a scan): «no_text» with the reason', $o->state === TextLayerOutcome::NO_TEXT && str_contains((string) $o->reason, 'Kein Textlayer'), dump([$o->state, $o->reason]));
    $small = new PdfText($tools, 20.0, 2_097_152, 100);
    check('P4 a PDF above the byte cap is refused', str_contains((string) $small->read(str_repeat('%PDF-1.4 ', 20))->reason, 'grösser'));
    check('P5 PdfText leaves no temp file', tempLeftovers() === [], implode(', ', tempLeftovers()));
    $both = $pdfText->readModes((invoicePage(PdfDocument::create('t'), 'Text'))->output());
    check('P6 both modes from one call (layout, raw)', array_keys($both) === [PdfText::MODE_LAYOUT, PdfText::MODE_RAW] && $both['layout']->hasText() && $both['raw']->hasText());

    // ── (E) end to end ──────────────────────────────────────────────────
    echo "\n(E) end to end: PDF → pdftotext → payment part\n";
    $recognize = static fn(PdfDocument $pdf): TextLayerOutcome => $recognizer->recognize($pdf->output());

    // E1 — module-debtor's own partial (DE, QR-IBAN, QRR, debtor, message)
    $pdf = invoicePage(PdfDocument::create('de'), 'Rechnung 3139');
    debtorSlip($pdf, $qrr);
    $o = $recognize($pdf);
    $p = $o->payment;
    check('E1 module-debtor\'s QR-bill partial (DE): recognised', $o->isRecognized(), dump([$o->state, $o->reason]));
    check('E1 … QR-IBAN, QRR reference, CHF 1234.50', $p?->iban === 'CH4431999123000889012' && $p->isQrIban && $p->referenceType === 'QRR' && $p->reference === $qrr && $p->currency === 'CHF' && decimal($p->amount) === '1234.50', dump($p));
    check('E1 … creditor and address, postcode + town', $p?->creditorName === 'Muster AG' && $p->creditorAddress === ['Musterstrasse 1', '8000 Zürich'] && $p->creditorPostalCode === '8000' && $p->creditorTown === 'Zürich', dump($p));
    check('E1 … debtor, message wrapped over two lines joined', $p?->debtorName === 'Beispiel GmbH' && $p->debtorAddress === ['Beispielweg 12', '3000 Bern'] && $p->message === 'Rechnung Nr. 3139 für Gartenarbeiten und Entsorgung Grünmaterial', dump($p));
    check('E1 … receipt and payment part agree: confidence high, no warning, usable', $p?->occurrences === 2 && $p->warnings === [] && $p->confidence() === RecognizedPayment::CONFIDENCE_HIGH && $p->isUsable(), dump($p));
    foreach ([PdfText::MODE_LAYOUT, PdfText::MODE_RAW] as $mode) {
        $single = SwissQrBillText::parse((string) $pdfText->read($pdf->output(), $mode)->text);
        check("E1 … the {$mode} text alone reads the same", $single?->iban === $p?->iban && $single?->reference === $p?->reference && decimal($single?->amount) === '1234.50' && $single?->creditorName === 'Muster AG', dump($single));
    }

    // E2 — FR, normal IBAN, SCOR, EUR, debtor blank, apostrophe amount
    $pdf = invoicePage(PdfDocument::create('fr'), 'Facture 77');
    drawSlip($pdf, 'fr', ['iban' => IBAN, 'creditor' => ['Beispiel GmbH', 'Beispielgasse 7', '1003 Lausanne'], 'reference' => ScorReference::format($scor), 'currency' => 'EUR', 'amount' => "1'234.50"]);
    $p = $recognize($pdf)->payment;
    check('E2 FR: IBAN, SCOR reference, EUR 1234.50 («1\'234.50»), payer box empty', $p?->iban === 'CH9300762011623852957' && !$p->isQrIban && $p->referenceType === 'SCOR' && $p->reference === $scor && $p->currency === 'EUR' && decimal($p->amount) === '1234.50' && $p->debtorName === null && $p->creditorTown === 'Lausanne' && $p->warnings === [], dump($p));

    // E3 — IT, NON, amount blank, debtor printed
    $pdf = invoicePage(PdfDocument::create('it'), 'Fattura 55');
    drawSlip($pdf, 'it', ['iban' => IBAN, 'creditor' => ['Beispiel GmbH', 'Via Esempio 3', '6900 Lugano'], 'message' => 'Fattura 55', 'debtor' => $debtorLn]);
    $p = $recognize($pdf)->payment;
    check('E3 IT: NON (no reference printed), the amount left blank → null, CHF, debtor read', $p?->referenceType === 'NON' && $p->reference === null && $p->amount === null && $p->currency === 'CHF' && $p->debtorName === 'Beispiel GmbH' && $p->message === 'Fattura 55' && $p->warnings === [], dump($p));
    check('E3 … usable only with a known creditor (plan §4)', $p !== null && !$p->isUsable() && $p->isUsable(true));

    // E4 — EN, QRR, Swico S1 broken over lines, the slip on page 2 behind a body with a «Amount» column
    $pdf = PdfDocument::create('en');
    invoicePage($pdf, 'Invoice 10201409');
    $pdf->font('B', 10)->text(20, 50, 'Item')->text(150, 50, 'Amount');
    $pdf->font('', 10)->text(20, 56, 'Consulting')->text(150, 56, '999.00');
    $pdf->font('', 10)->text(20, 62, 'Reference')->text(20, 66, 'Your order 12');
    $pdf->addPage();
    drawSlip($pdf, 'en', ['iban' => QR_IBAN, 'creditor' => $creditor, 'reference' => QrReference::format($qrr), 'message' => 'Order 12 ' . $s1, 'debtor' => $debtorLn, 'amount' => '3 949.75']);
    $o = $recognize($pdf);
    $p = $o->payment;
    check('E4 EN, slip on page 2: the body\'s «Amount 999.00» is not taken — 3949.75', decimal($p?->amount) === '3949.75' && $p->referenceType === 'QRR', dump($p));
    check('E4 … the message without the S1 part', $p?->message === 'Order 12', dump($p?->message));
    $b = $p?->billing;
    check('E4 … Swico S1 read across the line breaks: invoice no, date, customer ref, UID, VAT, terms', $b !== null && $b->invoiceNumber === '10201409' && $b->invoiceDate?->format('Y-m-d') === '2019-05-12' && $b->customerReference === '1400.000-53' && $b->uid === 'CHE-106.017.086' && $b->vatDateFrom?->format('Y-m-d') === '2018-05-08' && $b->vatRates === [['rate' => '7.7', 'net' => null]] && $b->terms === [['percent' => '2', 'days' => 10], ['percent' => '0', 'days' => 30]], dump($b === null ? null : get_object_vars($b)));
    check('E4 … due date = invoice date + the net term (30 days)', $b?->dueDate()?->format('Y-m-d') === '2019-06-11', (string) $b?->dueDate()?->format('Y-m-d'));

    // E5 — the receipt prints another IBAN
    $pdf = invoicePage(PdfDocument::create('x'), 'Rechnung 5');
    drawSlip($pdf, 'de', ['iban' => IBAN, 'receiptIban' => OTHER_IBAN, 'creditor' => $creditor, 'amount' => '50.00']);
    $p = $recognize($pdf)->payment;
    check('E5 receipt and payment part with different IBANs: the payment part\'s, a warning, confidence low', $p?->iban === 'CH9300762011623852957' && $p->confidence() === RecognizedPayment::CONFIDENCE_LOW && str_contains(implode(' ', $p->warnings), 'Verschiedene IBAN'), dump($p));

    // E6 — «NICHT ZUR ZAHLUNG VERWENDEN»
    $pdf = invoicePage(PdfDocument::create('x'), 'Rechnung 6');
    drawSlip($pdf, 'de', ['iban' => QR_IBAN, 'creditor' => $creditor, 'reference' => QrReference::format($qrr), 'amount' => '0.00', 'notForPayment' => true]);
    $p = $recognize($pdf)->payment;
    check('E6 «NICHT ZUR ZAHLUNG VERWENDEN»: flagged, warned, not usable, 0.00 is no amount', $p !== null && $p->notForPayment && !$p->isUsable(true) && $p->amount === null && str_contains(implode(' ', $p->warnings), 'Nicht zur Zahlung'), dump($p));

    // E7 — QR-IBAN without QR reference (invalid combination)
    $pdf = invoicePage(PdfDocument::create('x'), 'Rechnung 7');
    drawSlip($pdf, 'de', ['iban' => QR_IBAN, 'creditor' => $creditor, 'amount' => '10.00']);
    $p = $recognize($pdf)->payment;
    check('E7 a QR-IBAN without QR reference: read, but warned', $p?->iban === 'CH4431999123000889012' && $p->referenceType === 'NON' && in_array('Eine QR-IBAN verlangt eine QR-Referenz', $p->warnings, true), dump($p));

    // E8 — a plain invoice
    $pdf = invoicePage(PdfDocument::create('x'), 'Rechnung 8');
    $pdf->font('', 10)->text(20, 50, 'Betrag')->text(60, 50, 'CHF 450.00')->text(20, 56, 'Bitte überweisen auf ' . IBAN)->text(20, 62, 'Referenz')->text(20, 66, $scor);
    $o = $recognize($pdf);
    check('E8 a plain invoice (IBAN, «Betrag», «Referenz» in the body): «not_found», the text kept', $o->state === TextLayerOutcome::NOT_FOUND && $o->payment === null && str_contains((string) $o->text, 'Rechnung 8'), dump([$o->state, $o->reason]));
    check('E9 no temp file left after all runs', tempLeftovers() === [], implode(', ', tempLeftovers()));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n(X) text fixtures\n";
$fixture = static fn(string $name): string => (string) file_get_contents(__DIR__ . '/fixtures/creditor-recognition/' . $name);

$p = SwissQrBillText::parse($fixture('poppler-layout-de-scor.txt'));
check('X1 poppler-style layout (DE): values one column off their heading, IBAN + SCOR', $p?->iban === 'CH9300762011623852957' && $p->referenceType === 'SCOR' && $p->reference === $scor, dump($p));
check('X1 … «Währung Betrag» run together in the receipt, apostrophe grouping: CHF 1081.00 — not the body\'s «Betrag» column', $p?->currency === 'CHF' && decimal($p->amount) === '1081.00' && $p->warnings === [], dump($p));
check('X1 … creditor from the payment part, payer box empty, message', $p?->creditorName === 'Beispiel GmbH' && $p->creditorAddress === ['Beispielgasse 7', '8000 Zürich'] && $p->debtorName === null && $p->message === 'Rechnung 2026-117 / Kunde 4711' && $p->occurrences === 2, dump($p));

$p = SwissQrBillText::parse($fixture('poppler-layout-fr-qrr.txt'));
check('X2 FR layout, two titles run together, QR-IBAN + QRR, EUR «12 500.00»', $p?->iban === 'CH4431999123000889012' && $p->reference === $qrr && $p->currency === 'EUR' && decimal($p->amount) === '12500.00' && $p->warnings === [], dump($p));
check('X2 … a value line run together with the receipt by ONE space is cut at the column («CH-8000 Zürich»)', $p?->creditorAddress === ['Musterstrasse 1', 'CH-8000 Zürich'] && $p->creditorPostalCode === '8000' && $p->creditorTown === 'Zürich', dump($p));
check('X2 … debtor from the payment part', $p?->debtorName === 'Beispiel GmbH' && $p->debtorAddress === ['Beispielgasse 7', '3000 Bern'] && $p->message === 'Facture 8812', dump($p));

$p = SwissQrBillText::parse($fixture('xpdf-default-it-non.txt'));
check('X3 xpdf default mode (IT): IBAN read from the line the address was joined onto, NON, amount blank', $p?->iban === 'CH9300762011623852957' && $p->referenceType === 'NON' && $p->amount === null && $p->currency === 'CHF' && $p->warnings === [], dump($p));
check('X3 … the joined address stays one line; postcode + town still found', $p?->creditorName === 'Beispiel GmbH Beispielgasse 7 8000 Zürich' && $p->creditorPostalCode === '8000' && $p->creditorTown === 'Zürich', dump($p));

check('X4 a plain invoice whose body names IBAN, «Betrag», «Referenz» and «Konto … Zahlbar an» → null', SwissQrBillText::parse($fixture('plain-invoice.txt')) === null);
check('X5 empty text → null', SwissQrBillText::parse('') === null && SwissQrBillText::parseBest() === null);

$broken = str_replace('CH93 0076 2011 6238 5295 7', 'CH93 0076 2011 6238 5295 8', $fixture('poppler-layout-de-scor.txt'));
$p      = SwissQrBillText::parse($broken);
check('X6 an IBAN with wrong check digits is NOT taken: iban null, warned, not usable', $p !== null && $p->iban === null && !$p->isUsable(true) && str_contains(implode(' ', $p->warnings), 'ungültig') && $p->confidence() === RecognizedPayment::CONFIDENCE_LOW, dump($p));
$p = SwissQrBillText::parse(str_replace('RF18 5390 0754 7034', 'RF19 5390 0754 7034', $fixture('poppler-layout-de-scor.txt')));
check('X7 a reference failing its check: type null, reference null, warned', $p !== null && $p->referenceType === null && $p->reference === null && str_contains(implode(' ', $p->warnings), 'Referenz'), dump($p));
$p = SwissQrBillText::parse(preg_replace("/(CHF {5})1'081\\.00/", '${1}1\'018.00', $fixture('poppler-layout-de-scor.txt'), 1));
check('X8 receipt and payment part with different amounts: the payment part\'s, warned', decimal($p?->amount) === '1081.00' && str_contains(implode(' ', $p->warnings), 'Verschiedene Beträge'), dump($p));

$best = SwissQrBillText::parseBest($fixture('plain-invoice.txt'), $fixture('poppler-layout-de-scor.txt'));
check('X9 parseBest(): a text without slip does not hide the one with it', $best?->reference === $scor);
$other = str_replace('CH93 0076 2011 6238 5295 7', 'CH56 0483 5012 3456 7800 9', $fixture('poppler-layout-de-scor.txt'));
$best  = SwissQrBillText::parseBest($fixture('poppler-layout-de-scor.txt'), $other);
check('X10 parseBest(): two extractions that disagree on the IBAN are a warning', $best !== null && str_contains(implode(' ', $best->warnings), 'widersprechen sich (IBAN)'), dump($best));

// ─────────────────────────────────────────────────────────────────────────
echo "\n(A) amounts\n";
$slip = static fn(string $amount): string => "Zahlteil\nKonto / Zahlbar an\n" . IBAN . "\nMuster AG\nWährung Betrag\nCHF {$amount}\n";
foreach (['1 234.50' => '1234.50', "1'234.50" => '1234.50', "1\u{2019}234.50" => '1234.50', '1234.50' => '1234.50', '0.05' => '0.05', '999 999 999.99' => '999999999.99'] as $printed => $expected) {
    $p = SwissQrBillText::parse($slip($printed));
    check("A «{$printed}» → {$expected}", decimal($p?->amount) === $expected && $p->warnings === [], dump($p));
}
foreach (['1 000 000 000.00' => 'above the range', '12.5' => 'one decimal', '1 23.50' => 'wrong grouping', '1,234.50' => 'comma grouping'] as $printed => $why) {
    $p = SwissQrBillText::parse($slip($printed));
    check("A «{$printed}» ({$why}) → no amount, warned", $p !== null && $p->amount === null && $p->warnings !== [], dump($p));
}
$p = SwissQrBillText::parse("Zahlteil\nKonto / Zahlbar an\n" . IBAN . "\nMuster AG\nWährung Betrag\nCHF\n");
check('A blank amount → null, no warning', $p !== null && $p->amount === null && $p->currency === 'CHF' && $p->warnings === [], dump($p));
$p = SwissQrBillText::parse("Zahlteil\nKonto / Zahlbar an\n" . IBAN . "\nMuster AG\nBetrag gemäss Offerte 500.00\n");
check('A «Betrag» inside a sentence is no heading', $p !== null && $p->amount === null, dump($p));

// ─────────────────────────────────────────────────────────────────────────
echo "\n(S) Swico S1\n";
$b = SwicoBillingInfo::parse($s1);
check('S1 the Swico example: all named fields', $b?->invoiceNumber === '10201409' && $b->uid === 'CHE-106.017.086' && $b->dueDate()?->format('Y-m-d') === '2019-06-11' && $b->warnings === [], dump($b === null ? null : get_object_vars($b)));
$b = SwicoBillingInfo::parse("//S1/10/R-2026\\/17/11/260230/30/106017087/31/261001261031/32/8.1:1000;2.6:50/40/0:20");
check('S2 escaped slash in the invoice number, VAT period, two VAT rates', $b?->invoiceNumber === 'R-2026/17' && $b->vatDateFrom?->format('Y-m-d') === '2026-10-01' && $b->vatDateTo?->format('Y-m-d') === '2026-10-31' && $b->vatRates === [['rate' => '8.1', 'net' => '1000'], ['rate' => '2.6', 'net' => '50']], dump($b === null ? null : get_object_vars($b)));
check('S2 … an impossible date (30 Feb) and a UID with a wrong check digit are dropped with warnings; no due date without a date', $b?->invoiceDate === null && $b->uid === null && count($b->warnings) === 2 && $b->dueDate() === null, dump($b?->warnings));
$b = SwicoBillingInfo::parse("//S1/10/4711/11/261115/40/3:10;\n1:20");
check('S3 terms without a 0 % entry: the longest term is the due date', $b?->dueDate()?->format('Y-m-d') === '2026-12-05', (string) $b?->dueDate()?->format('Y-m-d'));
check('S4 text that is no S1 → null', SwicoBillingInfo::parse('//S2/xyz') === null && SwicoBillingInfo::parse('Rechnung 12') === null);
$b = SwicoBillingInfo::parse('//S1/10/12/40/zwei Wochen');
check('S5 terms in prose: dropped with a warning', $b !== null && $b->terms === [] && $b->warnings !== [], dump($b?->warnings));

// ─────────────────────────────────────────────────────────────────────────
echo "\n(H) the header above the slip — labels, the letterhead street, the QR message (owner 2026-11-15)\n";
$slipOf = static fn(string $name, string $zipTown, string $amount, string $message = ''): string
    => "Empfangsschein                    Zahlteil\nKonto / Zahlbar an\n" . IBAN . "\n{$name}\n{$zipTown}\n"
    . ($message !== '' ? "Zusätzliche Informationen\n{$message}\n" : '') . "Währung Betrag\nCHF {$amount}\n";
$wingoHead = "Swisscom (Schweiz) AG                    Rechnungsbetrag      25.55\n"
    . "Alte Tiefenaustrasse 6                   Zahlbar bis          28.02.2026\n"
    . "3050 Bern                                Rechnungsdatum       31.01.2026\n"
    . "                                         Rechnungsnummer      416070524\n"
    . "                                         Kundennummer         1.2345678\n"
    . "Periode Januar 2026\n\n";
$wingo = $wingoHead . $slipOf('Swisscom (Schweiz) AG', '3050 Bern', '25.55', 'Wingo, Januar 2026');
check('H1 textBefore(): the header ends where the slip begins; without a slip it is the whole text',
    str_contains(SwissQrBillText::textBefore($wingo), 'Rechnungsnummer') && !str_contains(SwissQrBillText::textBefore($wingo), 'Zahlteil')
    && SwissQrBillText::textBefore("Rechnung 7\nTotal 5.00") === "Rechnung 7\nTotal 5.00");
$f = \Z77\Module\Creditor\Recognition\InvoiceFacts::fromPayment(SwissQrBillText::parse($wingo), $wingo);
check('H2 the Wingo layout: number 416070524 (NOT the Kundennummer), invoice date 31.01.2026, due 28.02.2026 — each source «text-label»; the amount 25.55 agrees, no warning; the QR message «Wingo, Januar 2026» becomes the line text',
    $f->invoiceNumber === '416070524' && $f->invoiceDate?->format('Y-m-d') === '2026-01-31' && $f->dueDate?->format('Y-m-d') === '2026-02-28'
    && $f->sources === ['invoice_number' => 'text-label', 'invoice_date' => 'text-label', 'due_date' => 'text-label', 'proposal_street' => 'text-header']
    && $f->lineText === 'Wingo, Januar 2026' && !str_contains(implode(' ', $f->warnings), 'Rechnungsbetrag'), dump([$f->invoiceNumber, $f->invoiceDate, $f->dueDate, $f->sources, $f->lineText, $f->warnings]));
check('H3 the slip has no street: the letterhead\'s «Swisscom (Schweiz) AG / Alte Tiefenaustrasse 6 / 3050 Bern» (same name, same postcode + town) gives street and house number',
    ($f->proposal['street'] ?? '') === 'Alte Tiefenaustrasse' && ($f->proposal['house_no'] ?? '') === '6' && ($f->proposal['zip'] ?? '') === '3050' && ($f->proposal['city'] ?? '') === 'Bern', dump($f->proposal));
$otherZip = str_replace("3050 Bern                                Rechnungsdatum", "3000 Bern                                Rechnungsdatum", $wingo);
$f2 = \Z77\Module\Creditor\Recognition\InvoiceFacts::fromPayment(SwissQrBillText::parse($otherZip), $otherZip);
check('H4 the letterhead names another postcode (3000 vs. the slip\'s 3050): no street taken, no source', !isset($f2->proposal['street']) && !isset($f2->sources['proposal_street']), dump($f2->proposal));
$poBox = str_replace('Alte Tiefenaustrasse 6       ', 'Postfach                     ', $wingo);
check('H4 … a «Postfach» line instead of a street: nothing taken', !isset(\Z77\Module\Creditor\Recognition\InvoiceFacts::fromPayment(SwissQrBillText::parse($poBox), $poBox)->proposal['street']));
$mismatch = str_replace('Rechnungsbetrag      25.55', 'Rechnungsbetrag      52.55', $wingo);
$f3 = \Z77\Module\Creditor\Recognition\InvoiceFacts::fromPayment(SwissQrBillText::parse($mismatch), $mismatch);
check('H5 a «Rechnungsbetrag» that disagrees with the QR amount: a warning, the QR amount (25.55) wins', decimal($f3->amount) === '25.55' && str_contains(implode(' ', $f3->warnings), 'weicht vom QR-Betrag (25.55) ab'), dump($f3->warnings));
$s1Head = "Rechnungsnummer R-99\nRechnungsdatum 01.02.2026\n\n" . str_replace('Wingo, Januar 2026', '//S1/10/4711/11/260115', $slipOf('Muster AG', '8000 Zürich', '10.00', 'Wingo, Januar 2026'));
$f4 = \Z77\Module\Creditor\Recognition\InvoiceFacts::fromPayment(SwissQrBillText::parse($s1Head), $s1Head);
check('H6 Swico S1 wins over the labels: number 4711 and date 15.01.2026 from S1, no «text-label» source for them', $f4->invoiceNumber === '4711' && $f4->invoiceDate?->format('Y-m-d') === '2026-01-15' && !isset($f4->sources['invoice_number']) && !isset($f4->sources['invoice_date']), dump([$f4->invoiceNumber, $f4->invoiceDate, $f4->sources]));
$labels = static fn(string $t) => \Z77\Module\Creditor\Recognition\HeaderLabels::read($t);
$fr = $labels("Facture n° F-2026-17\nDate: 3 février 2026\nÉchéance 5.3.26");
$it = $labels("Numero fattura 2026/88\nData fattura 12.03.2026\nScadenza 11 aprile 2026");
$en = $labels("Invoice no.   Invoice date      Due date\nINV-77        12 January 2026   11.02.2026");
check('H7 labels per language: FR (n°, «3 février 2026», «5.3.26»), IT («Numero fattura», «11 aprile 2026»), EN (a header table — the value below its label)',
    $fr->invoiceNumber === 'F-2026-17' && $fr->invoiceDate?->format('Y-m-d') === '2026-02-03' && $fr->dueDate?->format('Y-m-d') === '2026-03-05'
    && $it->invoiceNumber === '2026/88' && $it->invoiceDate?->format('Y-m-d') === '2026-03-12' && $it->dueDate?->format('Y-m-d') === '2026-04-11'
    && $en->invoiceNumber === 'INV-77' && $en->invoiceDate?->format('Y-m-d') === '2026-01-12' && $en->dueDate?->format('Y-m-d') === '2026-02-11',
    dump([$fr, $it, $en]));
$amb = $labels("Datum 01.02.2026\nLieferung\nDatum 03.02.2026\nRechnungsdatum fehlt");
$one = $labels("Datum: 4. März 2026\nKundennummer 12345\nKunden-Nr. 777");
check('H8 a bare «Datum» twice is ambiguous → no date; once → taken; «Kundennummer» / «Kunden-Nr.» are never an invoice number',
    $amb->invoiceDate === null && $one->invoiceDate?->format('Y-m-d') === '2026-03-04' && $one->invoiceNumber === null, dump([$amb, $one]));
$terms = \Z77\Module\Creditor\Recognition\InvoiceFacts::fromTextOnly("Rechnung Nr. 55\nRechnungsdatum 10.01.2026\nzahlbar innert 30 Tagen netto");
$back  = \Z77\Module\Creditor\Recognition\InvoiceFacts::fromTextOnly("Rechnungsnummer 56\nRechnungsdatum 10.01.2026\nZahlbar bis 01.01.2026");
check('H9 no slip: the labels still give number and date; «zahlbar innert 30 Tagen» = invoice date + 30; a due date before the invoice date is dropped with a warning',
    $terms?->invoiceNumber === '55' && $terms->dueDate?->format('Y-m-d') === '2026-02-09' && $terms->sources['due_date'] === 'text-label'
    && $back?->dueDate === null && str_contains(implode(' ', $back->warnings), 'Fälligkeit vor dem Rechnungsdatum'), dump([$terms, $back]));
check('H10 a text without UID and without labels → no facts (null)', \Z77\Module\Creditor\Recognition\InvoiceFacts::fromTextOnly("Quittung\nDanke für den Einkauf") === null);
check('H11 an unavailable tool names the PHP that probed: «geprüft mit PHP-CLI (Cron)» here; the web reads «PHP-Web (fpm-fcgi)»',
    str_contains((string) RecognitionTools::detect('z77-no-such-pdftotext')->reason, 'geprüft mit PHP-CLI (Cron)') && RecognitionTools::sapiLabel('fpm-fcgi') === 'PHP-Web (fpm-fcgi)',
    (string) RecognitionTools::detect('z77-no-such-pdftotext')->reason);

echo "\n{$pass} ok, {$fail} failed" . ($notes !== [] ? ', ' . count($notes) . ' skipped with a note' : '') . "\n";
foreach ($notes as $text) {
    echo "NOTE: {$text}\n";
}
exit($fail === 0 ? 0 : 1);
