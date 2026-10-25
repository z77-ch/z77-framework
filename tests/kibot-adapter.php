<?php

/**
 * KiBot z77 adapter harness (CLI) — the kernel's KibotEndpoint, KibotSession
 * (Z77\Core\Kibot, shared with the backend assistant — tests/kibot-assistant.php)
 * and module-frontend's KibotController over the standalone package, without
 * network and key.
 *
 * What is load-bearing here:
 *
 *   - OFF unless configured: no `config/client/kibot.inc.php` → fromConfig() null
 *     (the controller turns that into a 404); a file that returns no array throws;
 *   - path resolution against the PROJECT ROOT: sourceRoot default `shared/kibot`,
 *     topics `<sourceRoot>/topics.json`, logs `logs/kibot`, an absolute keyFile kept;
 *   - ONE token: the config action hands out z77's CsrfService token, Guard reads
 *     the same token through KibotSession; a wrong token is a clean SSE error and
 *     never reaches the API; a write to Guard's token key throws;
 *   - the full ask over a fake transport: text frames, [DONE], topics of the
 *     answer stored in the z77 session for the follow-up question;
 *   - no key file configured → the `noApiKey` text as SSE error plus a log line;
 *   - the controller keeps the rules: Fetch-only, typed helpers (json / void),
 *     no superglobals.
 *
 * Run: php tests/kibot-adapter.php
 * No DB, no composer install, no API key, no network. Throwaway dir in the system temp.
 */

declare(strict_types=1);

$work = sys_get_temp_dir() . '/z77-kibot-adapter-' . bin2hex(random_bytes(4));
mkdir($work, 0777, true);
define('ABS_BASE_PATH', $work);

require __DIR__ . '/../packages/kibot/autoload.php';
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'            => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'          => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Module\\Frontend\\' => __DIR__ . '/../packages/module-frontend/src/',
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

use Z77\Core\Http\Security\CsrfService;
use Z77\Core\Session\SessionManager;
use Z77\KiBot\Guard;
use Z77\KiBot\HttpResult;
use Z77\KiBot\Transport;
use Z77\Core\Kibot\KibotEndpoint;
use Z77\Core\Kibot\KibotSession;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

final class FakeTransport implements Transport
{
    public array $requests = [];
    /** @var list<HttpResult> */
    public array $queue = [];

    public function send(string $url, array $headers, string $body, ?callable $onChunk = null): HttpResult
    {
        $this->requests[] = json_decode($body, true);
        $result = array_shift($this->queue) ?? new HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"queue empty"}}');
        if ($onChunk !== null && $result->status !== 0) {
            foreach (str_split($result->body, 7) as $chunk) {
                $onChunk($chunk);
            }
        }
        return $result;
    }
}

function sse(array $events): string
{
    $out = '';
    foreach ($events as $e) {
        $out .= 'event: ' . $e['type'] . "\n" . 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    return $out;
}

function sseAnswer(array $pieces): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'model' => 'claude-sonnet-5-5', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 900, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
    ];
    foreach ($pieces as $p) {
        $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $p]];
    }
    $events[] = ['type' => 'content_block_stop', 'index' => 0];
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 42]];
    $events[] = ['type' => 'message_stop'];
    return sse($events);
}

function routingToTopics(array $ids): HttpResult
{
    return new HttpResult(200, json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lies_themen', 'input' => ['ids' => $ids]],
        ],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
    ], JSON_UNESCAPED_UNICODE));
}

/** Runs one ask and returns the decoded frames (arrays, or the string "[DONE]"). */
function ask(KibotEndpoint $endpoint, array $body, KibotSession $session): array
{
    $frames = [];
    $endpoint->ask($body, '198.51.100.7', $session, static function (string $frame) use (&$frames): void {
        check('frame is one complete SSE data line', str_starts_with($frame, 'data: ') && str_ends_with($frame, "\n\n"));
        $raw      = substr($frame, 6, -2);
        $frames[] = $raw === '[DONE]' ? '[DONE]' : json_decode($raw, true);
    });
    return $frames;
}

