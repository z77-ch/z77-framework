<?php

/**
 * Cross-origin POST door harness (CLI) — CROSS-ORIGIN-POST-001, security.md «cross-origin POST door».
 *
 * What is load-bearing here:
 *
 *   A. FormStamp: a stamp opens only its own route, only between 3 s and 2 h after issue, only
 *      with its signature intact; a short key throws, no key file = no stamp service.
 *   B. CrossOriginPostGuard: every refusal step answers with its status and reason AND carries
 *      CORS headers (`*` before the origin check, the checked origin after it); cors and no-cors
 *      pass, a navigating <form> is refused; the per-IP throttle counts refusals too and answers
 *      429 with an exposed Retry-After;
 *      finish() refuses any answer but JSON and puts the door's CORS headers on it.
 *   C. CrossOriginPostDoor: the two keys — attribute and `crossOriginPosts` entry — must agree;
 *      either alone throws, as does a wrong policy class, an HttpMethod other than exactly POST,
 *      and #[Csrf] / #[Page] on the door.
 *   D. Request: not stateless by default, stateless after enterCrossOriginPost(); content type
 *      without its parameters; content length only as a number.
 *   E. Fence: no action under packages/ carries #[CrossOriginPost] — the framework ships no door.
 *
 * Not covered (needs a web server and a browser): that no session cookie goes out, and the
 * foreign-origin fetch itself. See security.md for the browser check.
 *
 * Run: php tests/cross-origin-post.php
 */

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

use Z77\Core\Config\Config;
use Z77\Core\Controller\ControllerHandler;
use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\JsonResponse;
use Z77\Core\Services\CrossOriginPostDoor;
use Z77\Core\Services\CrossOriginPostGuard;
use Z77\Core\Services\ModuleManager;
use Z77\Shared\Attributes\CrossOriginPost;
use Z77\Shared\Attributes\Csrf;
use Z77\Shared\Attributes\HttpMethod;
use Z77\Shared\Security\CrossOriginPolicyInterface;
use Z77\Shared\Security\FormStamp;

$passed   = 0;
$failures = 0;
$check = function (string $label, bool $ok) use (&$passed, &$failures): void {
    echo $ok ? "  ok   {$label}\n" : "  FAIL {$label}\n";
    $ok ? $passed++ : $failures++;
};
$section = function (string $title): void {
    echo "\n{$title}\n";
};

$root = sys_get_temp_dir() . '/z77-cross-origin-' . getmypid();
define('ABS_BASE_PATH', $root);
@mkdir($root . '/config/client', 0777, true);
$key = str_repeat('k', 40);
file_put_contents($root . '/config/client/cross-origin.inc.php', "<?php return ['stampKey' => '{$key}'];");

$readPrivate = fn(object $o, string $p) => (new ReflectionProperty($o::class, $p))->getValue($o);
$setPrivate  = function (object $o, string $p, mixed $v): void {
    (new ReflectionProperty($o::class, $p))->setValue($o, $v);
};

const ROUTE = 'main/widget/enquiry';

// ── A. FormStamp ──
$section('A. FormStamp');

$stamps = new FormStamp($key);
$now    = 1_800_000_000;
$stamp  = $stamps->issue(ROUTE, $now);

$check('A1 a stamp 10 s old verifies', $stamps->verify($stamp, ROUTE, $now + 10));
$check('A2 … younger than 3 s does not', !$stamps->verify($stamp, ROUTE, $now + 2));
$check('A3 … at exactly 3 s does', $stamps->verify($stamp, ROUTE, $now + 3));
$check('A4 … at exactly 2 h does', $stamps->verify($stamp, ROUTE, $now + 7200));
$check('A5 … older than 2 h does not', !$stamps->verify($stamp, ROUTE, $now + 7201));
$check('A6 … for another route does not', !$stamps->verify($stamp, 'main/contact/send', $now + 10));
$check('A7 … with a moved time does not', !$stamps->verify(($now - 100) . substr($stamp, 10), ROUTE, $now + 10));
$check('A8 … under another key does not', !(new FormStamp(str_repeat('x', 40)))->verify($stamp, ROUTE, $now + 10));
$check('A9 garbage does not', !$stamps->verify('not-a-stamp', ROUTE, $now));

$threw = false;
try { new FormStamp('short'); } catch (RuntimeException) { $threw = true; }
$check('A10 a key under 32 characters throws', $threw);

$check('A11 fromConfig() reads the key file', FormStamp::fromConfig() instanceof FormStamp);
$check('A12 fromConfig() without a file → null', FormStamp::fromConfig($root . '/nowhere') === null);

