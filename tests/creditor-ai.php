<?php

/**
 * Creditor AI harness (CLI, offline, no DI, no database, NO network, no real key) — the AI core of
 * the creditor plan §4 step 3 / owner answer §11.9: an unclear supplier invoice's PDF → one
 * Anthropic Messages request → validated values in the text layer's own `RecognizedPayment` plus
 * `AiInvoiceData`.
 *
 * What is load-bearing:
 *
 *   (C) the switch — off by default; on only with `creditorAi.enabled === true` in kibot's file AND
 *       a readable key file (the shared `paths.keyFile`); wrong settings fall back to defaults;
 *       the key never shows in a dump;
 *   (G) the guards before anything is sent — off, no key, not a PDF, the size cap, the per-day cap
 *       (counted before the call, reset on a new day, fail closed without a counter); the fake
 *       transport proves nothing left;
 *   (Q) the request: the PDF as a base64 `document` block, the optional text layer, the JSON
 *       schema through `output_config.format` (every object closed, every property required),
 *       no forced tool_choice, model / effort / fallback from the settings, the key only in the
 *       header the transport sees;
 *   (V) a valid answer mapped — IBAN, QR reference, gross as Money, UID, dates, VAT lines, line
 *       text, category; partially invalid values dropped with German warnings, the rest kept;
 *   (F) failures as outcomes, never exceptions — garbage, a JSON list, refusal, max_tokens, an
 *       empty answer, HTTP 500 / 429 / 401 / 404, a timeout, a transport that throws: all
 *       «KI-Erkennung fehlgeschlagen — von Hand erfassen»;
 *   (L) the log line: one per attempt, no PDF, no text, no answer value, no key, no API message;
 *   (S) source rules: no superglobal, no network code outside kibot's transport, German privacy
 *       sentence without ß.
 *
 * Example data only (SIX sample IBANs, «Muster AG») — no customer data.
 *
 * Run: php tests/creditor-ai.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "creditor-ai: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\KiBot\HttpResult;
use Z77\KiBot\Transport;
use Z77\Module\Creditor\Recognition\Ai\AiDailyCap;
use Z77\Module\Creditor\Recognition\Ai\AiInvoiceMapper;
use Z77\Module\Creditor\Recognition\Ai\AiInvoiceReader;
use Z77\Module\Creditor\Recognition\Ai\AiOutcome;
use Z77\Module\Creditor\Recognition\Ai\AiSettings;
use Z77\Module\Creditor\Recognition\RecognizedPayment;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Replays queued responses and records every request. No network. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: list<string>, body: string}> */
    public array $calls = [];

    /** @param list<HttpResult|\Closure> $queue */
    public function __construct(public array $queue = []) {}

    public function send(string $url, array $headers, string $body, ?callable $onChunk = null): HttpResult
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue) ?? new HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"queue empty"}}');

        return $next instanceof \Closure ? $next() : $next;
    }
}

const SECRET = 'sk-ant-TEST-ONLY-not-a-real-key-4711';

$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-creditor-ai-' . bin2hex(random_bytes(4));
mkdir($tmp . '/keys', 0775, true);
file_put_contents($tmp . '/keys/anthropic.key', SECRET . "\n");

$now   = strtotime('2026-11-15 10:00:00');
$clock = static function () use (&$now): int { return $now; };

$pdf = "%PDF-1.7\n% fake invoice body MUSTER-PDF-CONTENT\n1 0 obj << >> endobj\n%%EOF\n";

/** kibot overrides as `config/client/kibot.inc.php` would return them. */
function overrides(array $creditorAi = [], array $paths = []): array
{
    return [
        'paths'      => $paths + ['keyFile' => 'keys/anthropic.key', 'logDir' => 'logs/kibot'],
        'api'        => ['version' => '2026-10-21'],
        'widget'     => ['botName' => 'Website-Bot'],
        'creditorAi' => $creditorAi,
    ];
}

function reader(array $creditorAi, FakeTransport $transport, array $paths = []): AiInvoiceReader
{
    global $tmp, $clock;

    return new AiInvoiceReader(AiSettings::fromOverrides(overrides($creditorAi, $paths), $tmp), $transport, $clock);
}

