<?php

/**
 * Backend menu conditions harness (CLI, no database) — the data half of the menu rule
 * (owner 2026-11-24, backend.md «menu follows access»).
 *
 * What is load-bearing here:
 *
 *   A. MenuConditions (kernel `Z77\Shared\Navigation`): a route without a condition is visible;
 *      a condition that says no hides it; a condition that THROWS counts as visible and is logged;
 *      a condition is instantiated only when its route is asked and answers once per instance;
 *      two conditions on one route must both say visible; a bad declaration fails loudly.
 *   B. BackendMenu with conditions, over the real NavigationService and an in-memory tree: a hidden
 *      leaf, its ref, a section left without visible pages, the section URL skipping it, an href of
 *      '' — and the access check asked FIRST (no condition for a page the user may not open); an
 *      opener's own route is not asked while it has children (the existing rule).
 *   C. module-financial's VatReturnMenuCondition over VatReturnService::settingsRefusal(): hidden
 *      only when not liable without «Abrechnung bis»; shown when liable (complete or not), when the
 *      method is not supported, with no record, and when the liability ended on a past day (review
 *      U5); the registration in financialConfig matches the seed entry `mwst-abrechnungen`.
 *
 * Run: php tests/run.php backend-menu-conditions   (or php tests/backend-menu-conditions.php)
 * No DI: the classes are required through a PSR-4 map of this checkout.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Module\\Backend\\'   => '/../packages/module-backend/src/',
        'Z77\\Module\\Financial\\' => '/../packages/module-financial/src/',
        'Z77\\Module\\Mandator\\'  => '/../packages/module-mandator/src/',
        'Z77\\Shared\\'            => '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\'       => '/../packages/kernel/persistence/src/',
        'Z77\\Core\\'              => '/../packages/kernel/core/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Module\Backend\Ui\BackendMenu;
use Z77\Module\Financial\Ui\VatReturnMenuCondition;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Navigation\MenuCondition;
use Z77\Shared\Navigation\MenuConditions;
use Z77\Shared\Tree\TreeService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — {$got}" : '') . "\n"; }
}

/** The exception message of $fn, '' when it does not throw. */
function thrown(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

// The error log goes to a throwaway file of this run (a throwing condition is logged there).
$logFile = sys_get_temp_dir() . '/z77-menu-conditions-' . getmypid() . '.log';
ini_set('log_errors', '1');
ini_set('error_log', $logFile);
register_shutdown_function(static function () use ($logFile): void {
    if (is_file($logFile)) {
        unlink($logFile);
    }
});

// Conditions as classes (the registry instantiates by class name, without arguments).
final class ShownCondition implements MenuCondition
{
    public static int $made = 0;
    public function __construct() { self::$made++; }
    public function visible(): bool { return true; }
}
final class HiddenCondition implements MenuCondition
{
    public static int $made = 0;
    public function __construct() { self::$made++; }
    public function visible(): bool { return false; }
}
final class BrokenCondition implements MenuCondition
{
    public function visible(): bool { throw new \RuntimeException('mandator table gone'); }
}
final class NeedsArgumentCondition implements MenuCondition
{
    public function __construct(string $x) {}
    public function visible(): bool { return true; }
}
final class NotACondition
{
    public function visible(): bool { return true; }
}

// ─────────────────────────────────────────────────────────────────────────
echo "A. MenuConditions — the registry\n";

$registry = new MenuConditions([
    'backend/finance/vat-return/list' => HiddenCondition::class,
    'backend/finance/journal/list'    => ShownCondition::class,
    'backend/finance/broken/list'     => BrokenCondition::class,
]);
check('A1 a route without a condition is visible (the menu as before)', $registry->visible('backend', 'finance', 'account', 'list'));
check('A2 a condition that says no hides its route', !$registry->visible('backend', 'finance', 'vat-return', 'list'));
check('A3 a condition that says yes shows its route', $registry->visible('backend', 'finance', 'journal', 'list'));
check('A4 the route is matched exactly — another action of the same controller is not asked', $registry->visible('backend', 'finance', 'vat-return', 'form'));

HiddenCondition::$made = 0;
$fresh = new MenuConditions(['backend/finance/vat-return/list' => HiddenCondition::class]);
check('A5 nothing is instantiated before its route is asked', HiddenCondition::$made === 0);
$fresh->visible('backend', 'finance', 'vat-return', 'list');
$fresh->visible('backend', 'finance', 'vat-return', 'list');
$fresh->visible('backend', 'finance', 'vat-return', 'list');
check('A6 a condition answers once per instance (= once per request), however often the menu asks', HiddenCondition::$made === 1, (string) HiddenCondition::$made);

$before = is_file($logFile) ? (string) file_get_contents($logFile) : '';
$broken = $registry->visible('backend', 'finance', 'broken', 'list');
$after  = is_file($logFile) ? (string) file_get_contents($logFile) : '';
$logged = substr($after, strlen($before));
check('A7 a condition that throws counts as visible — the menu never dies on it', $broken === true);
check('A8 … and is logged with its class, its route and the reason', str_contains($logged, 'BrokenCondition') && str_contains($logged, "'backend/finance/broken/list'") && str_contains($logged, 'mandator table gone'), trim($logged));

ShownCondition::$made = 0;
HiddenCondition::$made = 0;
$both = new MenuConditions(['backend/finance/vat-return/list' => ShownCondition::class]);
(new ReflectionMethod(MenuConditions::class, 'addAll'))->invoke($both, ['backend/finance/vat-return/list' => HiddenCondition::class], 'menuConditions extension');
check('A9 two conditions on one route (two modules / an extension file) must BOTH say visible', !$both->visible('backend', 'finance', 'vat-return', 'list') && ShownCondition::$made === 1 && HiddenCondition::$made === 1);

check('A10 a list instead of a route map is refused', str_contains(thrown(fn() => new MenuConditions([HiddenCondition::class])), "'module/group/controller/action'"));
check('A11 a key that is no 4-part route is refused (a seed key, a controller only)',
    str_contains(thrown(fn() => new MenuConditions(['mwst-abrechnungen' => HiddenCondition::class])), 'is not a route')
    && str_contains(thrown(fn() => new MenuConditions(['backend/finance/vat-return' => HiddenCondition::class])), 'is not a route')
    && str_contains(thrown(fn() => new MenuConditions(['backend//vat-return/list' => HiddenCondition::class])), 'is not a route'));
check('A12 a missing class is refused', str_contains(thrown(fn() => new MenuConditions(['a/b/c/d' => 'No\\Such\\Condition'])), 'non-existent class'));
check('A13 a class that is no MenuCondition is refused', str_contains(thrown(fn() => new MenuConditions(['a/b/c/d' => NotACondition::class])), 'does not implement'));
check('A14 a condition whose constructor needs arguments is refused', str_contains(thrown(fn() => new MenuConditions(['a/b/c/d' => NeedsArgumentCondition::class])), 'without arguments'));

// ─────────────────────────────────────────────────────────────────────────
echo "B. BackendMenu with conditions\n";

function nav(int $id, ?int $parent, string $name, string $target = '', ?int $ref = null): Navigation
{
    $parts = $target === '' ? ['', '', '', ''] : explode('/', $target);
    $n = new Navigation(['name' => $name, 'module' => $parts[0], 'group' => $parts[1],
                         'controller' => $parts[2], 'action' => $parts[3], 'ref' => $ref]);
    $n->setParentId($parent);
    $n->setSortKey($id);
    (new ReflectionProperty(Navigation::class, 'id'))->setValue($n, $id);
    return $n;
}

// Finanzen: Journal, MWST-Abrechnungen (condition), Bilanz (admin only in this test)
// MWST: only the VAT return — a section that loses its one page
// Stammdaten: a ref to the VAT return, an opener «Kontenplan» whose OWN route carries a hidden
//             condition but which has a visible child
$entries = [
    nav(1, null, 'Finanzen'),
    nav(11, 1, 'Journal', 'backend/finance/journal/list'),
    nav(12, 1, 'MWST-Abrechnungen', 'backend/finance/vat-return/list'),
    nav(13, 1, 'Bilanz', 'backend/finance/report/balance-sheet'),
    nav(2, null, 'MWST'),
    nav(21, 2, 'Abrechnungen', 'backend/finance/vat-return/list'),
    nav(3, null, 'Stammdaten'),
    nav(31, 3, 'MWST (Verweis)', '', 12),
    nav(32, 3, 'Kontenplan', 'backend/finance/account/list'),
    nav(33, 32, 'Kontenklassen', 'backend/finance/account/classes'),
];

$dataCache = new class($entries) extends \Z77\Core\Libraries\Cache\DataCache {
    public function __construct(private array $entries) {}
    public function get(string $className, array $components) { return $this->entries; }
};
$cacheManager = (new ReflectionClass(\Z77\Core\Libraries\CacheManager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Z77\Core\Libraries\CacheManager::class, 'data'))->setValue($cacheManager, $dataCache);
$urlResolver = new class extends \Z77\Core\Services\NavigationUrlResolver {
    public function __construct() {}
    public function urlFor(Navigation $entry): string { return $entry->getUrl(); }
};
$navService = (new ReflectionClass(\Z77\Core\Services\NavigationService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(\Z77\Core\Services\NavigationService::class, 'cacheManager'))->setValue($navService, $cacheManager);
(new ReflectionProperty(\Z77\Core\Services\NavigationService::class, 'urlResolver'))->setValue($navService, $urlResolver);
(new ReflectionProperty(\Z77\Core\Services\NavigationService::class, 'navTree'))->setValue($navService, new TreeService(fn(Navigation $n) => $n->getSlot()));

$byId = [];
foreach ($entries as $e) { $byId[$e->getId()] = $e; }

/** Records the routes the access check was asked for; the balance sheet is refused. */
$asked     = [];
$reachable = function (string $m, string $g, string $c, string $a) use (&$asked): bool {
    $asked[] = "{$m}/{$g}/{$c}/{$a}";
    return $c !== 'report';
};
$menuWith = fn(?MenuConditions $conditions): BackendMenu => new BackendMenu($navService, 'backend-main', Closure::fromCallable($reachable), $conditions);
$names    = fn(BackendMenu $menu, int $id): string => implode(', ', array_map(fn(Navigation $e) => $e->getName(), $menu->children($byId[$id])));

$plain = $menuWith(null);
check('B1 without a registry (or without a condition) everything reachable is shown, as before',
    $plain->isVisible($byId[12]) && $plain->isVisible($byId[2]) && $plain->isVisible($byId[31]) && $names($plain, 1) === 'Journal, MWST-Abrechnungen', $names($plain, 1));
check('B2 an empty registry changes nothing', $menuWith(new MenuConditions())->isVisible($byId[12]));

$menu = $menuWith(new MenuConditions([
    'backend/finance/vat-return/list' => HiddenCondition::class,
    'backend/finance/account/list'    => HiddenCondition::class,
]));
check('B3 a leaf whose route\'s condition says no is hidden', !$menu->isVisible($byId[12]));
check('B4 … its siblings stay, in order', $names($menu, 1) === 'Journal', $names($menu, 1));
check('B5 … its href is empty (the subnav renders no link)', $menu->href($byId[12]) === '');
check('B6 a ref to it is hidden too (asked through its target\'s route)', !$menu->isVisible($byId[31]));
check('B7 a section whose only page is hidden disappears', !$menu->isVisible($byId[2]) && $menu->children($byId[2]) === []);
check('B8 the section URL skips the hidden page (first page the user may open AND is shown)',
    $navService->resolveFirstNavigable($byId[2], fn(Navigation $e) => $menu->allows($e)) === null
    && $navService->resolveFirstNavigable($byId[1], fn(Navigation $e) => $menu->allows($e))?->getId() === 11);
check('B9 an opener is not hidden by a condition on its OWN route while it has a visible child (its target is never asked — the existing rule)',
    $menu->isVisible($byId[32]) && $names($menu, 3) === 'Kontenplan' && $names($menu, 32) === 'Kontenklassen', $names($menu, 3));

HiddenCondition::$made = 0;
$asked = [];
$adminOnly = $menuWith(new MenuConditions(['backend/finance/report/balance-sheet' => HiddenCondition::class]));
$adminOnly->isVisible($byId[13]);
check('B10 the access check comes first: a page the user may not open never asks its condition',
    in_array('backend/finance/report/balance-sheet', $asked, true) && HiddenCondition::$made === 0);

$brokenMenu = $menuWith(new MenuConditions(['backend/finance/vat-return/list' => BrokenCondition::class]));
check('B11 a throwing condition leaves the entry in the menu', $brokenMenu->isVisible($byId[12]) && $brokenMenu->href($byId[12]) !== '' && $brokenMenu->isVisible($byId[31]));

$shell = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/BackendMenu.php');
check('B12 the menu of every backend page carries the registry of all modules (forCurrentUser)',
    preg_match('/function forCurrentUser.*?MenuConditions::fromModules\(DI::getModuleManager\(\)\)/s', $shell) === 1);

// ─────────────────────────────────────────────────────────────────────────
echo "C. module-financial — «MWST-Abrechnungen» only for a mandator that settles VAT\n";

$day = static fn(string $d): \DateTimeImmutable => new \DateTimeImmutable($d);
// settingsRefusal() reads only the settings handed in — the entity manager is never asked.
$em = (new ReflectionClass(UnifiedEntityManager::class))->newInstanceWithoutConstructor();
$shownFor = static fn(VatSettings $s): bool => (new VatReturnMenuCondition(new VatReturnService($em, $s)))->visible();
$settings = static fn(bool $liable = true, ?VatMethod $method = VatMethod::Effective, ?string $until = null, ?string $from = '2026-01-01'): VatSettings
    => new VatSettings($liable, $method, VatConsideration::Agreed, VatRhythm::Quarter, $from === null ? null : $day($from), $until === null ? null : $day($until));

check('C1 liable, settings complete → shown', $shownFor($settings()));
check('C2 NOT liable, no «Abrechnung bis» → hidden', !$shownFor($settings(liable: false)));
check('C3 no longer liable, «Abrechnung bis» on a past day → shown (review U5: the periods up to that day stay)', $shownFor($settings(liable: false, until: '2025-12-31', from: '2024-01-01')));
check('C4 liable but «Abrechnungsmethode» not set → shown (the page names what is missing)', $shownFor($settings(method: null)));
check('C5 liable but «Abrechnung ab» not set → shown', $shownFor($settings(from: null)));
check('C6 a method z77 does not build (net tax rate) → shown (the page says so)', $shownFor($settings(method: VatMethod::NetTaxRate)));
check('C7 no mandator saved (VatSettings::none(), liable by default) → shown', $shownFor(VatSettings::none()));
check('C8 not liable with an incomplete record and no «bis» → hidden (liability decides first)', !$shownFor(new VatSettings(false, null, null, null, null, null)));

$config     = require __DIR__ . '/../packages/module-financial/src/App/Config/financialConfig.inc.php';
$registered = $config[MenuConditions::CONFIG_KEY] ?? [];
$seed       = json_decode((string) file_get_contents(__DIR__ . '/../packages/module-financial/data/framework/routing/navigation.d/module-financial.json'), true);
$seedEntry  = array_values(array_filter($seed, fn(array $e) => ($e['key'] ?? '') === 'mwst-abrechnungen'))[0] ?? [];
$seedRoute  = implode('/', [$seedEntry['module'] ?? '', $seedEntry['group'] ?? '', $seedEntry['controller'] ?? '', $seedEntry['action'] ?? '']);
check('C9 financialConfig registers the condition under `menuConditions` for the route of the seed entry `mwst-abrechnungen`',
    $registered === [$seedRoute => VatReturnMenuCondition::class] && $seedRoute === 'backend/finance/vat-return/list', json_encode($registered) . ' / ' . $seedRoute);
check('C10 the declaration passes the registry\'s own checks', thrown(fn() => new MenuConditions($registered)) === '');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
