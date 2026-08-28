<?php

/**
 * Geo-guard harness (CLI) — the country gate and the form log inside
 * PublicFormHandler, against a real file store and a real (hand-built) MMDB.
 *
 * What is load-bearing here:
 *
 *   - the GATE POSITION: after the bot trap, before validation — a bot wins
 *     over the country rule, a blocked country wins over a validation error;
 *   - visible vs. silent: the default shows the send error (a customer must
 *     be able to notice), silent behaves like the bot path (PRG, no signal);
 *   - FAIL OPEN in every unknown state: empty list, unknown IP, no database;
 *   - ONE ip read and ONE lookup per submit, shared by gate and log line —
 *     verified by swapping REMOTE_ADDR mid-dispatch and asserting the line
 *     still carries the original;
 *   - the log line: guard off → no line; guard on → a line for EVERY outcome,
 *     with the country even while the blocklist is empty (the evidence a
 *     block is later decided from), `identity` only where the definition
 *     opts in, `extra` and the flow's note() riding along.
 *
 * The MMDB fixture is built by hand (IPv4-only, record_size 24): a chain of
 * tree nodes per /32 entry, the 16-byte gap, one country record per ISO code,
 * the magic marker, and a metadata map. MmdbReader is the integrity check of
 * the update job, so the fixture doubles as a reader test.
 *
 * Run: php tests/form-geo-guard.php
 * Uses a throwaway data directory in the system temp; removed on success.
 */

$work = sys_get_temp_dir() . '/z77-form-geo-guard-' . getmypid();
@mkdir($work . '/data/framework/forms', 0777, true);
define('ABS_BASE_PATH', $work);

// Minimal PSR-4 autoloader over the packages — the harnesses run without a
// composer install (see tests/country-blocklist.php).
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'      => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => __DIR__ . '/../packages/kernel/persistence/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Session\SessionManager;
use Z77\Shared\Forms\CountryBlocklist;
use Z77\Shared\Forms\FormDefinition;
use Z77\Shared\Forms\FormLog;
use Z77\Shared\Forms\PublicFormHandler;
use Z77\Shared\GeoIp\CountryLookup;

// ── DI doubles ──────────────────────────────────────────────────────────────

class FakeRequest
{
    public array $post = [];
    public function isReadMethod(): bool { return false; }
    public function getPostParameters(): array { return $this->post; }
    public function getPostParameter(string $key): mixed { return $this->post[$key] ?? null; }
    // viewContext() derives the blur-check URL from the current route.
    public function getModule(): string { return 'test'; }
    public function getGroup(): string { return 'main'; }
    public function getController(): string { return 'form'; }
}

class FakeCsrf
{
    public bool $ok = true;
    public function validate(string $token): bool { return $this->ok; }
}

class FakeTranslator
{
    public function t(string $key): string { return $key; }
}

$request = new FakeRequest();
$csrf    = new FakeCsrf();

DI::getInstance()->set('CacheManager', new CacheManager(), true);
DI::getInstance()->set('SessionManager', new SessionManager(), true); // CLI: no real session
DI::getInstance()->set('Request', $request, true);
DI::getInstance()->set('CsrfService', $csrf, true);
DI::getInstance()->set('Translator', new FakeTranslator(), true);

// ── form definitions ────────────────────────────────────────────────────────

class PlainFormDefinition extends FormDefinition
{
    public function formKey(): string { return 'geoPlain'; }
    public function fields(): array
    {
        return ['email' => ['label' => 'E-Mail', 'rules' => ['required' => true, 'email' => true]]];
    }
}

class IdentityFormDefinition extends PlainFormDefinition
{
    public function formKey(): string { return 'geoIdentity'; }
    public function identityField(): ?string { return 'email'; }
}

// ── MMDB fixture (IPv4-only, record_size 24) ────────────────────────────────

