<?php

/**
 * Alias-first routing harness (CLI) — slug translation applies to the ALIAS PART of
 * a URL and to nothing else (ADR-014 / ADR-015 amendment 2026-09-17,
 * docs/03-development/plan-alias-first-routing.md).
 *
 * What is load-bearing here:
 *
 *   - an alias matches its EXACT path; only `accepts_slugs` makes it a prefix —
 *     `/kontakt/foo` is a miss, `/referenzen/mein_name` hands `[mein_name]` on;
 *   - non-default language: translate, THEN look the alias up; a miss means "not an
 *     alias" and the caller resolves the segments as written — a localized word
 *     equal to a controller name (`kontakt → contact`) can no longer break
 *     `/fr/frontend/main/contact/get-form`;
 *   - the remainder behind an alias is raw both ways (never through the table);
 *   - the raw spelling is tried after the translated one, so an alias whose own path
 *     is a localized word stays reachable;
 *   - ROUND TRIP: every URL `localizedUrl()` emits resolves back to the same
 *     navigation and the same slugs;
 *   - a dangling alias is a miss on BOTH sides;
 *   - `localizedUrl()` carries ?query and #fragment, leaves technical and external
 *     URLs alone, and works with pullUpServices()-level services only (CLI/mail).
 *
 * Run: php tests/routing-alias-first.php
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
use Z77\Core\Routing\AliasPathResolver;
use Z77\Core\Routing\Router;
use Z77\Core\Services\I18n;
use Z77\Core\Services\NavigationService;
use Z77\Core\Services\NavigationUrlResolver;
use Z77\Core\Services\SlugTranslator;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Entities\NavigationAlias;

// ── fixture: slug table on disk (SlugTranslator reads ABS_BASE_PATH) ─────────
$base = sys_get_temp_dir() . '/z77-alias-first-' . getmypid();
@mkdir($base . '/data/framework/i18n', 0777, true);
define('ABS_BASE_PATH', $base);
define('DEBUG', false);
file_put_contents($base . '/data/framework/i18n/route-slugs.fr.json', json_encode([
    'kontakt'    => 'contact',
    'wohnen'     => 'habiter',
    'referenzen' => 'references',
    'schweiz'    => 'suisse',
    'stadt'      => 'ville',
]));
register_shutdown_function(static function () use ($base): void {
    @unlink($base . '/data/framework/i18n/route-slugs.fr.json');
    @rmdir($base . '/data/framework/i18n');
    @rmdir($base . '/data/framework');
    @rmdir($base . '/data');
    @rmdir($base);
});

// ── fixture: aliases + navigations (no repository, no cache) ─────────────────
$aliasRows = [
    ['id' => 1, 'navigation_id' => 3,  'path' => '/home'],
    ['id' => 2, 'navigation_id' => 29, 'path' => '/kontakt'],
    ['id' => 3, 'navigation_id' => 25, 'path' => '/wohnen'],
    ['id' => 4, 'navigation_id' => 40, 'path' => '/referenzen',        'accepts_slugs' => true],
    ['id' => 5, 'navigation_id' => 41, 'path' => '/referenzen/archiv'],                       // exact-only, deeper
    ['id' => 6, 'navigation_id' => 42, 'path' => '/schweiz/stadt'],
    ['id' => 7, 'navigation_id' => 43, 'path' => '/habiter'],                                  // own path = a localized word
    ['id' => 8, 'navigation_id' => 99, 'path' => '/verwaist'],                                 // dangling: no navigation 99
    ['id' => 9, 'navigation_id' => 44, 'path' => '/aus', 'active' => false],
];
$aliases = array_map(static fn(array $row) => new NavigationAlias($row), $aliasRows);

$urlResolver = new class($aliases) extends NavigationUrlResolver {
    public function __construct(private array $rows) {}
    public function findByAliasPath(string $path): ?NavigationAlias
    {
        foreach ($this->rows as $alias) {
            if ($alias->getPath() === $path && $alias->isActive()) {
                return $alias;
            }
        }
        return null;
    }
};

$navigationService = new class extends NavigationService {
    public function __construct() {}
    public function findById(int $id): ?Navigation
    {
        if ($id === 99) {
            return null;
        }
        $nav = new Navigation();
        (new ReflectionProperty(Navigation::class, 'id'))->setValue($nav, $id);
        return $nav;
    }
};

$i18n = new class extends I18n {
    public function __construct() {}
    public function getDefaultLanguage(): string { return 'de'; }
};

$resolver = new AliasPathResolver(new Router($navigationService, $urlResolver), new SlugTranslator(), $i18n);

// localizedUrl() reads these two from the container — pullUpServices()-level only.
DI::getInstance(true)
    ->set('I18n', $i18n, true)
    ->set('AliasPathResolver', $resolver, true);
require __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

// ── tiny assert ──────────────────────────────────────────────────────────────
$failed = 0;
$check = static function (string $label, mixed $expected, mixed $actual) use (&$failed): void {
    if ($expected === $actual) {
        echo "  ok    {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}\n        expected " . json_encode($expected) . "\n        actual   " . json_encode($actual) . "\n";
};
/** [navigationId, slugs, localized] or null */
$in = static function (string $path, string $lang) use ($resolver): ?array {
    $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
    $hit = $resolver->resolve($segments, $lang);
    return $hit === null ? null : [$hit['navigation']->getId(), $hit['slugs'], '/' . implode('/', $hit['localized'])];
};

