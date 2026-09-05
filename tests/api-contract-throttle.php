<?php

/**
 * API-contract + throttle harness (CLI) — the second half of the step-1
 * framework extensions (the guard/routing half lives in
 * tests/api-stateless-guard.php): the ApiResult → wire mapping and the
 * shared fixed-window counter.
 *
 * What is load-bearing here:
 *
 *   - ApiResponder is the ONE place that knows the wire (api-envelope-v1):
 *     payload → 200 + quoted ETag + no-store; a matching If-None-Match
 *     (quoted, unquoted, or W/-prefixed) → 304 with NO body; an error →
 *     its status + the frozen {"error":{code,message}} shape, Retry-After
 *     only when the result carries one;
 *   - JsonResponse: 304 and omitBody() (HEAD) send headers but no body and
 *     no Content-Type;
 *   - FileThrottle: counts to the limit and not past it, keys are isolated,
 *     the window rolls over, retryAfter() points at the window end, and the
 *     increment survives concurrent writers (the pre-extraction counter read
 *     unlocked and could lose counts);
 *   - MemberThrottle still behaves identically through the delegation
 *     (limit reached, IPv6 /64 folding, unparseable IP → allow).
 *
 * Run: php tests/api-contract-throttle.php
 * Uses a throwaway counter directory in the system temp; removed on success.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'          => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'        => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\'   => __DIR__ . '/../packages/kernel/persistence/src/',
        'Z77\\Module\\Member\\' => __DIR__ . '/../packages/module-member/src/',
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

use Z77\Core\Http\Response\ApiResponder;
use Z77\Core\Http\Response\JsonResponse;
use Z77\Module\Member\Services\MemberThrottle;
use Z77\Core\Http\Response\Etag;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;
use Z77\Shared\Throttle\FileThrottle;

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'FAIL') . "  $label\n";
    if (!$ok) {
        $failures++;
    }
};

$readPrivate = function (object $obj, string $prop) {
    $p = new ReflectionProperty($obj::class, $prop);
    return $p->getValue($obj);
};

/** send() echoes — capture the body to assert on it. The @ silences the
 *  header()/http_response_code() warnings that CLI output provokes (headers
 *  are meaningless in this SAPI; the body is what the checks assert on). */
$captureBody = function (JsonResponse $response): string {
    ob_start();
    @$response->send();
    return (string) ob_get_clean();
};

$request = fn(?string $ifNoneMatch = null): ApiRequest =>
    new ApiRequest('zihlundsee', [], ['lang' => 'fr'], $ifNoneMatch);

// ── A. ApiResponder: payload ─────────────────────────────────────────────────

$r = ApiResponder::respond($request(), ApiResult::payload(['units' => [1, 2]], 'hash-1'));
$check('A1 payload → 200', $readPrivate($r, 'status') === 200);
$check('A2 … data intact', ($readPrivate($r, 'data')['units'] ?? null) === [1, 2]);
$headers = $readPrivate($r, 'headers');
$check('A3 … ETag quoted', ($headers['ETag'] ?? '') === '"hash-1"');
$check('A4 … Cache-Control: no-store', ($headers['Cache-Control'] ?? '') === 'no-store');

$r = ApiResponder::respond($request(), ApiResult::payload(['ok' => true]));
$check('A5 payload without etag → 200, no ETag header',
    $readPrivate($r, 'status') === 200 && !isset($readPrivate($r, 'headers')['ETag']));

// ── B. ApiResponder: conditional GET → 304 ───────────────────────────────────

foreach (['"hash-1"' => 'quoted', 'hash-1' => 'unquoted', 'W/"hash-1"' => 'weak'] as $sent => $form) {
    $r = ApiResponder::respond($request($sent), ApiResult::payload(['big' => 'bundle'], 'hash-1'));
    $check("B  If-None-Match ($form) matches → 304", $readPrivate($r, 'status') === 304);
}

$r = ApiResponder::respond($request('"stale-hash"'), ApiResult::payload(['big' => 'bundle'], 'hash-1'));
$check('B4 stale If-None-Match → 200 with new bundle', $readPrivate($r, 'status') === 200);

$r304 = ApiResponder::respond($request('"hash-1"'), ApiResult::payload(['big' => 'bundle'], 'hash-1'));
$check('B5 304 body is empty', $captureBody($r304) === '');
$check('B6 304 keeps the ETag header', ($readPrivate($r304, 'headers')['ETag'] ?? '') === '"hash-1"');

// ── B'. ApiResponder: service headers ride on 200 and 304 ───────────────────