/** @param array<string,string> $entries ip => ISO code */
function mmdbBuild(array $entries): string
{
    // Data section: one {"country":{"iso_code":XX}} record per distinct code.
    $data = '';
    $dataOffset = [];
    foreach (array_unique(array_values($entries)) as $iso) {
        $dataOffset[$iso] = strlen($data);
        $data .= "\xE1" . chr(0x40 | 7) . 'country'
               . "\xE1" . chr(0x40 | 8) . 'iso_code' . chr(0x40 | 2) . $iso;
    }

    // Search tree: a trie over the 32 address bits, one node per prefix.
    $nodes = [[null, null]];
    foreach ($entries as $ip => $iso) {
        $packed = inet_pton($ip);
        $cur = 0;
        for ($i = 0; $i < 32; $i++) {
            $bit = (ord($packed[$i >> 3]) >> (7 - ($i % 8))) & 1;
            if ($i === 31) {
                $nodes[$cur][$bit] = ['data', $iso];
            } else {
                if ($nodes[$cur][$bit] === null) {
                    $nodes[] = [null, null];
                    $nodes[$cur][$bit] = ['node', count($nodes) - 1];
                }
                $cur = $nodes[$cur][$bit][1];
            }
        }
    }

    $n = count($nodes);
    // A record: child node | $n (no data) | $n + 16 + dataOffset (spec: the
    // two 16s cancel in the reader, so this lands at dataStart + offset).
    $record = static function (?array $cell) use ($n, $dataOffset): string {
        $value = $cell === null ? $n
            : ($cell[0] === 'node' ? $cell[1] : $n + 16 + $dataOffset[$cell[1]]);
        return substr(pack('N', $value), 1); // 3 bytes, big-endian
    };

    $tree = '';
    foreach ($nodes as $node) {
        $tree .= $record($node[0]) . $record($node[1]);
    }

    $meta = "\xE5"
        . chr(0x40 | 10) . 'node_count'    . "\xA2" . pack('n', $n)
        . chr(0x40 | 11) . 'record_size'   . "\xA2" . pack('n', 24)
        . chr(0x40 | 10) . 'ip_version'    . "\xA2" . pack('n', 4)
        . chr(0x40 | 11) . 'build_epoch'   . "\x04\x02" . pack('N', 1735689600)
        . chr(0x40 | 13) . 'database_type' . chr(0x40 | 12) . 'Test-Country';

    return $tree . str_repeat("\x00", 16) . $data
         . "\xAB\xCD\xEF" . 'MaxMind.com' . $meta;
}

$mmdbFile = $work . '/test-country.mmdb';
file_put_contents($mmdbFile, mmdbBuild([
    '203.0.113.5'  => 'RU',
    '198.51.100.7' => 'CH',
]));
CountryLookup::useFile($mmdbFile);

// ── plumbing ────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/**
 * One submit through a fresh handler (the handler is per-request). Arms the
 * time trap in the past so the bot check passes unless the test trips it.
 *
 * @return array{0: bool, 1: PublicFormHandler}
 */
function submit(
    FormDefinition $definition,
    array $post,
    ?callable $configure = null,
    ?callable $onValid = null,
): array {
    global $request;
    $request->post = $post + ['csrf_token' => 'x'];
    $_SESSION['formGuard.' . $definition->guardKey() . '.renderedAt'] = time() - 10;
    // The session rate limit (3 sends/h) is not under test here — reset it,
    // or the fourth scenario on the same guard key measures the limit
    // instead of the gate.
    $_SESSION['formGuard.' . $definition->guardKey() . '.sends'] = [];

    $handler = PublicFormHandler::create($definition);
    if ($configure !== null) {
        $configure($handler);
    }

    return [$handler->process($onValid ?? static fn(): bool => true), $handler];
}

function logLines(): array
{
    global $work;
    $file = $work . '/logs/form-' . date('Y-m') . '.jsonl';
    if (!is_file($file)) {
        return [];
    }

    return array_map(
        static fn(string $line): array => json_decode($line, true),
        file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    );
}

function clearLog(): void
{
    global $work;
    array_map('unlink', glob($work . '/logs/form-*.jsonl') ?: []);
}

$blocklist = CountryBlocklist::create();

// ── checks ──────────────────────────────────────────────────────────────────

echo "1. the fixture database answers (MmdbReader against a hand-built file)\n";
check("blocked-country IP resolves",   CountryLookup::of('203.0.113.5') === 'RU');
check("second entry resolves",         CountryLookup::of('198.51.100.7') === 'CH');
check("unknown IP answers null",       CountryLookup::of('192.0.2.9') === null);
check("available() sees the fixture",  CountryLookup::available());
check("build stamp is readable",       CountryLookup::databaseBuiltAt() === 1735689600);

echo "2. guard OFF → no line, ever\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch']);
check('the submit passes',   $ok === true);
check('no log file appears', logLines() === []);

echo "3. guard ON, blocklist EMPTY → a line WITH the country, nothing blocked\n";
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard());
$lines = logLines();
check('the submit passes',              $ok === true);
check('exactly one line',               count($lines) === 1);
check('outcome is sent',                ($lines[0]['outcome'] ?? '') === 'sent');
check('the line carries the country',   ($lines[0]['country'] ?? '') === 'RU');
check('the line carries the ip',        ($lines[0]['ip'] ?? '') === '203.0.113.5');
check('the form key is the guard key',  ($lines[0]['form'] ?? '') === 'geoPlain');
check('no identity without the opt-in', ($lines[0]['identity'] ?? null) === null);

