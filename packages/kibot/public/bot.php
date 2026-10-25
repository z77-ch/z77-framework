<?php

declare(strict_types=1);

/**
 * Standalone endpoint of KiBot — for a host without a framework.
 *
 *   GET  bot.php?config  -> widget configuration + CSRF token (JSON)
 *   POST bot.php         -> {messages, token, website} -> answer as SSE:
 *                           data: {"text": "..."} ... data: {"error": "..."} ... data: [DONE]
 *
 * Configuration: the PHP file named in the environment variable KIBOT_CONFIG, else
 * ../kibot.config.php; it returns the Config overrides (see kibot.config.example.php).
 * Relative paths in it resolve against the package root.
 *
 * A framework adapter does the same with its own request, session and CSRF; this file
 * is the reference for what has to happen in which order.
 */

use Z77\KiBot\ArraySession;
use Z77\KiBot\Bot;
use Z77\KiBot\Config;
use Z77\KiBot\Guard;
use Z77\KiBot\GuardException;
use Z77\KiBot\Log;

$baseDir = dirname(__DIR__);
require is_file($baseDir . '/vendor/autoload.php') ? $baseDir . '/vendor/autoload.php' : $baseDir . '/autoload.php';

$configFile = getenv('KIBOT_CONFIG') ?: $baseDir . '/kibot.config.php';
$config     = new Config(is_file($configFile) ? (array) require $configFile : []);

$https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
session_name('kibot');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $https, 'path' => '/']);
session_start();

$session = new ArraySession($_SESSION);
$guard   = new Guard($config, $session, $config->path('rateDir', $baseDir));
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ── GET ?config ─────────────────────────────────────────────────────────────
if ($method === 'GET' && isset($_GET['config'])) {
    $widget          = $config->widget();
    $widget['token'] = $guard->issueToken();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($widget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

// ── POST: ask ───────────────────────────────────────────────────────────────
ignore_user_abort(true); // finish and log even when the visitor closes the tab
set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/event-stream; charset=utf-8');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$emit = static function (array|string $data): void {
    echo 'data: ' . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n\n";
    flush();
};
$fail = static function (string $key) use ($emit, $config): never {
    $emit(['error' => $config->message($key)]);
    $emit('[DONE]');
    exit;
};

$maxBytes = (int) $config->get('limits.maxBodyBytes');
$raw      = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
if (strlen($raw) > $maxBytes) {
    $fail('invalidRequest');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    $fail('invalidRequest');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
try {
    $messages = $guard->check($body, $ip);
} catch (GuardException $e) {
    $fail($e->key);
}

try {
    $bot = Bot::fromConfig($config, $baseDir);
} catch (\RuntimeException $e) { // topics file or source root missing
    (new Log($config, $config->path('logDir', $baseDir)))->error(0, $e->getMessage());
    $fail('genericError');
}

$previous = $session->get('kibotTopics');
$result   = $bot->ask($messages, is_array($previous) ? $previous : [], static function (string $text) use ($emit): void {
    $emit(['text' => $text]);
}, $ip);

// Topics of this answer, so a follow-up question can reuse them.
$session->set('kibotTopics', $result->topics);

if (!$result->ok()) {
    $emit(['error' => $result->error]);
}
$emit('[DONE]');