/** A Messages API response carrying $answer as the structured-output text. */
function message(mixed $answer, string $stop = 'end_turn'): HttpResult
{
    return new HttpResult(200, json_encode([
        'id'          => 'msg_test',
        'type'        => 'message',
        'role'        => 'assistant',
        'model'       => 'claude-opus-5-5',
        'stop_reason' => $stop,
        'content'     => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
            ['type' => 'text', 'text' => is_string($answer) ? $answer : json_encode($answer)],
        ],
        'usage'       => ['input_tokens' => 2345, 'output_tokens' => 321],
    ]));
}

function validAnswer(array $over = []): array
{
    return $over + [
        'creditor_name'          => 'Muster AG',
        'creditor_address_lines' => ['Musterstrasse 1'],
        'creditor_postal_code'   => '8000',
        'creditor_town'          => 'Zürich',
        'creditor_country'       => 'ch',
        'creditor_uid'           => 'CHE-109.322.551 MWST',
        'iban'                   => 'CH44 3199 9123 0008 8901 2',
        'currency'               => 'CHF',
        'gross_total'            => "1'081.00",
        'vat_lines'              => [['rate' => '8.1', 'net' => '1000.00', 'vat' => '81.00'], ['rate' => '0', 'net' => '0.00', 'vat' => '0.00']],
        'invoice_number'         => 'RE-2026-0815',
        'invoice_date'           => '2026-11-01',
        'due_date'               => '2026-12-01',
        'reference_type'         => 'QRR',
        'reference'              => '21 00000 00003 13947 14300 09017',
        'line_text'              => 'Druckerpatronen November 2026',
        'expense_category'       => 'Büromaterial',
    ];
}

function logText(): string
{
    global $tmp;
    $text = '';
    foreach (glob($tmp . '/logs/kibot/creditor-ai_*.log') ?: [] as $file) {
        $text .= file_get_contents($file);
    }

    return $text;
}

function resetCap(): void
{
    global $tmp;
    @unlink($tmp . '/logs/kibot/rate/' . AiDailyCap::FILE);
}

echo "C  the switch\n";

$s = AiSettings::fromOverrides(null, $tmp);
check('C1 no kibot file → off', !$s->switchedOn() && !$s->enabled());
$s = AiSettings::fromOverrides(overrides(), $tmp);
check('C2 file without creditorAi.enabled → off (default false)', !$s->switchedOn() && !$s->enabled() && AiSettings::DEFAULTS['enabled'] === false);
$s = AiSettings::fromOverrides(overrides(['enabled' => 'true']), $tmp);
check('C3 «true» as a string does not switch it on', !$s->switchedOn());
$s = AiSettings::fromOverrides(overrides(['enabled' => true], ['keyFile' => 'keys/missing.key']), $tmp);
check('C4 on, key file missing → switched on but not enabled', $s->switchedOn() && !$s->hasKey() && !$s->enabled());
$s = AiSettings::fromOverrides(overrides(['enabled' => true]), $tmp);
check('C5 on + readable key → enabled', $s->enabled());
check('C6 paths resolve against the project root like kibot', $s->logDir() === $tmp . '/logs/kibot' && $s->rateDir() === $tmp . '/logs/kibot/rate');
check('C7 defaults: model claude-opus-5-5, effort medium, 10 MiB, 50/day', $s->model() === 'claude-opus-5-5' && $s->effort() === 'medium'
    && $s->maxPdfBytes() === 10485760 && $s->maxPerDay() === 50 && $s->timeoutSeconds() === 120);
check('C8 api version / endpoint / fallback beta from the shared kibot section', $s->apiVersion() === '2026-10-21'
    && $s->endpoint() === 'https://api.anthropic.com/v1/messages' && $s->fallbackBeta() !== '');
$s = AiSettings::fromOverrides(overrides(['enabled' => true, 'model' => 'claude-sonnet-5-5', 'maxPerDay' => 5, 'maxPdfBytes' => -1, 'effort' => 'turbo', 'maxTokens' => '9000']), $tmp);
check('C9 a valid model / cap is taken, wrong values fall back to defaults', $s->model() === 'claude-sonnet-5-5' && $s->maxPerDay() === 5
    && $s->maxPdfBytes() === 10485760 && $s->effort() === 'medium' && $s->maxTokens() === 16000);
$s = AiSettings::fromOverrides(overrides(['enabled' => true, 'model' => "x'; rm -rf"]), $tmp);
check('C10 a model id with foreign characters is refused', $s->model() === 'claude-opus-5-5');
$dump = print_r($s, true) . var_export($s->keyStore(), true);
$s->keyStore()->get();
$dump .= print_r($s->keyStore(), true) . print_r($s, true);
check('C11 the key never shows in a dump of the settings / key store', !str_contains($dump, SECRET));
check('C12 forInstallation() without a config file → off, no exception', !AiSettings::forInstallation($tmp)->enabled());

