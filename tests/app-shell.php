<?php

/**
 * The app shell `/app` through the real stack (CLI, PHP's built-in server) — plan A6 / T6, the
 * ADR-057 addendum 2026-11-12, ADR-058 (code sign-in instead of pairing). What is load-bearing:
 *
 *   (A) the pages: start and sign-in answer 200 with the ENFORCED app CSP exactly once (script,
 *       style, image from the own origin, no `unsafe-inline` / `unsafe-eval`, no framing), the
 *       global page CSP stays observing on the login page; the HTML holds no inline script, no
 *       `style=` attribute and no `on…=` handler, loads its scripts from /assets/ and links the
 *       manifest; the receipt screen (module-receipt's `appScreens`) is in the start page, hidden
 *       until the device's scope shows it; the start page offers the sign-out; the sign-in page has
 *       the e-mail step and the code step (`one-time-code`), puts nothing from the address into
 *       the page and shows no tries counter; /app/pair is gone (404);
 *   (B) the manifest: `scope` and `start_url` /app/, standalone, the installation's icons;
 *   (C) the service worker: served from /app/sw.js as JavaScript with its own CSP, cache v2
 *       (the pairing shell dropped on installed phones), /app/login among its pages;
 *   (D) /app/anything else is a 404; the pages carry no device data (the cookie is on /api only);
 *   (E) the queue cards of `app.js` (source and min): a WAITING entry offers «Verwerfen» too, with
 *       its own question — the sign-out refusal «Erst senden oder verwerfen» always has its action
 *       (owner 2026-11-12); the cards are built with DOM nodes and textContent only.
 *
 * Run: php tests/app-shell.php
 */

use Z77\Module\Api\Ui\Controllers\App\AppController;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "app-shell: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/support/real-flow-site.php';
require_once __DIR__ . '/support/real-flow-server.php';

$root = str_replace('\\', '/', dirname(__DIR__));
// module-api is not in the monorepo's composer map — load it directly (harness and site alike).
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Z77\\Module\\Api\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/packages/module-api/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-app-shell-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});

// ── the site: kernel + frontend + backend, plus module-api and module-receipt ────────────────
$site = $base . '/site';
realFlowSite($site, $root, [
    ['id' => 1, 'username' => 'max', 'email' => 'max@example.ch', 'password_hash' => password_hash('Passwort-Sehr-Lang-1', PASSWORD_BCRYPT, ['cost' => 4]), 'roles' => ['superUser'], 'sort_key' => 0],
]);
$stub = (string) file_get_contents($site . '/vendor/autoload.php');
file_put_contents($site . '/vendor/autoload.php', "<?php\n"
    . 'spl_autoload_register(static function (string $class): void {' . "\n"
    . '    $prefix = ' . var_export('Z77\\Module\\Api\\', true) . ";\n"
    . '    if (str_starts_with($class, $prefix)) {' . "\n"
    . '        $file = ' . var_export($root . '/packages/module-api/src/', true) . ' . str_replace(\'\\\\\', \'/\', substr($class, strlen($prefix))) . \'.php\';' . "\n"
    . '        if (is_file($file)) { require $file; }' . "\n"
    . "    }\n"
    . "});\n"
    . preg_replace('/^<\?php\s*/', '', $stub));
$finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
    "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Api\\\\'      => \$entry('module-api'),\n        'Z77\\\\Module\\\\Receipt\\\\'  => \$entry('module-receipt'),",
    $finder
));
$modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'api' => array (),\n    'receipt' => array (),", $modules));
foreach (['module-api' => 'api', 'module-receipt' => 'receipt'] as $package => $asset) {
    $source = $root . '/packages/' . $package . '/res/assets';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $to = $site . '/public/assets/' . $asset . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
        if ($file->isFile()) {
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0777, true);
            }
            copy($file->getPathname(), $to);
        }
    }
}

$port = realFlowServer($site, $base . '/sessions', $base . '/server.log');