// ── B. CrossOriginPostGuard ──
$section('B. CrossOriginPostGuard');

final class HarnessOriginPolicy implements CrossOriginPolicyInterface
{
    public function allows(string $origin, Request $request): bool
    {
        return $origin === 'https://wohngut.ch' && $request->getPostParameter('widget') === 'w1';
    }
}
final class HarnessNotAPolicy {}

$requestWith = function (string $method, RequestMode $mode) use ($setPrivate): Request {
    $r = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $setPrivate($r, 'method', $method);
    $setPrivate($r, 'mode', $mode);
    $setPrivate($r, 'group', 'main');
    $setPrivate($r, 'controller', 'widget');
    $setPrivate($r, 'action', 'enquiry');
    DI::getInstance(true)->set('Request', $r, true);
    return $r;
};

/** A request that passes every step; each case breaks exactly one thing. */
// Every case gets an address of its own, so the throttle (which counts every request,
// refusals included) never decides a case it is not about.
$nextIp = 0;
$valid = function (array $server = [], array $post = []) use ($stamps, $requestWith, &$nextIp): void {
    $requestWith('post', RequestMode::Fetch);
    $nextIp++;
    $_SERVER = array_merge([
        'CONTENT_TYPE'   => 'application/x-www-form-urlencoded;charset=UTF-8',
        'CONTENT_LENGTH' => '120',
        'HTTP_ORIGIN'    => 'https://wohngut.ch',
        'REMOTE_ADDR'    => '198.51.' . intdiv($nextIp, 250) . '.' . ($nextIp % 250 + 1),
    ], $server);
    $_POST = array_merge(['widget' => 'w1', FormStamp::FIELD => $stamps->issue(ROUTE, time() - 60)], $post);
};

$guard   = fn() => new CrossOriginPostGuard(ROUTE, HarnessOriginPolicy::class);
$refusal = function (?object $answer, int $status, string $reason, string $acao) use ($readPrivate): bool {
    if (!$answer instanceof JsonResponse) {
        return false;
    }
    $headers = $readPrivate($answer, 'headers');
    return $answer->getStatus() === $status
        && ($readPrivate($answer, 'data')['reason'] ?? '') === $reason
        && ($headers['Access-Control-Allow-Origin'] ?? '') === $acao
        && ($headers['Vary'] ?? '') === 'Origin'
        && ($headers['Access-Control-Expose-Headers'] ?? '') === 'Retry-After'
        && !isset($headers['Access-Control-Allow-Credentials']);
};

$valid();
$check('B1 a valid request passes (cors)', $guard()->enforce() === null);

$valid();
$requestWith('post', RequestMode::Fetch); // Sec-Fetch-Mode no-cors is Fetch mode as well
$check('B2 … and as no-cors (same mode)', $guard()->enforce() === null);

$valid();
$requestWith('get', RequestMode::Fetch);
$answer = $guard()->enforce();
$check('B3 GET → 405 method, CORS *', $refusal($answer, 405, 'method', '*'));
$check('B4 … with Allow: POST', ($readPrivate($answer, 'headers')['Allow'] ?? '') === 'POST');

$valid();
$requestWith('post', RequestMode::Page);
$check('B5 a navigating <form> (Page mode) → 403 mode', $refusal($guard()->enforce(), 403, 'mode', '*'));

$valid(['CONTENT_TYPE' => 'application/json']);
$check('B6 JSON body → 415', $refusal($guard()->enforce(), 415, 'content_type', '*'));

