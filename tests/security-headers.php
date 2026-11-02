<?php

/**
 * Security headers on HTML answers (CLI, real stack in PHP's built-in server) — security.md
 * SEC-HEAD-001. What is load-bearing: every HTML path carries the enforced set and the observing CSP
 * exactly once (a page fresh from a controller, a page-cache miss, hit and 304, HEAD, the error
 * pages); the reset page's `no-referrer` is not weakened; a file answer keeps DeliveryPolicy's set
 * alone; HSTS is never sent; a project switches a header off, changes one or adds one in its one
 * file, and a broken file costs nothing.
 *
 * Run: php tests/security-headers.php
 */

use Z77\Core\Http\Response\SecurityHeaders;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "security-headers: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n";
    }
}

$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/z77-sec-headers-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});

// ── (A) the merge ───────────────────────────────────────────────────────────
echo "(A) the merge\n";
check('no project file: the defaults', SecurityHeaders::resolved([]) === SecurityHeaders::DEFAULTS);
check('the defaults hold no HSTS', !array_key_exists('Strict-Transport-Security', SecurityHeaders::DEFAULTS));
$r = SecurityHeaders::resolved(['X-Frame-Options' => null]);
check('null switches a header off', !isset($r['X-Frame-Options']) && isset($r['Referrer-Policy']));
$r = SecurityHeaders::resolved(['Referrer-Policy' => '']);
check('an empty value too', !isset($r['Referrer-Policy']));
$r = SecurityHeaders::resolved(['x-frame-options' => 'DENY']);
check('another spelling replaces, it does not add', ($r['x-frame-options'] ?? '') === 'DENY' && !isset($r['X-Frame-Options']));
$r = SecurityHeaders::resolved(['Strict-Transport-Security' => 'max-age=31536000']);
check('a project adds one', ($r['Strict-Transport-Security'] ?? '') === 'max-age=31536000' && count($r) === count(SecurityHeaders::DEFAULTS) + 1);
$headerLog = $base . '/headers.log';
ini_set('log_errors', '1');
ini_set('error_log', $headerLog);
$r = SecurityHeaders::resolved(['X-Frame-Options' => false, 'Referrer-Policy' => 42, 'Content-Security-Policy' => ['a']]);
check('a value neither string nor null keeps the default', ($r['X-Frame-Options'] ?? '') === 'SAMEORIGIN' && ($r['Referrer-Policy'] ?? '') === SecurityHeaders::DEFAULTS['Referrer-Policy'] && ($r['Content-Security-Policy'] ?? '') === SecurityHeaders::DEFAULTS['Content-Security-Policy'], json_encode($r));
$logged = (string) @file_get_contents($headerLog);
check('...and logs each, by name', str_contains($logged, "'X-Frame-Options'") && str_contains($logged, "'Referrer-Policy'") && str_contains($logged, "'Content-Security-Policy'") && str_contains($logged, 'keeps its default'), $logged);
@unlink($headerLog);
$r = SecurityHeaders::resolved(['Strict-Transport-Security: max-age=1', 'X-Frame-Options' => null, 5 => 'x']);
check('a list-style file: no header named 0 or 5', !isset($r[0]) && !isset($r['0']) && !isset($r[5]) && !isset($r['5']), json_encode(array_keys($r)));
check('...the named entry still counts', !isset($r['X-Frame-Options']));
check('...and it logs', str_contains((string) @file_get_contents($headerLog), 'not a header name'), (string) @file_get_contents($headerLog));
ini_restore('error_log');

// ── (B) the real stack ──────────────────────────────────────────────────────
echo "(B) real stack\n";
$site = $base . '/site';
$hash = password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]);
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'max', 'email' => 'max.muster@example.ch', 'password_hash' => $hash, 'roles' => ['superUser'], 'sort_key' => 0],
]);
// The frontend's starter pages and stylesheets: the page cache needs a real page.
mkdir($site . '/data/content', 0777, true);
foreach (glob($root . '/packages/kernel/core/data/content/*.default.json') as $seed) {
    copy($seed, $site . '/data/content/' . str_replace('.default.json', '.json', basename($seed)));
}
$source = $root . '/packages/module-frontend/res/assets';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    $to = $site . '/public/assets/frontend' . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
    if ($file->isFile()) {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        copy($file->getPathname(), $to);
    }
}

$port = realFlowServer($site, $base . '/sessions', $base . '/server.log');

/**
 * Every header line as sent, with duplicates: the browser class keeps the last one only.
 *
 * @return array{0:int, 1:list<array{0:string,1:string}>, 2:string}
 */
$raw = static function (int $port, string $path, string $method = 'GET', array $headers = []): array {
    $body = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => [
        'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 30, 'method' => $method, 'header' => implode("\r\n", $headers),
    ]]));
    $status = 0;
    $lines  = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
            continue;
        }
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $lines[] = [strtolower(trim($name)), trim($value)];
    }

    return [$status, $lines, $body];
};
$valuesOf = static function (array $lines, string $name): array {
    return array_values(array_map(static fn(array $l): string => $l[1], array_filter($lines, static fn(array $l): bool => $l[0] === strtolower($name))));
};
$carries = static function (string $where, array $response) use ($valuesOf): void {
    [$status, $lines] = $response;
    foreach (SecurityHeaders::DEFAULTS as $name => $value) {
        $found = $valuesOf($lines, $name);
        check("{$where} ({$status}): {$name} once", $found === [$value], implode(' | ', $found));
    }
    check("{$where}: no HSTS", $valuesOf($lines, 'Strict-Transport-Security') === []);
};