/** @return array{0:int, 1:list<array{0:string,1:string}>, 2:string} status, every header line (lower-case name), body */
$get = static function (string $path, array $headers = []) use ($port): array {
    $body = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => [
        'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 30, 'header' => implode("\r\n", $headers),
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
$values = static fn(array $lines, string $name): array => array_values(array_map(
    static fn(array $l) => $l[1],
    array_filter($lines, static fn(array $l) => $l[0] === strtolower($name))
));

/** Inline script (not a JSON data block), `style=` attributes, `on…=` handlers — what the app CSP forbids. */
$inlineViolations = static function (string $html): array {
    $found = [];
    if (preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $tag) {
            $isData = preg_match('#type\s*=\s*"application/json"#i', $tag[1]) === 1;
            if (!$isData && trim($tag[2]) !== '') $found[] = 'inline script';
            if (!$isData && !preg_match('#\bsrc="/assets/#', $tag[1])) $found[] = 'script not from /assets/: ' . trim($tag[1]);
        }
    }
    if (preg_match('#\sstyle\s*=#i', $html)) $found[] = 'style attribute';
    if (preg_match('#<[^>]+\son[a-z]+\s*=#i', $html)) $found[] = 'on… handler';
    return $found;
};

// ── (A) the pages ─────────────────────────────────────────────────────────────────────────
echo "(A) pages\n";
[$status, $lines, $html] = $get('/app/');
$csp = $values($lines, 'Content-Security-Policy');
check('A1 /app/ → 200 HTML', $status === 200 && str_starts_with(($values($lines, 'Content-Type')[0] ?? ''), 'text/html'), $status . ' ' . substr($html, 0, 300));
check('A2 the app CSP is ENFORCED, exactly once, as AppController::CSP', $csp === [AppController::CSP], implode(' | ', $csp));
check('A3 … scripts, styles, images from the own origin; no unsafe-inline / unsafe-eval; no framing',
    str_contains(AppController::CSP, "script-src 'self'") && str_contains(AppController::CSP, "style-src 'self'") && str_contains(AppController::CSP, "img-src 'self'")
    && str_contains(AppController::CSP, "default-src 'self'") && str_contains(AppController::CSP, "frame-ancestors 'none'")
    && !str_contains(AppController::CSP, 'unsafe-') && !str_contains(AppController::CSP, 'blob:') && !str_contains(AppController::CSP, '*'));
check('A4 noindex, nosniff', ($values($lines, 'X-Robots-Tag')[0] ?? '') === 'noindex, nofollow' && $values($lines, 'X-Content-Type-Options') === ['nosniff']);
check('A5 the start page holds no inline script, no style attribute, no on… handler', $inlineViolations($html) === [], implode(', ', $inlineViolations($html)));
check('A6 … links the manifest and loads _Z77.app and the receipt screen\'s script from /assets/',
    str_contains($html, '<link rel="manifest" href="/app/manifest.webmanifest">')
    && preg_match('#<script src="/assets/api/js/app_at-[^"]+\.js" defer></script>#', $html) === 1
    && preg_match('#<script src="/assets/receipt/js/app/receipt_at-[^"]+\.js" defer></script>#', $html) === 1);
check('A7 the receipt screen (module-receipt appScreens) is there, HIDDEN until the device shows receipt.capture',
    preg_match('#<section class="za-screen" data-app-screen="receipt" data-app-scope="receipt.capture" data-app-title="Belege" hidden>#', $html) === 1
    && str_contains($html, 'data-receipt-view="tiles"') && str_contains($html, 'capture="environment"') && str_contains($html, 'Ohne Vorlage')
    && str_contains($html, 'id="receipt-icon-fuel"'));
check('A8 the page carries no device data and no account field (E2): the cookie is on /api only',
    !str_contains($html, 'debit') && !str_contains($html, 'credit_account') && !str_contains($html, 'z77_api_device'));

check('A9 the start page offers «Auf diesem Gerät abmelden», hidden until the device is known',
    preg_match('#<p class="za-pad za-stack" data-app-account hidden>\s*<button type="button" class="za-link" data-app-logout>Auf diesem Gerät abmelden</button>#', $html) === 1);

[$status, $lines, $html] = $get('/app/login?email=' . rawurlencode('"><script>alert(1)</script>') . '&code=123456');
check('A10 /app/login → 200, the app CSP enforced once', $status === 200 && $values($lines, 'Content-Security-Policy') === [AppController::CSP], $status . ' ' . substr($html, 0, 300));
check('A11 two steps on one page: the e-mail form, then the code form (hidden) with autocomplete="one-time-code", numeric',
    str_contains($html, 'data-app-login-email') && preg_match('#<form class="za-stack" method="get" action="/app/login" data-app-login-code hidden novalidate>#', $html) === 1
    && str_contains($html, 'name="email"') && str_contains($html, 'autocomplete="one-time-code"') && str_contains($html, 'inputmode="numeric"')
    && str_contains($html, 'Neuen Code anfordern'));
check('A12 nothing from the address is put into the page (no e-mail, no code, nothing unescaped); no tries counter',
    !str_contains($html, 'alert(1)') && !str_contains($html, '123456') && !preg_match('/value="[^"]+"/', $html) && !preg_match('/Versuche? übrig|noch \d+ Versuch/i', $html));
check('A13 the same text for every address; the sign-in page holds no inline script, style attribute or handler; without JS the forms only reload the page',
    str_contains($html, 'Wenn diese Adresse für die App freigeschaltet ist') && $inlineViolations($html) === []
    && substr_count($html, 'method="get" action="/app/login"') === 2 && str_contains($html, '<noscript>'), implode(', ', $inlineViolations($html)));

[$status, $lines] = $get('/login');
check('A14 the global default is untouched: the login page keeps the observing page CSP and only frame-ancestors enforced',
    $values($lines, 'Content-Security-Policy') === ["frame-ancestors 'self'"] && count($values($lines, 'Content-Security-Policy-Report-Only')) === 1,
    implode(' | ', $values($lines, 'Content-Security-Policy')));

// ── (B) manifest ──────────────────────────────────────────────────────────────────────────
echo "(B) manifest\n";
[$status, $lines, $body] = $get('/app/manifest.webmanifest');
$manifest = json_decode($body, true) ?: [];
check('B1 the manifest: scope and start_url /app/, standalone, German', $status === 200
    && ($manifest['scope'] ?? '') === '/app/' && ($manifest['start_url'] ?? '') === '/app/' && ($manifest['display'] ?? '') === 'standalone' && ($manifest['lang'] ?? '') === 'de', $body);
check('B2 … the installation\'s icons, 192 and 512', array_column($manifest['icons'] ?? [], 'src') === ['/web-app-manifest-192x192.png', '/web-app-manifest-512x512.png']);

// ── (C) service worker ────────────────────────────────────────────────────────────────────
echo "(C) service worker\n";
[$status, $lines, $body] = $get('/app/sw.js');
check('C1 /app/sw.js → 200 as JavaScript (a worker registers only from a script type), nosniff',
    $status === 200 && str_starts_with(($values($lines, 'Content-Type')[0] ?? ''), 'text/javascript') && $values($lines, 'X-Content-Type-Options') === ['nosniff'],
    $status . ' ' . implode(' | ', $values($lines, 'Content-Type')));
check('C2 … with the worker\'s own CSP, revalidated on every navigation', $values($lines, 'Content-Security-Policy') === ["default-src 'self'"] && $values($lines, 'Cache-Control') === ['no-cache']);
check('C3 … the shell worker itself: cache v2 (ADR-058 — drops the pairing shell), /app/login among its pages, never /api',
    preg_match('/["\x27]z77-app-v2["\x27]/', $body) === 1 && !str_contains($body, 'z77-app-v1') && preg_match('#["\x27]/app/login["\x27]#', $body) === 1
    && preg_match('#["\x27]/app/pair["\x27]#', $body) !== 1 && str_contains($body, '/api/'));

// ── (D) the rest ──────────────────────────────────────────────────────────────────────────
echo "(D) other paths\n";
[$status] = $get('/app/nope');
check('D1 /app/anything else → 404', $status === 404, (string) $status);
[$status] = $get('/app/pair?code=ABCDE-FGHJK');
check('D2 /app/pair is gone (ADR-058) → 404', $status === 404, (string) $status);
[$status] = $get('/app/login/x');
check('D3 /app/login/x → 404', $status === 404, (string) $status);

// ── (E) the queue cards ───────────────────────────────────────────────────────────────────
echo "(E) queue cards (app.js)\n";
$appJs  = (string) file_get_contents($root . '/packages/module-api/res/assets/js/app.js');
$appMin = (string) file_get_contents($root . '/packages/module-api/res/assets/js/app.min.js');
$cardFn = preg_match('/function card\(entry\) \{(.*?)\n    \}\n/s', $appJs, $m) === 1 ? $m[1] : '';
check('E1 a waiting entry can be discarded: «Verwerfen» outside the refused-only branch, with its own question; «Bearbeiten» stays for a refused one',
    $cardFn !== '' && preg_match("/if \\(refused(?: && !foreign)?\\) \\{[^}]*'Bearbeiten'.*?\\n        \\}\\n        var drop = el\\('button', 'za-link', 'Verwerfen'\\)/s", $cardFn) === 1
    && str_contains($cardFn, 'Diesen wartenden Eintrag verwerfen') && str_contains($cardFn, 'queue.remove(entry.key)'));
check('E2 … built with DOM nodes and textContent, no innerHTML / insertAdjacentHTML in the shell; the min carries it',
    !preg_match('/innerHTML|insertAdjacentHTML|outerHTML/', $appJs) && str_contains($appMin, 'Diesen wartenden Eintrag verwerfen') && str_contains($appMin, 'Erst senden oder verwerfen'));

if ($fail > 0) {
    echo "\n--- server log (tail) ---\n" . implode("\n", array_slice(file($base . '/server.log', FILE_IGNORE_NEW_LINES) ?: [], -25)) . "\n";
    foreach (glob($site . '/logs/*') ?: [] as $log) {
        echo "--- {$log} (tail) ---\n" . implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -15)) . "\n";
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