$valid(['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
$check('B7 multipart → 415', $refusal($guard()->enforce(), 415, 'content_type', '*'));

$valid(['CONTENT_LENGTH' => '']);
$check('B8 no Content-Length → 413', $refusal($guard()->enforce(), 413, 'too_large', '*'));

$valid(['CONTENT_LENGTH' => '16385']);
$check('B9 16 KB + 1 → 413', $refusal($guard()->enforce(), 413, 'too_large', '*'));

$valid(['CONTENT_LENGTH' => '16384']);
$check('B10 exactly 16 KB passes', $guard()->enforce() === null);

$valid(['HTTP_ORIGIN' => '', 'HTTP_REFERER' => 'https://wohngut.ch/objekt']);
$check('B11 no Origin (Referer ignored) → 403 origin', $refusal($guard()->enforce(), 403, 'origin', '*'));

$valid(['HTTP_ORIGIN' => 'null']);
$check('B12 Origin «null» → 403 origin', $refusal($guard()->enforce(), 403, 'origin', '*'));

$valid(['HTTP_ORIGIN' => 'https://wohngut.ch/path']);
$check('B13 Origin with a path → 403 origin', $refusal($guard()->enforce(), 403, 'origin', '*'));

$valid(['HTTP_ORIGIN' => 'HTTPS://WOHNGUT.CH']);
$check('B14 Origin is compared lowercase', $guard()->enforce() === null);

$valid(['HTTP_ORIGIN' => 'https://evil.example']);
$check('B15 an origin the policy refuses → 403 origin_denied, CORS *', $refusal($guard()->enforce(), 403, 'origin_denied', '*'));

$valid([], ['widget' => 'w2']);
$check('B16 the policy sees the body (other widget) → origin_denied', $refusal($guard()->enforce(), 403, 'origin_denied', '*'));

$valid([], [FormStamp::FIELD => '']);
$check('B17 no stamp → 403 stamp, CORS = checked origin', $refusal($guard()->enforce(), 403, 'stamp', 'https://wohngut.ch'));

$valid([], [FormStamp::FIELD => $stamps->issue(ROUTE, time())]);
$check('B18 a stamp issued just now → 403 stamp', $refusal($guard()->enforce(), 403, 'stamp', 'https://wohngut.ch'));

$valid([], [FormStamp::FIELD => $stamps->issue('main/contact/send', time() - 60)]);
$check('B19 a stamp of another door → 403 stamp', $refusal($guard()->enforce(), 403, 'stamp', 'https://wohngut.ch'));

$valid();
$closed = new CrossOriginPostGuard(ROUTE, HarnessOriginPolicy::class, $root . '/nowhere');
$check('B20 no key configured → the door is shut (403 stamp)', $refusal($closed->enforce(), 403, 'stamp', 'https://wohngut.ch'));

$ip = '2001:db8:1:2::';
$throttlePassed = 0;
for ($i = 1; $i <= CrossOriginPostGuard::IP_PER_HOUR; $i++) {
    $valid(['REMOTE_ADDR' => $ip . dechex($i)]);   // one /64, many addresses
    $throttlePassed += $guard()->enforce() === null ? 1 : 0;
}
$check('B21 the per-IP limit passes in full', $throttlePassed === CrossOriginPostGuard::IP_PER_HOUR);
$valid(['REMOTE_ADDR' => $ip . 'ffff']);
$answer = $guard()->enforce();
$check('B22 one more from the same /64 → 429 (before the origin check: CORS *)', $refusal($answer, 429, 'throttled', '*'));
$check('B23 … with Retry-After, exposed to the foreign page', ctype_digit($readPrivate($answer, 'headers')['Retry-After'] ?? ''));

// Refusals count too (owner 2026-11-28): probing without a stamp spends the same budget.
$probe = '2001:db8:9:9::';
for ($i = 1; $i <= CrossOriginPostGuard::IP_PER_HOUR; $i++) {
    $valid(['REMOTE_ADDR' => $probe . dechex($i)], [FormStamp::FIELD => '']);
    $guard()->enforce();
}
$valid(['REMOTE_ADDR' => $probe . 'ffff']);
$check('B23b after 20 refused probes a valid post from the same /64 → 429', $refusal($guard()->enforce(), 429, 'throttled', '*'));
$valid(['REMOTE_ADDR' => '2001:db8:9:a::1']);
$check('B23c … the next /64 is not affected', $guard()->enforce() === null);

$valid();
$g = $guard();
$g->enforce();
$done    = $g->finish(new JsonResponse(['status' => 'ok'], 200, ['access-control-allow-origin' => '*']));
$headers = $readPrivate($done, 'headers');
$check('B24 finish(): the answer carries the checked origin', ($headers['Access-Control-Allow-Origin'] ?? '') === 'https://wohngut.ch');
$check('B25 … and the action\'s own ACAO (any case) is replaced', !isset($headers['access-control-allow-origin']));
$check('B26 … never Allow-Credentials', !isset($headers['Access-Control-Allow-Credentials']));

$threw = false;
try {
    $g->finish((new ReflectionClass(HtmlResponse::class))->newInstanceWithoutConstructor());
} catch (LogicException) {
    $threw = true;
}
$check('B27 finish(): an HTML answer throws', $threw);

$valid();
$threw = false;
try { (new CrossOriginPostGuard(ROUTE, HarnessNotAPolicy::class))->enforce(); } catch (LogicException) { $threw = true; }
$check('B28 a policy class without the interface throws', $threw);

// ── C. CrossOriginPostDoor — the two keys ──
$section('C. CrossOriginPostDoor — the two keys');

final class HarnessWidgetController
{
    #[CrossOriginPost]
    #[HttpMethod('POST')]
    public function enquiryAction(): void {}

    #[HttpMethod('POST')]
    public function plainAction(): void {}

    #[CrossOriginPost]
    #[HttpMethod('GET', 'POST')]
    public function getToo(): void {}

    #[CrossOriginPost]
    public function noMethod(): void {}

    #[CrossOriginPost]
    #[HttpMethod('POST')]
    #[Csrf]
    public function withCsrf(): void {}
}

$modules = function (array $frontendConfig): ModuleManager {
    $mm = new class extends ModuleManager {
        public array $fake = [];
        public function __construct() {}
        public function getModuleConfig(string $moduleKey): ?Config
        {
            return isset($this->fake[$moduleKey]) ? new Config($this->fake[$moduleKey]) : null;
        }
    };
    $mm->fake = ['frontend' => $frontendConfig];
    return $mm;
};

$door = function (string $method, array $config) use ($modules, $requestWith, $setPrivate): ?string {
    $request = $requestWith('post', RequestMode::Fetch);
    $handler = new ControllerHandler($modules([]));
    $setPrivate($handler, 'currentModule', 'frontend');
    $setPrivate($handler, 'currentControllerClassName', HarnessWidgetController::class);
    $setPrivate($handler, 'currentActionMethod', $method);
    return CrossOriginPostDoor::policyFor($request, $handler, $modules($config));
};
$throws = function (callable $fn): bool {
    try { $fn(); } catch (LogicException) { return true; }
    return false;
};
$entry = [CrossOriginPostDoor::CONFIG_KEY => [ROUTE => HarnessOriginPolicy::class]];

$check('C1 attribute + entry → the policy', $door('enquiryAction', $entry) === HarnessOriginPolicy::class);
$check('C2 neither key → null (an ordinary action)', $door('plainAction', []) === null);
$check('C3 attribute without entry → throws', $throws(fn() => $door('enquiryAction', [])));
$check('C4 entry without attribute → throws', $throws(fn() => $door('plainAction', $entry)));
$check('C5 entry naming a class without the interface → throws',
    $throws(fn() => $door('enquiryAction', [CrossOriginPostDoor::CONFIG_KEY => [ROUTE => HarnessNotAPolicy::class]])));
$check('C6 #[HttpMethod(GET, POST)] → throws', $throws(fn() => $door('getToo', $entry)));
$check('C7 no #[HttpMethod] → throws', $throws(fn() => $door('noMethod', $entry)));
$check('C8 #[Csrf] on the door → throws', $throws(fn() => $door('withCsrf', $entry)));
$check('C9 a missing action method → null (the Dispatcher says not found)', $door('nope', $entry) === null);
$check('C10 route() is group/controller/action', CrossOriginPostDoor::route($requestWith('post', RequestMode::Fetch)) === ROUTE);

// ── D. Request ──
$section('D. Request');

$r = $requestWith('post', RequestMode::Fetch);
$setPrivate($r, 'statelessRoute', false);
$check('D1 not stateless by default', $r->isStateless() === false && $r->isCrossOriginPost() === false);
$r->enterCrossOriginPost();
$check('D2 stateless after enterCrossOriginPost()', $r->isStateless() === true && $r->isCrossOriginPost() === true);

$_SERVER['CONTENT_TYPE'] = ' Application/X-WWW-Form-Urlencoded ; charset=UTF-8';
$check('D3 content type lowercase, without parameters', $r->getContentType() === 'application/x-www-form-urlencoded');
unset($_SERVER['CONTENT_TYPE']);
$check('D4 no content type → \'\'', $r->getContentType() === '');
$_SERVER['CONTENT_LENGTH'] = '12a';
$check('D5 a non-numeric length → null', $r->getContentLength() === null);
$_SERVER['CONTENT_LENGTH'] = '512';
$check('D6 a numeric length → int', $r->getContentLength() === 512);

// ── E. Fence ──
$section('E. Fence');

$users = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../packages', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    if (preg_match('~^\s*#\[(\\\\?Z77\\\\Shared\\\\Attributes\\\\)?CrossOriginPost\b~m', (string) file_get_contents($file->getPathname())) === 1) {
        $users[] = $file->getPathname();
    }
}
$check('E1 no framework action carries #[CrossOriginPost]' . ($users === [] ? '' : ': ' . implode(', ', $users)), $users === []);

// ── cleanup ──────────────────────────────────────────────────────────────────

$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $p) {
        is_dir($p) ? $rm($p) : unlink($p);
    }
    @rmdir($dir);
};
$rm($root);

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