echo "G  guards before sending\n";

$t = new FakeTransport();
$o = reader([], $t)->read($pdf);
check('G1 off → SKIPPED, nothing sent', $o->state === AiOutcome::SKIPPED && $t->calls === [] && str_contains((string) $o->reason, 'ausgeschaltet'));
check('G2 off → no log line', logText() === '');
$o = reader(['enabled' => true], $t, ['keyFile' => 'keys/missing.key'])->read($pdf);
check('G3 no key → SKIPPED «von Hand erfassen», nothing sent', $o->state === AiOutcome::SKIPPED && $t->calls === [] && str_contains((string) $o->reason, 'von Hand erfassen'));
$o = reader(['enabled' => true], $t)->read("PK\x03\x04 not a pdf");
check('G4 not a PDF → SKIPPED, nothing sent', $o->state === AiOutcome::SKIPPED && $t->calls === [] && str_contains((string) $o->reason, 'kein PDF'));
$o = reader(['enabled' => true, 'maxPdfBytes' => 1024], $t)->read($pdf . str_repeat('x', 2000));
check('G5 above maxPdfBytes → SKIPPED with sizes, nothing sent', $o->state === AiOutcome::SKIPPED && $t->calls === [] && str_contains((string) $o->reason, 'zu gross'), (string) $o->reason);

resetCap();
$t = new FakeTransport([message(validAnswer()), message(validAnswer()), message(validAnswer())]);
$r = reader(['enabled' => true, 'maxPerDay' => 2], $t);
$a = $r->read($pdf);
$b = $r->read($pdf);
$c = $r->read($pdf);
check('G6 per-day cap: two calls go, the third is SKIPPED unsent', $a->isRecognized() && $b->isRecognized() && $c->state === AiOutcome::SKIPPED
    && count($t->calls) === 2 && str_contains((string) $c->reason, 'Tageslimit'), (string) $c->reason);
$now += 86400;
$d = $r->read($pdf);
check('G7 a new day starts at zero', $d->isRecognized() && count($t->calls) === 3);
$now -= 86400;
resetCap();
$t = new FakeTransport([new HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"boom"}}'), message(validAnswer())]);
$r = reader(['enabled' => true, 'maxPerDay' => 1], $t);
$r->read($pdf);
$o = $r->read($pdf);
check('G8 a failed call counts against the cap (it may cost)', $o->state === AiOutcome::SKIPPED && count($t->calls) === 1);
resetCap();
file_put_contents($tmp . '/blocker', 'x');
$t = new FakeTransport([message(validAnswer())]);
$o = reader(['enabled' => true], $t, ['rateDir' => 'blocker/rate'])->read($pdf);
check('G9 no counter possible → fail closed, nothing sent', $o->state === AiOutcome::SKIPPED && $t->calls === []);
check('G10 AiDailyCap count() reads the day', (new AiDailyCap($tmp . '/logs/kibot/rate', $clock))->count() === 0);

echo "Q  the request\n";

resetCap();
$t = new FakeTransport([message(validAnswer())]);
$o = reader(['enabled' => true], $t)->read($pdf, "Rechnung Muster AG\nTotal CHF 1'081.00", 'intake-42');
$call = $t->calls[0] ?? ['url' => '', 'headers' => [], 'body' => '{}'];
$body = json_decode($call['body'], true);
$doc  = $body['messages'][0]['content'][0] ?? [];
check('Q1 exactly one POST to the configured endpoint', count($t->calls) === 1 && $call['url'] === 'https://api.anthropic.com/v1/messages');
check('Q2 the PDF as a base64 document block, first in the content', ($doc['type'] ?? '') === 'document'
    && ($doc['source']['type'] ?? '') === 'base64' && ($doc['source']['media_type'] ?? '') === 'application/pdf'
    && base64_decode((string) ($doc['source']['data'] ?? '')) === $pdf);
check('Q3 the text layer goes along as a text block', str_contains((string) ($body['messages'][0]['content'][1]['text'] ?? ''), 'Total CHF'));
$format = $body['output_config']['format'] ?? [];
check('Q4 structured output: json_schema with all fields required', ($format['type'] ?? '') === 'json_schema'
    && ($format['schema']['required'] ?? []) === AiInvoiceMapper::FIELDS
    && array_keys($format['schema']['properties'] ?? []) === AiInvoiceMapper::FIELDS);