echo "inbound — default language\n";
$check('/kontakt exact',                        [29, [], '/kontakt'],                     $in('/kontakt', 'de'));
$check('/contact is not an alias in de',        null,                                     $in('/contact', 'de'));
$check('/kontakt/foo: exact-only alias, MISS',  null,                                     $in('/kontakt/foo', 'de'));
$check('/referenzen exact',                     [40, [], '/referenzen'],                  $in('/referenzen', 'de'));
$check('/referenzen/mein_name → slugs',         [40, ['mein_name'], '/referenzen/mein_name'], $in('/referenzen/mein_name', 'de'));
$check('/referenzen/a/b → two slugs',           [40, ['a', 'b'], '/referenzen/a/b'],      $in('/referenzen/a/b', 'de'));
$check('/referenzen/0 keeps the slug "0"',      [40, ['0'], '/referenzen/0'],             $in('/referenzen/0', 'de'));
$check('/referenzen/archiv: deeper exact wins', [41, [], '/referenzen/archiv'],           $in('/referenzen/archiv', 'de'));
$check('/referenzen/archiv/x: exact-only skipped, prefix alias takes it',
                                                [40, ['archiv', 'x'], '/referenzen/archiv/x'], $in('/referenzen/archiv/x', 'de'));
$check('technical path is not an alias',        null,                                     $in('/frontend/main/contact/get-form', 'de'));
$check('inactive alias is a miss',              null,                                     $in('/aus', 'de'));
$check('dangling alias is a miss',              null,                                     $in('/verwaist', 'de'));
$check('no segments',                           null,                                     $in('/', 'de'));

echo "inbound — fr\n";
$check('/fr/contact → /kontakt',                [29, [], '/contact'],                     $in('/contact', 'fr'));
$check('/fr/kontakt hits, localized form differs (→ 301)',
                                                [29, [], '/contact'],                     $in('/kontakt', 'fr'));
$check('/fr/contact/foo MISS',                  null,                                     $in('/contact/foo', 'fr'));
$check('/fr/references/mein_name: remainder raw', [40, ['mein_name'], '/references/mein_name'], $in('/references/mein_name', 'fr'));
$check('/fr/referenzen/mein_name → localized alias part',
                                                [40, ['mein_name'], '/references/mein_name'], $in('/referenzen/mein_name', 'fr'));
$check('/fr/references/contact: slug NOT translated',
                                                [40, ['contact'], '/references/contact'], $in('/references/contact', 'fr'));
$check('/fr/suisse/ville multi-segment',        [42, [], '/suisse/ville'],                $in('/suisse/ville', 'fr'));
$check('/fr/suisse/stadt partially localized',  [42, [], '/suisse/ville'],                $in('/suisse/stadt', 'fr'));
$check('/fr/home without table entry',          [3, [], '/home'],                         $in('/home', 'fr'));
$check('FIXED: /fr/frontend/main/contact/get-form is not an alias',
                                                null,                                     $in('/frontend/main/contact/get-form', 'fr'));
$check('FIXED: /fr/frontend/main/contact/danke is not an alias',
                                                null,                                     $in('/frontend/main/contact/danke', 'fr'));