function rmTree(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

$_SESSION = [];
$sessionManager = new SessionManager();      // CLI: no session_start, plain $_SESSION
$csrf           = new CsrfService($sessionManager);
$session        = new KibotSession($sessionManager, $csrf);

// ── off unless configured ───────────────────────────────────────────────────
echo "configuration\n";
check('no config/client/kibot.inc.php → bot off (null)', KibotEndpoint::fromConfig() === null);

mkdir($work . '/config/client', 0777, true);
file_put_contents($work . '/config/client/kibot.inc.php', "<?php\nreturn 'nope';\n");
try {
    KibotEndpoint::fromConfig();
    check('config file returning no array throws', false);
} catch (RuntimeException) {
    check('config file returning no array throws', true);
}

// Project config: key path absolute (outside the project), everything else default.
$keyDir = $work . '-keys';
mkdir($keyDir, 0777, true);
file_put_contents($keyDir . '/anthropic.key', 'sk-test-FAKE-' . bin2hex(random_bytes(8)) . "\n"); // not a real key
$keyPath = str_replace('\\', '/', $keyDir) . '/anthropic.key';
file_put_contents($work . '/config/client/kibot.inc.php', "<?php\nreturn " . var_export([
    'paths'  => ['keyFile' => $keyPath],
    'widget' => ['botName' => 'Test-Assistent', 'welcomeMsg' => 'Grüezi'],
], true) . ";\n");

mkdir($work . '/shared/kibot/kontakt', 0777, true);
file_put_contents($work . '/shared/kibot/kontakt/zeiten.md', "# Zeiten\nMontag bis Freitag 08:00 bis 17:30.");
file_put_contents($work . '/shared/kibot/topics.json', json_encode(['version' => 1, 'themen' => [
    ['id' => 'kontakt', 'titel' => 'Öffnungszeiten', 'beschreibung' => 'Wann offen ist.', 'quellen' => ['kontakt/zeiten.md']],
]], JSON_UNESCAPED_UNICODE));

$endpoint = KibotEndpoint::fromConfig();
$base     = rtrim(str_replace('\\', '/', $work), '/');
check('configured → endpoint', $endpoint instanceof KibotEndpoint);
check('sourceRoot defaults to <project>/shared/kibot', $endpoint->path('sourceRoot') === $base . '/shared/kibot');
check('topicsFile defaults to <sourceRoot>/topics.json', $endpoint->path('topicsFile') === $base . '/shared/kibot/topics.json');
check('logDir defaults to <project>/logs/kibot', $endpoint->path('logDir') === $base . '/logs/kibot');
check('rate dir under the log dir', $endpoint->path('rateDir') === $base . '/logs/kibot/rate');
check('absolute keyFile kept as given', $endpoint->path('keyFile') === $keyPath);
check('a relative path resolves against the project root', (new KibotEndpoint(new Z77\KiBot\Config(['paths' => ['sourceRoot' => 'data/kibot']]), $base))->path('sourceRoot') === $base . '/data/kibot');

// ── config action: widget texts + the z77 token ─────────────────────────────
echo "config action\n";
$token  = $csrf->getToken();
$widget = $endpoint->widget($token);
check('widget carries the z77 CSRF token', ($widget['token'] ?? '') === $token && strlen($token) === 64);
check('widget carries the project texts', $widget['botName'] === 'Test-Assistent' && $widget['welcomeMsg'] === 'Grüezi');
check('widget carries defaults for the rest', $widget['sendLabel'] === 'Senden' && isset($widget['contactCtas'], $widget['maxLength']));
check('widget never shows paths, models or the key', !isset($widget['paths']) && !isset($widget['api'])
    && !str_contains(json_encode($widget), 'anthropic.key'));

// ── one token for AccessGuard and Guard ─────────────────────────────────────
echo "session + CSRF\n";
check('KibotSession reads Guard\'s token key as the z77 token', $session->get(Guard::TOKEN_KEY) === $token);
try {
    $session->set(Guard::TOKEN_KEY, 'other');
    check('writing Guard\'s token key throws (no second token)', false);
} catch (LogicException) {
    check('writing Guard\'s token key throws (no second token)', true);
}
$session->set('kibotProbe', 7);
check('other keys live in the z77 session', $sessionManager->get('kibotProbe') === 7);

$t        = new FakeTransport();
$endpoint = new KibotEndpoint($endpoint->config(), $base, $t);
$question = ['messages' => [['role' => 'user', 'content' => 'Wann habt ihr offen?']], 'token' => 'wrong', 'website' => ''];
$frames   = ask($endpoint, $question, $session);
check('bad CSRF token → sessionExpired as SSE error', ($frames[0]['error'] ?? '') === $endpoint->config()->message('sessionExpired'));
check('bad CSRF token → stream ends with [DONE]', end($frames) === '[DONE]' && count($frames) === 2);
check('bad CSRF token never reaches the API', $t->requests === []);

$frames = ask($endpoint, [], $session);
check('empty / unreadable body → invalidRequest', ($frames[0]['error'] ?? '') === $endpoint->config()->message('invalidRequest'));

$frames = ask($endpoint, ['messages' => $question['messages'], 'token' => $token, 'website' => 'http://spam'], $session);
check('honeypot filled → invalidRequest', ($frames[0]['error'] ?? '') === $endpoint->config()->message('invalidRequest'));

// ── full ask over the fake transport ────────────────────────────────────────
echo "ask\n";
$t->queue = [routingToTopics(['kontakt']), new HttpResult(200, sseAnswer(['Montag bis ', 'Freitag offen.']))];
$frames   = ask($endpoint, ['messages' => $question['messages'], 'token' => $token, 'website' => ''], $session);
$text     = implode('', array_map(static fn ($f) => is_array($f) ? ($f['text'] ?? '') : '', $frames));
check('valid token → answer streamed as text frames', $text === 'Montag bis Freitag offen.');
check('no error frame on success', array_filter($frames, static fn ($f) => is_array($f) && isset($f['error'])) === []);
check('stream ends with [DONE]', end($frames) === '[DONE]');
check('two API calls (routing + answer)', count($t->requests) === 2);
check('the source from <project>/shared/kibot reached the answer call', str_contains(json_encode($t->requests[1]), 'Montag bis Freitag 08:00'));
check('topics of the answer stored in the z77 session', $sessionManager->get(KibotEndpoint::TOPICS_KEY) === ['kontakt']);
check('rate counter in the z77 session', ($sessionManager->get(Guard::RATE_KEY)['count'] ?? 0) >= 1);
check('conversation log under <project>/logs/kibot', glob($base . '/logs/kibot/kibot-chat_*.jsonl') !== []);

// ── no key file configured ──────────────────────────────────────────────────
echo "no key\n";
$t        = new FakeTransport();
$noKey    = new KibotEndpoint(new Z77\KiBot\Config([]), $base, $t);
$frames   = ask($noKey, ['messages' => $question['messages'], 'token' => $token, 'website' => ''], $session);
check('no keyFile → noApiKey as SSE error', ($frames[0]['error'] ?? '') === $noKey->config()->message('noApiKey'));
check('no keyFile → [DONE]', end($frames) === '[DONE]');
check('no keyFile → nothing sent', $t->requests === []);
$errorLog = (string) @file_get_contents($base . '/logs/kibot/kibot-api-error.log');
check('no keyFile → cause in the error log', str_contains($errorLog, 'paths.keyFile'));

// ── controller keeps the rules ──────────────────────────────────────────────
echo "controller\n";
$src = (string) file_get_contents(__DIR__ . '/../packages/module-frontend/src/Ui/Controllers/Main/KibotController.php');
check('config action: Fetch GET, json() helper', str_contains($src, "#[Fetch, HttpMethod('GET')]") && str_contains($src, 'return $this->json('));
check('ask action: Fetch POST, void() helper', str_contains($src, "#[Fetch, HttpMethod('POST')]") && str_contains($src, 'return $this->void();'));
check('controller: no superglobals, no direct response', preg_match('/\$_(SERVER|POST|GET|SESSION)|new \w*Response\(/', $src) === 0);
check('controller: unconfigured → NotFoundException', str_contains($src, "throw new NotFoundException('KiBot is not configured.')"));

$js = (string) file_get_contents(__DIR__ . '/../packages/kibot/res/assets/js/bot.js');
check('bot.js sends the token as X-CSRF-Token (AccessGuard)', str_contains($js, "'X-CSRF-Token': state.csrfToken"));
check('bot.js: config URL configurable (data-config)', str_contains($js, "getAttribute('data-config')"));

rmTree($work);
rmTree($keyDir);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