$closed = static function (array $node) use (&$closed): bool {
    if (($node['type'] ?? null) === 'object') {
        if (($node['additionalProperties'] ?? null) !== false || array_keys($node['properties'] ?? []) !== ($node['required'] ?? null)) {
            return false;
        }
    }
    foreach ($node as $child) {
        if (is_array($child) && !$closed($child)) {
            return false;
        }
    }

    return true;
};
check('Q5 every object in the schema is closed and fully required', $closed($format['schema'] ?? []));
check('Q6 no forced tool_choice, no tools', !isset($body['tool_choice']) && !isset($body['tools']));
check('Q7 model, max_tokens, effort and fallback from the settings', ($body['model'] ?? '') === 'claude-opus-5-5'
    && ($body['max_tokens'] ?? 0) === 16000 && ($body['output_config']['effort'] ?? '') === 'medium' && ($body['fallbacks'] ?? '') === 'default');
check('Q8 headers: key, version, fallback beta', in_array('x-api-key: ' . SECRET, $call['headers'], true)
    && in_array('anthropic-version: 2026-10-21', $call['headers'], true)
    && (bool) preg_grep('/^anthropic-beta: server-side-fallback-/', $call['headers']));
check('Q9 the key is in the header only — never in the body', !str_contains($call['body'], SECRET));
$q = (new AiInvoiceReader(AiSettings::fromOverrides(overrides(['enabled' => true, 'effort' => '', 'serverSideFallback' => false]), $tmp)))->requestBody($pdf);
check('Q10 effort \'\' and fallback off → neither is sent; no text layer → no extra block', !isset($q['output_config']['effort'])
    && !isset($q['fallbacks']) && count($q['messages'][0]['content']) === 2);
check('Q11 nothing but the PDF (+ text layer) and the instruction leaves', count($body['messages']) === 1 && count($body['messages'][0]['content']) === 3);

echo "V  answers mapped and re-checked\n";

check('V1 a valid answer → RECOGNIZED', $o->isRecognized(), (string) $o->detail);
$data = $o->data;
$p    = $data?->payment;
check('V2 payment in the text layer\'s RecognizedPayment', $p instanceof RecognizedPayment);
check('V3 IBAN normalised, QR-IBAN, QRR reference', $p?->iban === 'CH4431999123000889012' && $p->isQrIban
    && $p->referenceType === RecognizedPayment::REFERENCE_QRR && $p->reference === '210000000003139471430009017');
check('V4 gross 1\'081.00 as Money CHF 108100', $p?->amount?->minor === 108100 && $p->amount->currency === 'CHF' && $data->grossTotal() === $p->amount);
check('V5 creditor name / address / postcode / town / country', $p?->creditorName === 'Muster AG' && $p->creditorAddress === ['Musterstrasse 1']
    && $p->creditorPostalCode === '8000' && $p->creditorTown === 'Zürich' && $data->creditorCountry === 'CH');
check('V6 UID canonical, invoice number, dates', $data?->uid === 'CHE-109.322.551' && $data->invoiceNumber === 'RE-2026-0815'
    && $data->invoiceDate?->format('Y-m-d') === '2026-11-01' && $data->dueDate?->format('Y-m-d') === '2026-12-01');
check('V7 VAT lines as Money (a 0 % line keeps 0.00)', count($data?->vatLines ?? []) === 2 && $data->vatLines[0]['rate'] === '8.1'
    && $data->vatLines[0]['vat']?->minor === 8100 && $data->vatLines[1]['vat']?->minor === 0);
check('V8 line text and category in words', $data?->lineText === 'Druckerpatronen November 2026' && $data->expenseCategory === 'Büromaterial');
check('V9 no warnings, confidence medium (never high from the AI), usable', $data?->warnings === [] && $p?->confidence() === RecognizedPayment::CONFIDENCE_MEDIUM && $p->isUsable());
check('V10 the model that answered is kept', $data?->model === 'claude-opus-5-5');