// `habiter` is BOTH the localized form of /wohnen and an alias path of its own:
// translated wins (documented data conflict) …
$check('/fr/habiter → translated spelling wins', [25, [], '/habiter'],                    $in('/habiter', 'fr'));

echo "outbound\n";
$check('alias',                  '/fr/contact',                          localizedUrl('/kontakt', 'fr'));
$check('alias, default lang',    '/kontakt',                             localizedUrl('/kontakt', 'de'));
$check('alias + query/fragment', '/fr/contact?x=1&y=2#f',                localizedUrl('/kontakt?x=1&y=2#f', 'fr'));
$check('alias + slugs',          '/fr/references/mein_name',             localizedUrl('/referenzen/mein_name', 'fr'));
$check('slug equal to a table key stays raw', '/fr/references/kontakt',  localizedUrl('/referenzen/kontakt', 'fr'));
$check('multi-segment alias',    '/fr/suisse/ville',                     localizedUrl('/schweiz/stadt', 'fr'));
$check('technical: prefix only', '/fr/frontend/main/contact/get-form',   localizedUrl('/frontend/main/contact/get-form', 'fr'));
$check('technical with a table key stays raw', '/fr/frontend/main/index/wohnen', localizedUrl('/frontend/main/index/wohnen', 'fr'));
$check('exact-only alias + remainder is not an alias', '/fr/kontakt/foo', localizedUrl('/kontakt/foo', 'fr'));
$check('dangling alias: not localized', '/fr/verwaist',                  localizedUrl('/verwaist', 'fr'));
$check('root',                   '/fr',                                  localizedUrl('/', 'fr'));
$check('root, default',          '/',                                    localizedUrl('/', 'de'));
$check('root + query',           '/fr?x=1',                              localizedUrl('/?x=1', 'fr'));
$check('external untouched',     'https://example.org/kontakt',          localizedUrl('https://example.org/kontakt', 'fr'));
$check('anchor untouched',       '#',                                    localizedUrl('#', 'fr'));
$check('mailto untouched',       'mailto:a@b.ch',                        localizedUrl('mailto:a@b.ch', 'fr'));

echo "round trip — every emitted alias URL resolves to the same navigation + slugs\n";
foreach (['/home', '/kontakt', '/wohnen', '/referenzen', '/referenzen/mein_name', '/referenzen/archiv', '/schweiz/stadt'] as $canonical) {
    $emitted = localizedUrl($canonical, 'fr');                 // '/fr/…'
    $back    = $in(substr($emitted, 3), 'fr');
    $direct  = $in($canonical, 'de');
    $check("{$canonical} → {$emitted}", [$direct[0], $direct[1]], $back === null ? null : [$back[0], $back[1]]);
    $check("{$emitted} is already the single form", substr($emitted, 3), $back[2] ?? null);
}

echo "alternative spelling — an alias whose own path is a localized word\n";
// Without /wohnen the translated lookup of /fr/habiter misses; the raw spelling must hit.
$soloResolver = new AliasPathResolver(
    new Router($navigationService, new class(array_values(array_filter($aliases, static fn($a) => $a->getPath() !== '/wohnen'))) extends NavigationUrlResolver {
        public function __construct(private array $rows) {}
        public function findByAliasPath(string $path): ?NavigationAlias
        {
            foreach ($this->rows as $alias) {
                if ($alias->getPath() === $path && $alias->isActive()) {
                    return $alias;
                }
            }
            return null;
        }
    }),
    new SlugTranslator(),
    $i18n
);
$hit = $soloResolver->resolve(['habiter'], 'fr');
$check('/fr/habiter reaches alias /habiter via the raw spelling', 43, $hit === null ? null : $hit['navigation']->getId());
$check('… and is its own single form (no 301 loop)', ['habiter'], $hit['localized'] ?? null);

echo "entity\n";
$check('accepts_slugs defaults to false for a row without the key', false, (new NavigationAlias(['path' => '/x']))->acceptsSlugs());
$check('accepts_slugs is persisted under its snake_case key', true, (new NavigationAlias(['path' => '/x', 'accepts_slugs' => true]))->mapToArray()['accepts_slugs']);

echo $failed === 0 ? "\nALL OK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
