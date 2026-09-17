<?php

/**
 * Language-session harness (CLI) — `Request::applyLanguageSession()`
 * (ADR-013/ADR-015, I18N-SWITCH-DEFAULT-001).
 *
 * What is load-bearing here:
 *
 *   - a URL prefix remembers its language; a prefix-less PAGE (the default
 *     language has no prefix) forgets it again — so `/fr` → «Deutsch» (`/home`)
 *     → logo (`/`) stays German;
 *   - the bare root redirects to the remembered non-default language, and only
 *     the bare root;
 *   - a Fetch call, a POST and a reserved route (a /media file in a new tab) do
 *     NOT reset the memory — they say nothing about the visitor's language;
 *   - the rendered language is never taken from the session (not tested here —
 *     `language` stays what extractLanguage() set).
 *
 * Run: php tests/language-session.php
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

use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Services\I18n;
use Z77\Core\Session\SessionManager;

$i18n = new class extends I18n {
    public function __construct() {}
    public function getDefaultLanguage(): string { return 'de'; }
    public function isValidLanguage(string $code): bool { return in_array($code, ['de', 'fr', 'en'], true); }
};
DI::getInstance(true)->set('I18n', $i18n, true);

$session = new SessionManager();   // CLI: no real session, $_SESSION is a plain array
$_SESSION = [];

/**
 * One request as the Dispatcher sees it after parsing.
 *
 * @param list<string> $segments path segments after the language prefix
 */
$request = static function (array $segments, ?string $prefix, string $method = 'get',
                            RequestMode $mode = RequestMode::Page, bool $reserved = false): Request {
    $ref = new ReflectionClass(Request::class);
    $req = $ref->newInstanceWithoutConstructor();
    $set = static function (string $prop, mixed $value) use ($ref, $req): void {
        $ref->getProperty($prop)->setValue($req, $value);
    };
    $set('pathSegments', $segments);
    $set('language', $prefix ?? 'de');
    $set('languageFromUrl', $prefix !== null);
    $set('method', $method);
    $set('mode', $mode);
    $set('reservedRoute', $reserved);
    return $req;
};

$failed = 0;
$check = static function (string $label, mixed $expected, mixed $actual) use (&$failed): void {
    if ($expected === $actual) {
        echo "  ok    {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}\n        expected " . json_encode($expected) . "\n        actual   " . json_encode($actual) . "\n";
};
$visit = static fn(Request $r): ?string => $r->applyLanguageSession($session);
$remembered = static fn(): mixed => $_SESSION['language'] ?? null;

echo "the reported click path\n";
$_SESSION = [];
$check('/ (fresh visitor) renders, no redirect', null, $visit($request([], null)));
$check('/fr remembers fr', null, $visit($request([], 'fr')));
$check('… remembered', 'fr', $remembered());
$check('logo / → 302 /fr', '/fr', $visit($request([], null)));
$check('«Deutsch» /home renders', null, $visit($request(['home'], null)));
$check('… and forgets fr', null, $remembered());
$check('logo / stays German (was: 302 /fr)', null, $visit($request([], null)));

echo "prefix-less pages\n";
$_SESSION = ['language' => 'fr'];
$visit($request(['kontakt'], null));
$check('/kontakt (GET, page) forgets', null, $remembered());
$_SESSION = ['language' => 'fr'];
$visit($request(['kontakt'], null, 'head'));
$check('HEAD is a read → forgets', null, $remembered());

echo "requests that must NOT reset\n";
$_SESSION = ['language' => 'fr'];
$visit($request(['frontend', 'main', 'contact', 'check'], null, 'post', RequestMode::Fetch));
$check('Fetch blur check (POST, no prefix)', 'fr', $remembered());
$visit($request(['frontend', 'main', 'unit', 'detail'], null, 'get', RequestMode::Fetch));
$check('Fetch GET (no prefix)', 'fr', $remembered());
$visit($request(['kontakt'], null, 'post'));
$check('POST to a prefix-less page', 'fr', $remembered());
$visit($request(['media', 'front', 'docs', 'plan.pdf'], null, 'get', RequestMode::Page, true));
$check('/media file opened in a tab (reserved route)', 'fr', $remembered());
$check('… so the root still goes to /fr', '/fr', $visit($request([], null)));

echo "prefixes\n";
$_SESSION = [];
$visit($request(['habiter'], 'fr'));
$check('/fr/habiter remembers fr', 'fr', $remembered());
$visit($request(['home'], 'en'));
$check('/en/home remembers en', 'en', $remembered());
$check('root → /en', '/en', $visit($request([], null)));
$visit($request(['frontend', 'main', 'contact', 'get-form'], 'fr', 'get', RequestMode::Fetch));
$check('a prefixed Fetch still remembers (unchanged behaviour)', 'fr', $remembered());

echo "root edge cases\n";
$_SESSION = ['language' => 'de'];
$check('remembered default → no redirect', null, $visit($request([], null)));
$_SESSION = ['language' => 'xx'];
$check('remembered invalid → no redirect', null, $visit($request([], null)));
$_SESSION = [];
$check('nothing remembered → no redirect', null, $visit($request([], null)));

echo $failed === 0 ? "\nALL OK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