resetCap();
$bad = validAnswer([
    'iban'           => 'CH44 3199 9123 0008 8901 3',   // check digits wrong
    'creditor_uid'   => 'CHE-109.322.552',              // check digit wrong
    'invoice_date'   => '2026-02-30',
    'due_date'       => '2026-10-01',
    'gross_total'    => '12,50 €',
    'reference'      => 'RF00 1234',
    'line_text'      => str_repeat('Sehr langer Text ', 20),
    'creditor_country' => 'Schweiz',
    'vat_lines'      => [['rate' => 'acht', 'net' => '1', 'vat' => '1'], ['rate' => '8.1%', 'net' => '100.00', 'vat' => '-8.10']],
    'expense_category' => ['Büromaterial'],
]);
$t = new FakeTransport([message($bad)]);
$o = reader(['enabled' => true], $t)->read($pdf);
$d = $o->data;
$w = implode(' | ', $d?->warnings ?? []);
check('V11 partially invalid → still RECOGNIZED with what holds', $o->isRecognized() && $d?->payment->creditorName === 'Muster AG' && $d->invoiceNumber === 'RE-2026-0815');
check('V12 wrong IBAN check digits dropped + warning', $d?->payment->iban === null && str_contains($w, 'IBAN'));
check('V13 wrong UID check digit dropped + warning', $d?->uid === null && str_contains($w, 'UID'));
check('V14 impossible date dropped; due date kept (no invoice date to compare)', $d?->invoiceDate === null && $d->dueDate?->format('Y-m-d') === '2026-10-01' && str_contains($w, 'Rechnungsdatum'));
check('V15 «12,50 €» is no amount → dropped', $d?->payment->amount === null && str_contains($w, 'Gesamtbetrag'));
check('V16 invalid reference → reference and type dropped', $d?->payment->reference === null && $d->payment->referenceType === null && str_contains($w, 'Referenz'));
check('V17 too long text dropped, never cut', $d?->lineText === null && str_contains($w, 'Buchungstext'));
check('V18 «Schweiz» is no ISO country → dropped', $d?->creditorCountry === null && str_contains($w, 'Land'));
check('V19 VAT: a line without valid rate dropped, «8.1%» taken, a negative amount dropped', count($d?->vatLines ?? []) === 1
    && $d->vatLines[0]['rate'] === '8.1' && $d->vatLines[0]['net']?->minor === 10000 && $d->vatLines[0]['vat'] === null, json_encode($d?->vatLines));
check('V20 a wrong type (list for a text) dropped + warning', $d?->expenseCategory === null && str_contains($w, 'Aufwandart'));
check('V21 warnings reach the payment → confidence low, not usable', $d?->payment->confidence() === RecognizedPayment::CONFIDENCE_LOW && !$d->payment->isUsable());

$m = (new AiInvoiceMapper())->map(validAnswer(['due_date' => '2026-10-01']), 'm');
check('V22 due date before the invoice date dropped', $m->dueDate === null && str_contains(implode(' ', $m->warnings), 'Fälligkeit'));
$m = (new AiInvoiceMapper())->map(validAnswer(['currency' => null]), 'm');
check('V23 amount without currency dropped (as the text layer does)', $m->payment->amount === null && str_contains(implode(' ', $m->warnings), 'ohne Währung'));
$m = (new AiInvoiceMapper())->map(validAnswer(['iban' => 'CH93 0076 2011 6238 5295 7', 'reference_type' => 'QRR', 'reference' => 'RF18 5390 0754 7034']), 'm');
check('V24 SCOR re-derived from the reference; the claimed type is corrected with a warning', $m->payment->referenceType === RecognizedPayment::REFERENCE_SCOR
    && $m->payment->reference === 'RF18539007547034' && !$m->payment->isQrIban && str_contains(implode(' ', $m->warnings), 'passt nicht'));
$m = (new AiInvoiceMapper())->map(validAnswer(['iban' => 'DE89 3704 0044 0532 0130 00', 'currency' => 'eur', 'reference_type' => null, 'reference' => null, 'creditor_uid' => null, 'creditor_country' => 'DE']), 'm');
check('V25 a foreign invoice: DE IBAN, EUR, no reference, no UID', $m->payment->iban === 'DE89370400440532013000' && $m->payment->amount?->currency === 'EUR'
    && $m->payment->reference === null && $m->uid === null && $m->warnings === []);
$m = (new AiInvoiceMapper())->map(validAnswer(['creditor_name' => "Muster\u{0000} AG\n<script>"]), 'm');
check('V26 control characters removed; markup stays plain text (the view escapes)', $m->payment->creditorName === 'Muster AG <script>');

echo "F  failures are outcomes\n";

