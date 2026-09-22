<?php

/**
 * Uncaught-error status harness (CLI) — an error nobody caught answers HTTP 500,
 * never 200 (P2 exit check 2026-09-22: the first setup page of a fresh install
 * died with status 200).
 *
 * What is load-bearing here:
 *
 *   - the CONTROL case reproduces the defect: without a framework handler and
 *     with `display_errors` on (DEBUG), PHP itself answers an uncaught
 *     exception with 200;
 *   - ExceptionHandler::handleUncaught() (Bootstrap's process handler) answers
 *     500 with `display_errors` on AND off; off, the half-rendered page and the
 *     message stay out of the body (no internals, no half page);
 *   - the DEBUG handler (setOwnExceptionHandler) answers 500 and still prints
 *     its box;
 *   - a real fatal (compile error) answers 500 through handleShutdown(), with
 *     `display_errors` on — best effort, needs output buffering like
 *     php.ini-development/-production ship (4096);
 *   - the DEBUG handler on a stateless route (/api) answers the JSON envelope,
 *     not its HTML box — the API contract does not change with DEBUG;
 *   - a real fatal with `display_errors` off gets the same generic body as
 *     the exception path, without the half-rendered page;
 *   - TemplateRenderer closes every buffer down to the level it found when a
 *     template throws — its own and any the template opened.
 *
 * Runs each case in PHP's built-in web server (a real SAPI with real status
 * codes). Run: php tests/uncaught-error-status.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-uncaught-' . getmypid();
@mkdir($work, 0777, true);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// ── the router the built-in server runs: one case per ?case= ────────────────
$router = <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => '/packages/kernel/core/src/',
        'Z77\\Shared\\'      => '/packages/kernel/shared/src/',
        'Z77\\Persistence\\' => '/packages/kernel/persistence/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = getenv('Z77_ROOT') . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
            return;
        }
    }
});
use Z77\Core\Exception\ExceptionHandler;

switch ($_GET['case'] ?? '') {
    case 'control':                 // PHP default, DEBUG-like display_errors
        ini_set('display_errors', '1');
        throw new RuntimeException('SECRET-MESSAGE');

    case 'handler-display':
        ini_set('display_errors', '1');
        set_exception_handler([ExceptionHandler::class, 'handleUncaught']);
        throw new RuntimeException('SECRET-MESSAGE');

    case 'handler-production':
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        set_exception_handler([ExceptionHandler::class, 'handleUncaught']);
        ob_start();
        echo 'HALF-PAGE';
        throw new RuntimeException('SECRET-MESSAGE');

    case 'debug-handler':
        ini_set('display_errors', '1');
        require getenv('Z77_ROOT') . '/packages/kernel/core/src/autoload/debug/php/Functions.php';
        setOwnExceptionHandler();
        throw new RuntimeException('SECRET-MESSAGE');

    case 'debug-stateless':         // DEBUG on /api: must stay the envelope
        ini_set('display_errors', '1');
        $request = (new ReflectionClass(Z77\Core\Http\Request::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Z77\Core\Http\Request::class, 'statelessRoute'))->setValue($request, true);
        Z77\Core\DI::getInstance(true)->set('Request', $request, true);
        require getenv('Z77_ROOT') . '/packages/kernel/core/src/autoload/debug/php/Functions.php';
        setOwnExceptionHandler();
        throw new RuntimeException('SECRET-MESSAGE');

    case 'fatal-production':
        ini_set('display_errors', '0');
        ini_set('log_errors', '0');
        register_shutdown_function([ExceptionHandler::class, 'handleShutdown']);
        ob_start();
        echo 'HALF-PAGE';
        function z77_twice() {}
        require getenv('Z77_FATAL');
        break;

    case 'fatal':
        ini_set('display_errors', '1');
        register_shutdown_function([ExceptionHandler::class, 'handleShutdown']);
        function z77_twice() {}
        require getenv('Z77_FATAL');   // redeclares z77_twice() → E_COMPILE_ERROR
        break;
}
echo 'unreachable';
PHP;
file_put_contents($work . '/router.php', $router);
file_put_contents($work . '/fatal.php', "<?php\nfunction z77_twice() {}\n");

// ── start the server on a free port ─────────────────────────────────────────
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$env = array_merge(getenv(), ['Z77_ROOT' => $root, 'Z77_FATAL' => $work . '/fatal.php']);
$cmd = [PHP_BINARY, '-d', 'output_buffering=4096', '-S', "127.0.0.1:{$port}", $work . '/router.php'];
$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'w'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $work, $env);

$get = function (string $case) use ($port): array {
    $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}/?case={$case}", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; }
    }
    return [$status, (string)$body];
};

$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);

if ($up) {
    echo "Control (the defect)\n";
    [$st] = $get('control');
    check('PHP default + display_errors on answers 200 — why the handler exists', $st === 200, (string)$st);

    echo "ExceptionHandler::handleUncaught\n";
    [$st, $body] = $get('handler-display');
    check('display_errors on → 500', $st === 500, (string)$st);
    check('display_errors on → message shown', str_contains($body, 'SECRET-MESSAGE'));

    [$st, $body] = $get('handler-production');
    check('display_errors off → 500', $st === 500, (string)$st);
    check('display_errors off → no message in the body', !str_contains($body, 'SECRET-MESSAGE'), $body);
    check('display_errors off → half-rendered page dropped', !str_contains($body, 'HALF-PAGE'), $body);

    echo "DEBUG handler\n";
    [$st, $body] = $get('debug-handler');
    check('setOwnExceptionHandler → 500', $st === 500, (string)$st);
    check('setOwnExceptionHandler → box still printed', str_contains($body, 'SECRET-MESSAGE'));

    echo "DEBUG handler on a stateless route\n";
    [$st, $body] = $get('debug-stateless');
    $json = json_decode($body, true);
    check('stateless + DEBUG → 500', $st === 500, (string)$st);
    check('stateless + DEBUG → JSON envelope, not the HTML box', ($json['error']['code'] ?? null) === 'internal' && !str_contains($body, 'Fatal Error'), $body);
    check('stateless + DEBUG → no message in the envelope', !str_contains($body, 'SECRET-MESSAGE'), $body);

    echo "Real fatal\n";
    [$st, $body] = $get('fatal-production');
    check('compile error + display_errors off → 500', $st === 500, (string)$st);
    check('compile error + display_errors off → generic body, half page dropped', str_contains($body, '<h1>500</h1>') && !str_contains($body, 'HALF-PAGE'), $body);
    [$st] = $get('fatal');
    check('compile error + display_errors on → 500 (handleShutdown)', $st === 500, (string)$st);
}

proc_terminate($server);
proc_close($server);

// ── TemplateRenderer: no leaked buffer after a throwing template ────────────
echo "TemplateRenderer\n";
require $root . '/packages/kernel/core/src/Exception/ViewException.php';
require $root . '/packages/kernel/core/src/Services/TemplateRenderer.php';
file_put_contents($work . '/outer.tpl.php', "OUTER<?= \$this->render(\$inner) ?>");
file_put_contents($work . '/inner.tpl.php', "INNER<?php throw new RuntimeException('boom'); ?>");
$renderer = new Z77\Core\Services\TemplateRenderer();
$level    = ob_get_level();
$thrown   = false;
try {
    $renderer->render($work . '/outer.tpl.php', ['inner' => $work . '/inner.tpl.php']);
} catch (RuntimeException) {
    $thrown = true;
}
check('the template error reaches the caller', $thrown);
check('no output buffer left open (nested templates)', ob_get_level() === $level, (string)ob_get_level());

file_put_contents($work . '/own-buffer.tpl.php', "<?php ob_start(); ob_start(); echo 'X'; throw new RuntimeException('boom'); ?>");
try {
    $renderer->render($work . '/own-buffer.tpl.php');
} catch (RuntimeException) {
}
check("a template's own open buffers are closed too", ob_get_level() === $level, (string)ob_get_level());

// ── cleanup ─────────────────────────────────────────────────────────────────
foreach (glob($work . '/*') as $f) { @unlink($f); }
@rmdir($work);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