$carries('backend login', $raw($port, '/login'));
$carries('a 404 page', $raw($port, '/frontend/main/index/gibt-es-auch-nicht'));
$carries('the logout\'s confirm page', $raw($port, '/backend/system/login/logout'));
$carries('an unknown route', $raw($port, '/frontend/main/index/gibt-es-nicht'));
$carries('HEAD on the login', $raw($port, '/login', 'HEAD'));

// The page cache: miss, hit, 304, all through HtmlResponse.
$first = $raw($port, '/');
check('home page renders', $first[0] === 200, $first[0] . ' ' . substr($first[2], 0, 200));
$cacheOf = static fn(array $r): string => ($valuesOf($r[1], 'X-Z77-PageCache')[0] ?? '');
check('first: a page-cache MISS', strtolower($cacheOf($first)) === 'miss', $cacheOf($first));
$carries('page-cache MISS', $first);
$second = $raw($port, '/');
check('second: a page-cache HIT', strtolower($cacheOf($second)) === 'hit', $cacheOf($second));
$carries('page-cache HIT', $second);
$etag = $valuesOf($second[1], 'ETag')[0] ?? '';
$third = $raw($port, '/', 'GET', ['If-None-Match: ' . $etag]);
check('with the ETag: a 304', $third[0] === 304, (string) $third[0]);
$carries('page-cache 304', $third);

// A stricter value of an action stays.
$reset = $raw($port, '/backend/system/login/reset?token=' . str_repeat('a', 32));
check('reset page: no-referrer, not weakened, once', $valuesOf($reset[1], 'Referrer-Policy') === ['no-referrer'], implode(' | ', $valuesOf($reset[1], 'Referrer-Policy')));
check('reset page: the rest of the set is there', $valuesOf($reset[1], 'X-Frame-Options') === ['SAMEORIGIN']);

// A fetch answer and a redirect stay as they were; a file answer is DeliveryPolicy's alone.
$redirect = $raw($port, '/backend/system/dashboard/overview');
check('a redirect carries no page CSP', in_array($redirect[0], [302, 303], true) && $valuesOf($redirect[1], 'Content-Security-Policy-Report-Only') === []);
$deliverySrc = (string) file_get_contents($root . '/packages/kernel/core/src/Http/Response/DeliveryPolicy.php');
$responses   = ['FileResponse', 'BytesResponse', 'ByteSourceResponse'];
foreach ($responses as $class) {
    $src = (string) file_get_contents($root . "/packages/kernel/core/src/Http/Response/{$class}.php");
    check("{$class}: DeliveryPolicy, not this set", str_contains($src, 'DeliveryPolicy::apply') && !str_contains($src, 'SecurityHeaders'));
}

// ── (C) a project's file ────────────────────────────────────────────────────
echo "(C) a project's file\n";
mkdir($site . '/config/client', 0777, true);
$projectFile = $site . '/config/client/securityHeaders.inc.php';
file_put_contents($projectFile, "<?php\nreturn ['X-Frame-Options' => null, 'Content-Security-Policy' => \"frame-ancestors https://partner.example.ch\", 'Strict-Transport-Security' => 'max-age=60'];\n");
$p = $raw($port, '/login');
check('switched off: no X-Frame-Options', $valuesOf($p[1], 'X-Frame-Options') === []);
check('changed: its CSP, once', $valuesOf($p[1], 'Content-Security-Policy') === ['frame-ancestors https://partner.example.ch']);
check('added: its HSTS', $valuesOf($p[1], 'Strict-Transport-Security') === ['max-age=60']);
check('the rest stays', $valuesOf($p[1], 'X-Content-Type-Options') === ['nosniff'] && count($valuesOf($p[1], 'Content-Security-Policy-Report-Only')) === 1);
$cached = $raw($port, '/');
check('a cached page follows the file at once', $valuesOf($cached[1], 'X-Frame-Options') === [] && $valuesOf($cached[1], 'Strict-Transport-Security') === ['max-age=60'] && strtolower($cacheOf($cached)) === 'hit', $cacheOf($cached));
$e404 = $raw($port, '/frontend/main/index/gibt-es-auch-nicht');
check('an error page too', $e404[0] === 404 && $valuesOf($e404[1], 'X-Frame-Options') === [] && $valuesOf($e404[1], 'Strict-Transport-Security') === ['max-age=60']);

file_put_contents($projectFile, "<?php\nthrow new RuntimeException('broken');\n");
$b = $raw($port, '/login');
check('a file that throws: the page goes out with the defaults', $b[0] === 200 && $valuesOf($b[1], 'X-Frame-Options') === ['SAMEORIGIN']);
file_put_contents($projectFile, "<?php\nreturn ['nosniff', 'X-Frame-Options' => false];\n");
$b = $raw($port, '/login');
$names = array_column($b[1], 0);
check('a list-style file through the stack: no header named 0, the default stays', $b[0] === 200 && !in_array('0', $names, true) && $valuesOf($b[1], 'X-Frame-Options') === ['SAMEORIGIN'], json_encode($names));
file_put_contents($projectFile, "<?php\nreturn 'kein Array';\n");
$b = $raw($port, '/login');
check('a file that returns no array: the defaults', $b[0] === 200 && $valuesOf($b[1], 'X-Frame-Options') === ['SAMEORIGIN']);
unlink($projectFile);

// An uncaught error, the path that drops the half page.
$src = (string) file_get_contents($root . '/packages/kernel/core/src/Exception/ExceptionHandler.php');
check('every HTML error branch sends the set', substr_count($src, 'SecurityHeaders::send()') === 4 && substr_count($src, "header('Content-Type: text/html; charset=utf-8');") === 4);

$serverText = (string) @file_get_contents($base . '/server.log');
check('no PHP warning or fatal reached the server log', !preg_match('/PHP (Fatal|Parse|Warning)/', $serverText));

echo "\nsecurity-headers: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