$cases = [
    'F1 not JSON'                 => message('Hier sind die Angaben: Muster AG …'),
    'F2 a JSON list'              => message('[1,2,3]'),
    'F3 a JSON object of no field' => message(['foo' => 'bar']),
    'F4 refusal'                  => message('', 'refusal'),
    'F5 max_tokens (cut answer)'  => message('{"creditor_name": "Mus', 'max_tokens'),
    'F6 all null (nothing read)'  => message(array_fill_keys(AiInvoiceMapper::FIELDS, null)),
    'F7 HTTP 500'                 => new HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"Internal"}}'),
    'F8 HTTP 429'                 => new HttpResult(429, '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'),
    'F9 HTTP 401'                 => new HttpResult(401, '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'),
    'F10 HTTP 404 (retired model)' => new HttpResult(404, '{"type":"error","error":{"type":"not_found_error","message":"model"}}'),
    'F11 timeout'                 => new HttpResult(0, '', 'Operation timed out after 120001 milliseconds'),
    'F12 a non-message 200'       => new HttpResult(200, '<html>proxy</html>'),
    'F13 the transport throws'    => static function (): HttpResult { throw new \RuntimeException('socket'); },
];
foreach ($cases as $label => $response) {
    resetCap();
    $t = new FakeTransport([$response]);
    try {
        $o = reader(['enabled' => true], $t)->read($pdf, null, 'intake-7');
        check("{$label} → FAILED «" . AiOutcome::MANUAL . '»', $o->state === AiOutcome::FAILED && $o->reason === AiOutcome::MANUAL
            && $o->data === null && (string) $o->detail !== '', $o->state . ' ' . $o->detail);
    } catch (\Throwable $e) {
        check("{$label} → no exception", false, get_class($e) . ': ' . $e->getMessage());
    }
}
check('F14 timeout detail names the connection', str_contains((string) (reader(['enabled' => true], new FakeTransport([new HttpResult(0, '', 'timed out')])))->read($pdf)->detail, 'Zeitüberschreitung'));
resetCap();

echo "L  the log line\n";

$log = logText();
check('L1 log lines written (one per attempt) with ref, outcome, model, tokens', str_contains($log, 'ref=intake-42 outcome=recognized code=ok model=claude-opus-5-5')
    && str_contains($log, 'in=2345 out=321'));
check('L2 failures logged with a code, not a message', str_contains($log, 'code=refusal') && str_contains($log, 'code=rate_limit_error')
    && str_contains($log, 'code=connection_error') && str_contains($log, 'code=daily_cap') && str_contains($log, 'code=too_large'));
check('L3 never the key', !str_contains($log, SECRET) && !str_contains($log, 'x-api-key'));
check('L4 never the PDF, its text or an answer value', !str_contains($log, 'MUSTER-PDF-CONTENT') && !str_contains($log, base64_encode($pdf))
    && !str_contains($log, 'Total CHF') && !str_contains($log, 'Muster AG') && !str_contains($log, 'Druckerpatronen') && !str_contains($log, 'CH44'));
check('L5 never an API error message', !str_contains($log, 'invalid x-api-key') && !str_contains($log, 'slow down') && !str_contains($log, 'socket'));
$o = reader(['enabled' => true], new FakeTransport([message(validAnswer())]))->read($pdf);
check('L6 the outcome carries no key', !str_contains(print_r($o, true), SECRET));

echo "S  source rules\n";

$dir = __DIR__ . '/../packages/module-creditor/src/Recognition/Ai';
$src = '';
foreach (glob($dir . '/*.php') ?: [] as $file) {
    $src .= file_get_contents($file);
}
check('S1 no superglobal in Recognition/Ai', preg_match('/\$_(SERVER|GET|POST|REQUEST|COOKIE|FILES|ENV|SESSION)\b/', $src) === 0);
check('S2 no network code of its own (curl / sockets / streams) — only kibot\'s transport', preg_match('/\b(curl_init|fsockopen|stream_socket_client|file_get_contents\s*\(\s*[\'"]https?:)/', $src) === 0);
// The only ß allowed is the prompt's «never ß» instruction to the model.
$reasons = (string) preg_replace('/never ß/u', '', $src);
check('S3 the privacy sentence names the PDF and the USA; Swiss spelling in every German text', str_contains(AiInvoiceReader::PRIVACY_NOTE, 'PDF')
    && str_contains(AiInvoiceReader::PRIVACY_NOTE, 'USA') && !str_contains($reasons, 'ß'));

// ── cleanup ───────────────────────────────────────────────────────────
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $entry) {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
}
@rmdir($tmp);

echo "\ncreditor-ai: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