$hinted = ApiResult::payload(['big' => 'bundle'], 'hash-1', ['X-Axo3-Retry-After' => '30']);
$r = ApiResponder::respond($request(), $hinted);
$check('B7 service header on 200', ($readPrivate($r, 'headers')['X-Axo3-Retry-After'] ?? '') === '30');
$r = ApiResponder::respond($request('"hash-1"'), $hinted);
$check('B8 service header on 304 too', $readPrivate($r, 'status') === 304
    && ($readPrivate($r, 'headers')['X-Axo3-Retry-After'] ?? '') === '30');
$r = ApiResponder::respond($request(), ApiResult::payload(['x' => 1], 'h', ['Cache-Control' => 'public', 'ETag' => 'forged']));
$check('B9 envelope headers win over a service clash',
    ($readPrivate($r, 'headers')['Cache-Control'] ?? '') === 'no-store'
    && ($readPrivate($r, 'headers')['ETag'] ?? '') === '"h"');
$r = ApiResponder::respond($request(), ApiResult::payload(['x' => 1], 'h'));
$check('B10 no service header unless set', count($readPrivate($r, 'headers')) === 2);

// ── C. ApiResponder: errors ──────────────────────────────────────────────────

$r = ApiResponder::respond($request(), ApiResult::error('unknown_dataset', 404, 'No such dataset.'));
$body = json_decode($captureBody($r), true);
$check('C1 error → its status', $readPrivate($r, 'status') === 404);
$check('C2 … frozen body shape', ($body['error']['code'] ?? '') === 'unknown_dataset'
    && ($body['error']['message'] ?? '') === 'No such dataset.');
$check('C3 … no Retry-After unless set', !isset($readPrivate($r, 'headers')['Retry-After']));

$r = ApiResponder::respond($request(), ApiResult::error('throttled', 429, 'Rate limit exceeded.', 42));
$check('C4 throttled → 429 + Retry-After', $readPrivate($r, 'status') === 429
    && ($readPrivate($r, 'headers')['Retry-After'] ?? '') === '42');

// ── D. JsonResponse: HEAD / omitBody ─────────────────────────────────────────

$r = new JsonResponse(['secret' => 'payload'], 200, ['ETag' => '"x"']);
$check('D1 omitBody() suppresses the body', $captureBody($r->omitBody()) === '');
$check('D2 normal send still delivers JSON',
    json_decode($captureBody(new JsonResponse(['a' => 1])), true) === ['a' => 1]);

// ── E. FileThrottle ──────────────────────────────────────────────────────────

$work = sys_get_temp_dir() . '/z77-api-throttle-' . getmypid();
$t    = new FileThrottle($work . '/counters');
$now  = 1_000_000;

$ok = true;
for ($i = 0; $i < 3; $i++) {
    $ok = $ok && $t->allow('tenant:a', 3, 3600, $now);
}
$check('E1 counts up to the limit', $ok);
$check('E2 limit reached → deny', $t->allow('tenant:a', 3, 3600, $now) === false);
$check('E3 other key unaffected', $t->allow('tenant:b', 3, 3600, $now) === true);
$check('E4 window rollover resets', $t->allow('tenant:a', 3, 3600, $now + 3600) === true);
$check('E5 retryAfter points at the window end', $t->retryAfter(3600, 3601) === 3599);

// Concurrent writers: two processes hammer one key; the file count must equal
// the number of allowed attempts (the old unlocked read lost increments).
$childCode = str_replace('{DIR}', addslashes($work . '/counters'), <<<'PHP'
require $argv[1];
$t = new Z77\Shared\Throttle\FileThrottle('{DIR}');
$allowed = 0;
for ($i = 0; $i < 200; $i++) { if ($t->allow('race', 1000, 3600, 1000000)) $allowed++; }
echo $allowed;
PHP);
$loader   = $work . '/loader.php';
@mkdir($work, 0777, true);
file_put_contents($loader, "<?php\nspl_autoload_register(function(\$c){\n"
    . "  if (str_starts_with(\$c, 'Z77\\\\Shared\\\\')) require '" . addslashes(__DIR__ . '/../packages/kernel/shared/src/') . "' . str_replace('\\\\','/',substr(\$c,11)) . '.php';\n});");
$childFile = $work . '/child.php';
file_put_contents($childFile, "<?php\n" . $childCode);
$p1 = proc_open('php ' . escapeshellarg($childFile) . ' ' . escapeshellarg($loader), [1 => ['pipe', 'w']], $pipes1);
$p2 = proc_open('php ' . escapeshellarg($childFile) . ' ' . escapeshellarg($loader), [1 => ['pipe', 'w']], $pipes2);
$allowed1 = (int) stream_get_contents($pipes1[1]);
$allowed2 = (int) stream_get_contents($pipes2[1]);
proc_close($p1);
proc_close($p2);
// The race key's counter is the only one in the hundreds — pick the maximum.
$total = 0;
foreach (glob($work . '/counters/*') ?: [] as $f) {
    $total = max($total, (int) file_get_contents($f));
}
$check('E6 concurrent increments are not lost (400 attempts → counter 400)',
    $allowed1 === 200 && $allowed2 === 200 && $total === 400);