echo "4. a blocked country is refused — visibly by default\n";
$blocklist->block('RU', 'Testgrund');
clearLog();
$validCalled = false;
[$ok, $handler] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard(),
    static function () use (&$validCalled): bool { $validCalled = true; return true; });
$lines = logLines();
check('process() returns false (re-render)', $ok === false);
check('the send error is shown',             ($handler->viewContext()['formError'] ?? '') === 'form.error.send');
check('the project callback never ran',      $validCalled === false);
check('outcome geo is logged',               ($lines[0]['outcome'] ?? '') === 'geo');

echo "5. gate position: after the bot trap, before validation\n";
clearLog();
[$ok] = submit(new PlainFormDefinition(), ['email' => 'kein-mail'],
    static fn($h) => $h->withGeoGuard());
check('invalid input from a blocked country reads geo, not invalid',
    (logLines()[0]['outcome'] ?? '') === 'geo');
check('…and the submit re-renders', $ok === false);
clearLog();
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch', 'website' => 'spam'],
    static fn($h) => $h->withGeoGuard());
check('a tripped honeypot wins over the country rule',
    (logLines()[0]['outcome'] ?? '') === 'bot');
check('…and fakes success', $ok === true);

echo "6. silent guard behaves like the bot path\n";
clearLog();
[$ok, $handler] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard(silent: true));
check('process() returns true (PRG)', $ok === true);
check('outcome geo is logged',        (logLines()[0]['outcome'] ?? '') === 'geo');

echo "7. fail OPEN — unknown never blocks\n";
clearLog();
$_SERVER['REMOTE_ADDR'] = '192.0.2.9'; // not in the fixture
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard());
check('an unknown country passes',    $ok === true);
check('the line records country null', array_key_exists('country', logLines()[0]) && logLines()[0]['country'] === null);
CountryLookup::forget(); // no database at all
clearLog();
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard());
check('no database → even a listed country passes', $ok === true);
check('…and the line says country null', logLines()[0]['country'] === null);
CountryLookup::useFile($mmdbFile);

echo "8. an unblocked country passes the gate\n";
clearLog();
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard());
check('CH passes while RU is blocked', $ok === true);
check('outcome is sent',               (logLines()[0]['outcome'] ?? '') === 'sent');

echo "9. identity, extra and the flow's note ride on the line\n";
clearLog();
[$ok] = submit(new IdentityFormDefinition(), ['email' => 'kunde@beispiel.ch'],
    static fn($h) => $h->withGeoGuard(extra: ['origin' => 'angebot-x']));
$line = logLines()[0];
check('identityField() puts the address on the line', ($line['identity'] ?? '') === 'kunde@beispiel.ch');
check('extra rides on the line',                      ($line['origin'] ?? '') === 'angebot-x');
clearLog();
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard(),
    static function (): bool { FormLog::note('throttled'); return false; });
$line = logLines()[0];
check('a refused dispatch logs failed', ($line['outcome'] ?? '') === 'failed');
check("the flow's note explains it",    ($line['detail'] ?? '') === 'throttled');

echo "10. ONE ip read per submit — gate and line cannot disagree\n";
clearLog();
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard(),
    static function (): bool {
        // A dispatch that changes REMOTE_ADDR must not change the line: the
        // handler read the address once, before the gate.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        return true;
    });
$line = logLines()[0];
check('the line keeps the original ip',      ($line['ip'] ?? '') === '198.51.100.7');
check('the line keeps the original country', ($line['country'] ?? '') === 'CH');

echo "11. every outcome leaves a line — csrf included\n";
clearLog();
$csrf->ok = false;
[$ok] = submit(new PlainFormDefinition(), ['email' => 'a@b.ch'],
    static fn($h) => $h->withGeoGuard());
$csrf->ok = true;
check('csrf failure re-renders', $ok === false);
check('…and is logged',          (logLines()[0]['outcome'] ?? '') === 'csrf');

echo "\n";
echo $fail === 0
    ? "PASS — {$pass} checks\n"
    : "FAIL — {$fail} of " . ($pass + $fail) . " checks failed\n";

if ($fail === 0) {
    // Only on success: a failed run leaves the evidence in place.
    clearLog();
    @rmdir($work . '/logs');
    array_map('unlink', glob($work . '/data/framework/forms/*') ?: []);
    @rmdir($work . '/data/framework/forms');
    @rmdir($work . '/data/framework');
    @rmdir($work . '/data');
    @unlink($mmdbFile);
    @rmdir($work);
} else {
    echo "Arbeitsverzeichnis bleibt stehen: {$work}\n";
}

exit($fail === 0 ? 0 : 1);
