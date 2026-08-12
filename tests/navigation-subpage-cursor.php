<?php

/**
 * Sub-page cursor harness (CLI). Runs the real stack — Navigation entity,
 * CollectionStore, NavigationRepository, NavigationService.
 *
 * The bug (AXO3, 2026-08-12): `/backend/service/estate/tree?tenant=2` emptied
 * the whole left shell column. `resolveCurrent()` matches the 4-tuple exactly,
 * and `estate/tree` has no navigation entry of its own (the section lists
 * `estate/list` and `estate/widgets`). No entry → no cursor →
 * `getActiveSectionBySlot()` finds no section → `partials/subnav` returns early
 * and renders nothing at all.
 *
 * The fix gives the UI CURSOR a fallback: a sibling entry of the same
 * controller. Verified here:
 *   - the sub-page gets a cursor, and the section resolves again
 *   - `getCurrent()` stays null — SEO metadata and the canonical path hang off
 *     it, and a sub-page must not inherit its sibling's canonical URL
 *   - a page WITH its own entry is untouched (a real match always wins)
 *   - no sibling, or only inactive ones → no cursor (nothing is guessed)
 *
 * Run: php tests/navigation-subpage-cursor.php
 * Uses a throwaway data directory in the system temp; removed on success.
 */

$work = sys_get_temp_dir() . '/z77-subpage-cursor-' . getmypid();
define('ABS_BASE_PATH', $work);

$autoload = __DIR__ . '/../skeleton/vendor/autoload.php';
if (!is_file($autoload)) {
    // Same requirement as optimistic-locking.php: these tests run the real
    // stack through the skeleton's autoloader. Say so instead of dying in a
    // require — a missing scaffold is an environment gap, not a test failure.
    fwrite(STDERR, "skeleton/vendor fehlt — bitte einmal `composer install` in skeleton/ ausfuehren.\n");
    exit(2);
}
require $autoload;

use Z77\Core\Libraries\CacheManager,
    Z77\Core\Services\NavigationService,
    Z77\Core\Services\NavigationUrlResolver,
    Z77\Persistence\File\Storage\CollectionStore,
    Z77\Persistence\File\Storage\FileStorage,
    Z77\Shared\Attributes\Entity as EntityAttr,
    Z77\Shared\Entities\Navigation,
    Z77\Shared\Entities\NavigationAlias,
    Z77\Shared\Entities\MetaData,
    Z77\Shared\Repositories\MetaDataRepository,
    Z77\Shared\Repositories\NavigationAliasRepository,
    Z77\Shared\Repositories\NavigationRepository;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

$storeFor = static function (string $class): CollectionStore {
    $attr = (new ReflectionClass($class))->getAttributes(EntityAttr::class)[0]->newInstance();

    return new CollectionStore(new FileStorage(), $attr);
};

$cache = new CacheManager();
$cache->setCacheDir('lib/cache');

$navStore = $storeFor(Navigation::class);

/** Builds a service over the CURRENT store contents (the cache is per instance). */
$service = static function () use ($navStore, $storeFor, $cache): NavigationService {
    $cache->data()->clear('NavigationService');

    return new NavigationService(
        new NavigationRepository(Navigation::class, $navStore),
        new MetaDataRepository(MetaData::class, $storeFor(MetaData::class)),
        // Stubbed to the one method this path uses: `getActiveSectionBySlot()`
        // asks whether the slot is known before it looks. Booting the real
        // ModuleManager would drag in the ConfigManager and a module config —
        // neither has anything to do with the cursor.
        new class extends \Z77\Core\Services\ModuleManager {
            public function __construct() {}
            public function isKnownSlot(string $slot): bool { return true; }
        },
        new NavigationUrlResolver(
            new NavigationAliasRepository(NavigationAlias::class, $storeFor(NavigationAlias::class)),
            $cache
        ),
        $cache
    );
};

// ── the real shape: a section with two listed actions of one controller ──────

$section = new Navigation();
$section->setName('Service');
$section->setSlot('backend-main');

$list = new Navigation();
$list->setName('Bestand');
$list->setModule('backend');
$list->setGroup('service');
$list->setController('estate');
$list->setAction('list');

$widgets = new Navigation();
$widgets->setName('Widget-Snippets');
$widgets->setModule('backend');
$widgets->setGroup('service');
$widgets->setController('estate');
$widgets->setAction('widgets');

$navStore->persistAll([$section]);
$list->setParentId($section->getId());
$widgets->setParentId($section->getId());
$navStore->persistAll([$section, $list, $widgets]);

echo "1. sub-page without an entry of its own (estate/tree)\n";

$nav = $service();
$nav->resolveCurrent('backend', 'service', 'estate', 'tree');
$nav->resolveUiCurrent(null);

check('getCurrent() stays null — canonical path and SEO metadata hang off it',
    $nav->getCurrent() === null);
check('the UI cursor falls back to a sibling of the same controller',
    $nav->getUiCurrent()?->getId() === $list->getId());
check('exactly that sibling reads as active',
    $nav->isActive($list) && !$nav->isActive($widgets));
check('the section resolves again — this is what the subnav needs',
    $nav->getActiveSectionBySlot('backend-main')?->getId() === $section->getId());

echo "2. a page WITH its own entry is untouched\n";

$nav = $service();
$nav->resolveCurrent('backend', 'service', 'estate', 'widgets');
$nav->resolveUiCurrent(null);

check('a real match is never overwritten by the fallback',
    $nav->getCurrent()?->getId() === $widgets->getId()
    && $nav->getUiCurrent()?->getId() === $widgets->getId());

echo "3. nothing is guessed\n";

$nav = $service();
$nav->resolveCurrent('backend', 'service', 'unbekannt', 'list');
$nav->resolveUiCurrent(null);
check('a controller with no entry at all gets no cursor',
    $nav->getCurrent() === null && $nav->getUiCurrent() === null);

$off = new Navigation();
$off->setName('Abgeschaltet');
$off->setModule('backend');
$off->setGroup('service');
$off->setController('archiv');
$off->setAction('list');
$off->setActive(false);
$navStore->persistAll([$section, $list, $widgets, $off]);

$nav = $service();
$nav->resolveCurrent('backend', 'service', 'archiv', 'detail');
$nav->resolveUiCurrent(null);
check('an inactive sibling does not become the cursor — iterateTree() skips it, so the section would stay empty anyway',
    $nav->getUiCurrent() === null);

echo "\n{$pass} passed, {$fail} failed\n";

if ($fail === 0) {
    $rm = function (string $dir) use (&$rm): void {
        foreach (glob($dir . '/*') ?: [] as $f) { is_dir($f) ? $rm($f) : @unlink($f); }
        @rmdir($dir);
    };
    $rm($work);
}
exit($fail === 0 ? 0 : 1);