// forget(): deliberate cleanup — counters gone, key starts from zero.
$tf = new FileThrottle($work . '/forget');
$tf->allow('gone', 1, 3600, $now);
$check('E7 key at limit before forget', $tf->allow('gone', 1, 3600, $now) === false);
$check('E8 forget removes the counter files', $tf->forget('gone') === 1
    && glob($work . '/forget/*') === []);
$check('E9 … and the key counts from zero again', $tf->allow('gone', 1, 3600, $now) === true);
$check('E10 forget of an unknown key removes nothing', $tf->forget('never-counted') === 0);

// ── F. MemberThrottle delegation ─────────────────────────────────────────────

$m = new MemberThrottle($work . '/member', 2, 3600);
$check('F1 member: allows under limit', $m->allow('a@b.ch', $now) && $m->allow('a@b.ch', $now));
$check('F2 member: denies at limit', $m->allow('a@b.ch', $now) === false);
$check('F3 member: case/space folding still counts as one address', $m->allow('  A@B.CH ', $now) === false);
$check('F4 member: IPv6 /64 folding', MemberThrottle::normalizeIp('2001:db8:1:2:aaaa::1')
    === MemberThrottle::normalizeIp('2001:db8:1:2:bbbb::2'));
$check('F5 member: unparseable IP → allow', $m->allowIp('not-an-ip', 1, $now));

// ── G. Etag: the ONE reading of If-None-Match ────────────────────────────────
//
// Before this class four doors answered the same question differently
// (PageCachePolicy, ApiResponder, FileResponse, and the axo3 widget). These
// cases are the union of what the four used to handle — a form any one of
// them dropped is a 200 where a 304 belonged.

$check('G1 null header never matches',        Etag::matches(null, 'h') === false);
$check('G2 empty header never matches',       Etag::matches('   ', 'h') === false);
$check('G3 quoted',                           Etag::matches('"h"', 'h'));
$check('G4 unquoted',                         Etag::matches('h', 'h'));
$check('G5 weak prefix',                      Etag::matches('W/"h"', 'h'));
$check('G6 wildcard matches anything',        Etag::matches('*', 'h'));
$check('G7 comma list, hit in the middle',    Etag::matches('"a", W/"h", "z"', 'h'));
$check('G8 comma list, no hit',               Etag::matches('"a", "b"', 'h') === false);
$check('G9 int tag (page-cache mtime)',       Etag::matches('"1788604483"', 1788604483));
$check('G10 a tag is opaque: "007" is not 7', Etag::matches('"007"', 7) === false);
$check('G11 header value is quoted, strong',  Etag::header('h') === '"h"' && Etag::header(7) === '"7"');
$check('G12 int = a time, string = a hash',   Etag::isTime(7) && !Etag::isTime('7'));

// ── H. HtmlResponse: a hash ETag must not become a Last-Modified date ────────
//
// The page cache stamps an entry mtime (int) and wants both validators; a
// controller that knows what its answer depends on stamps a content hash
// (string) and must get the ETag ALONE — `gmdate()` over a hash puts the
// header somewhere in 1970 and caches do arithmetic with it.
//
// CLI sends no headers, so the assertion is on the SOURCE: `Last-Modified`
// appears exactly once, and inside the `isTime()` branch. ⚠️ No literal line
// break in the pattern — this repo checks out with either line ending.

$responseSrc = (string) file_get_contents(__DIR__ . '/../packages/kernel/core/src/Http/Response/HtmlResponse.php');
$check('H1 Last-Modified is written in exactly one place',
    substr_count($responseSrc, "header('Last-Modified") === 1);
$guardPos = strpos($responseSrc, 'if (Etag::isTime(');
$datePos  = strpos($responseSrc, "header('Last-Modified");
$check('H2 … and only under the isTime() guard',
    $guardPos !== false && $datePos !== false && $guardPos < $datePos
    && !str_contains(substr($responseSrc, $guardPos, $datePos - $guardPos), '}'));

$hashed = HtmlResponse::notModified('a1b2c3');
$check('H3 a string ETag is accepted and kept', $readPrivate($hashed, 'etag') === 'a1b2c3');
$timed = HtmlResponse::notModified(1788604483);
$check('H4 an int ETag stays an int', $readPrivate($timed, 'etag') === 1788604483);

// ── cleanup + result ─────────────────────────────────────────────────────────

$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? $rm($f) : unlink($f);
    }
    @rmdir($dir);
};
if ($failures === 0) {
    $rm($work);
}

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
