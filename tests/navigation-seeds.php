<?php

/**
 * Navigation seeds harness (CLI) — ADR-050, NAV-SEED-001.
 *
 * What is load-bearing here:
 *
 *   - every package ships its menu entries in `navigation.d/<package>.json`, keyed, the
 *     parent named by `parent_key` — the union has unique keys and every parent resolves;
 *   - a FRESH installation (the kernel's navigation.default.json + the installer step)
 *     gets exactly the target tree (owner 2026-09-29, built by hand in z77.ch first);
 *   - an installation that built that tree by hand — module leaves WITHOUT keys, other
 *     ids, gaps in sort_key — gets NOTHING added and nothing changed: recognised by route;
 *   - one missing module entry is added exactly once, under the right parent, at the end;
 *   - an entry the project moved or renamed is never touched, never doubled;
 *   - a parent whose package is missing → the entry is skipped and named; ambiguity too;
 *   - the import's vendor source is the union, parent_key resolved against the TARGET
 *     through identity; the default file keeps its ids (alias + metadata defaults
 *     reference them).
 *
 * Run: php tests/navigation-seeds.php
 */

// ── Composer stubs (the installer runs INSIDE Composer; not a framework dependency) ──
namespace Composer {
    class Composer {}
}
namespace Composer\Script {
    class Event {}
}
namespace Composer\IO {
    interface IOInterface
    {
        public function write($messages, $newline = true, $verbosity = 0);
        public function writeError($messages, $newline = true, $verbosity = 0);
        public function isInteractive();
        public function askConfirmation($question, $default = true);
        public function askAndHideAnswer($question);
    }
}

namespace {

use Composer\IO\IOInterface;
use Z77\Core\Installer\Install;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Entities\NavigationAlias;
use Z77\Shared\Import\ImportOutcome;
use Z77\Shared\Import\ImportPlanner;
use Z77\Shared\Import\ImportServiceFactory;
use Z77\Shared\Import\ImportStaleException;
use Z77\Shared\Import\NavigationSeeds;
use Z77\Shared\Import\Source\NavigationSeedSource;

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "navigation-seeds: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once $root . '/packages/kernel/core/src/Installer/Install.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

final class FakeIo implements IOInterface
{
    public array $lines = [];
    public function write($messages, $newline = true, $verbosity = 0)
    {
        foreach ((array) $messages as $m) { $this->lines[] = (string) $m; }
    }
    public function writeError($messages, $newline = true, $verbosity = 0) { $this->write($messages); }
    public function isInteractive() { return false; }
    public function askConfirmation($question, $default = true) { return $default; }
    public function askAndHideAnswer($question) { return ''; }
    public function out(): string { return implode("\n", $this->lines); }
}

// ── fixtures ────────────────────────────────────────────────────────────────

$packages = ['kernel/core', 'module-contact', 'module-debtor', 'module-dms', 'module-financial', 'module-mandator', 'module-vat'];
$dataRoot = static fn(string $pkg): string => "{$root}/packages/{$pkg}/data";
$allRoots = array_map($dataRoot, $packages);
// z77.ch's package set: everything but module-debtor.
$chRoots  = array_map($dataRoot, array_values(array_diff($packages, ['module-debtor'])));

$defaultFile = $root . '/packages/kernel/core/data/framework/routing/navigation.default.json';
$default     = json_decode((string) file_get_contents($defaultFile), true);

/**
 * Structure of a navigation list, independent of ids and sort_key values: one line per
 * entry, in tree order (slot, then siblings by sort_key), with its path of names, its
 * route and — for a ref entry — the name path of its target.
 */
function outline(array $records): array
{
    $byId = [];
    foreach ($records as $r) { $byId[$r['id']] = $r; }
    $children = [];
    foreach ($records as $r) {
        $children[$r['parent_id'] === null ? 's:' . $r['slot'] : 'p:' . $r['parent_id']][] = $r;
    }
    foreach ($children as &$list) {
        usort($list, static fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
    }
    unset($list);

    $path = static function (int $id) use (&$path, $byId): string {
        $r = $byId[$id];
        return ($r['parent_id'] !== null ? $path($r['parent_id']) . ' > ' : $r['slot'] . ': ') . $r['name'];
    };
    $lines = [];
    $walk = static function (array $list) use (&$walk, &$lines, $children, $path): void {
        foreach ($list as $r) {
            $route = implode('/', array_filter([$r['module'], $r['group'], $r['controller'], $r['action']]));
            $lines[] = $path($r['id']) . ($route !== '' ? " [{$route}]" : '')
                . ($r['ref'] !== null ? ' -> ' . $path($r['ref']) : '');
            $walk($children['p:' . $r['id']] ?? []);
        }
    };
    $slots = array_filter(array_keys($children), static fn($k) => str_starts_with($k, 's:'));
    sort($slots);
    foreach ($slots as $slot) { $walk($children[$slot]); }
    return $lines;
}

function keyOf(array $records, string $key): ?array
{
    foreach ($records as $r) { if (($r['key'] ?? null) === $key) return $r; }
    return null;
}

function routeOf(array $records, string $route): ?array
{
    foreach ($records as $r) {
        if (implode('/', [$r['module'], $r['group'], $r['controller'], $r['action']]) === $route) return $r;
    }
    return null;
}

// The target tree (ADR-050, owner 2026-09-29) — z77.ch's navigation.json as outline.
$expectedBackend = [
    'backend-auth: Login [backend/system/login/login]',
    'backend-auth: Logout [backend/system/login/logout]',
    'backend-main: Webseiten',
    'backend-main: Webseiten > Inhalte [backend/content/content/list]',
    'backend-main: Webseiten > Metadaten [backend/content/meta-data/list]',
    'backend-main: Webseiten > Übersetzungen [backend/content/translation/list]',
    'backend-main: Webseiten > Navigation [backend/content/navigation/list]',
    'backend-main: Webseiten > Navigation > Navigation -> backend-main: Webseiten > Navigation',
    'backend-main: Webseiten > Navigation > Nav Alias [backend/content/navigation_alias/list]',
    'backend-main: Finanzen',
    'backend-main: Finanzen > Journal [backend/finance/journal/list]',
    'backend-main: Finanzen > Auswertungen [backend/finance/report/trial-balance]',
    'backend-main: Kontakte',
    'backend-main: Kontakte > Kontakte [backend/contact/contact/list]',
    'backend-main: Drive',
    'backend-main: Drive > Dokumente [backend/documents/drive/list]',
    'backend-main: Stammdaten',
    'backend-main: Stammdaten > Firma',
    'backend-main: Stammdaten > Firma > Mandant [backend/finance/mandator/edit]',
    'backend-main: Stammdaten > Finanzen',
    'backend-main: Stammdaten > Finanzen > Geschäftsjahre [backend/finance/fiscal-year/list]',
    'backend-main: Stammdaten > Finanzen > Kontenplan [backend/finance/account/list]',
    'backend-main: Stammdaten > Finanzen > MWST-Codes [backend/finance/tax-code/list]',
    'backend-main: Stammdaten > Kontakte',
    'backend-main: Stammdaten > Kontakte > Adresstypen [backend/contact/address-type/list]',
    'backend-main: Stammdaten > System',
    'backend-main: Stammdaten > System > Benutzer [backend/system/backend-user/list]',
    'backend-main: Service',
    'backend-main: Service > Backup [backend/service/backup/list]',
    'backend-main: Service > E-Mail [backend/service/email-settings/list]',
    'backend-main: Service > Jobs [backend/service/job/list]',
    'backend-main: Service > Import [backend/service/import/list]',
    'backend-main: Service > Formular-Protokoll [backend/service/form-log/list]',
];
$expectedFrontend = [
    'frontend-main: Home [frontend/main/index/home]',
    'frontend-main: About [frontend/main/index/about]',
    'frontend-main: Services [frontend/main/index/services]',
    'frontend-main: Contact [frontend/main/index/contact]',
    'frontend-meta: Legal [frontend/main/index/legal]',
    'frontend-meta: Privacy [frontend/main/index/privacy]',
];
$expected = array_merge($expectedBackend, $expectedFrontend);

// ── NSEED-1 the seeds ───────────────────────────────────────────────────────
echo "NSEED-1 seeds of all packages\n";
$files = NavigationSeeds::discover($allRoots);
check('NSEED-1a one seed file per package, sorted by name',
    array_map('basename', $files) === ['kernel.json', 'module-contact.json', 'module-debtor.json', 'module-dms.json',
        'module-financial.json', 'module-mandator.json', 'module-vat.json'], implode(', ', array_map('basename', $files)));
$seeds = NavigationSeeds::read($files);
$keys  = array_values(array_filter(array_merge(array_column($seeds, 'key'), array_column($default, 'key'))));
check('NSEED-1b keys unique across the union and the default file', count($keys) === count(array_unique($keys)));
$converted = NavigationSeeds::toRecords($seeds);
check('NSEED-1c every parent_key / ref_key resolves (no orphan with all packages)', $converted['orphans'] === [],
    json_encode($converted['orphans'], JSON_UNESCAPED_UNICODE));
$areasKeyed = array_filter($seeds, static fn($s) => $s['module'] === '' && ($s['ref_key'] ?? null) === null);
check('NSEED-1d every area / group carries a key',
    count(array_filter($areasKeyed, static fn($s) => ($s['key'] ?? null) === null)) === 0);
check('NSEED-1e the default file keeps only what other seeds reference by id (frontend pages, login/logout)',
    array_column($default, 'id') === [3, 4, 5, 8, 9, 10, 11, 12], implode(',', array_column($default, 'id')));
$override = sys_get_temp_dir() . '/z77-nseed-override-' . getmypid();
@mkdir($override . '/' . NavigationSeeds::SEED_DIR, 0777, true);
file_put_contents($override . '/' . NavigationSeeds::SEED_DIR . '/module-vat.json', '[]');
$withOverride = NavigationSeeds::discover(array_merge([$override], $allRoots));
check('NSEED-1f an override tier replaces a package seed by file name (CE)',
    in_array(str_replace('\\', '/', $override) . '/' . NavigationSeeds::SEED_DIR . '/module-vat.json', $withOverride, true)
    && count($withOverride) === 7);
$bad = [['name' => 'X', 'module' => '', 'slot' => '', 'parent_key' => null]];
file_put_contents($override . '/' . NavigationSeeds::SEED_DIR . '/bad.json', json_encode($bad));
$thrown = '';
try { NavigationSeeds::read([$override . '/' . NavigationSeeds::SEED_DIR . '/bad.json']); } catch (\Throwable $e) { $thrown = $e->getMessage(); }
check('NSEED-1g a root without slot is a packaging defect (throws)', str_contains($thrown, 'root area carries a slot'), $thrown);

// ── NSEED-2 fresh installation ──────────────────────────────────────────────
echo "NSEED-2 fresh installation\n";
$chSeeds = NavigationSeeds::read(NavigationSeeds::discover($chRoots));
$fresh   = NavigationSeeds::merge($chSeeds, $default);
check('NSEED-2a the target tree, exactly', outline($fresh['records']) === $expected,
    implode("\n       ", array_diff(outline($fresh['records']), $expected)) . ' | missing: '
    . implode("\n       ", array_diff($expected, outline($fresh['records']))));
check('NSEED-2b nothing skipped', $fresh['skipped'] === [], implode(' | ', $fresh['skipped']));
check('NSEED-2c the default entries kept their ids (alias + metadata defaults reference them)',
    array_slice(array_column($fresh['records'], 'id'), 0, 8) === [3, 4, 5, 8, 9, 10, 11, 12]);
$ids = array_column($fresh['records'], 'id');
check('NSEED-2d ids unique, new ones after the highest', count($ids) === count(array_unique($ids)) && min(array_column($fresh['added'], 'id')) === 13);
check('NSEED-2e sort_key dense per sibling group (0..n)', (static function (array $rs): bool {
    $groups = [];
    foreach ($rs as $r) { $groups[$r['parent_id'] ?? ('s:' . $r['slot'])][] = $r['sort_key']; }
    foreach ($groups as $g) { sort($g); if ($g !== range(0, count($g) - 1)) return false; }
    return true;
})($fresh['records']));
$again = NavigationSeeds::merge($chSeeds, $fresh['records']);
check('NSEED-2f a second run adds nothing', $again['added'] === [] && $again['records'] === $fresh['records']);

// With debtor: order processing is its own area, apart from the books (owner 2026-09-30) —
// «Aufträge» with Debitoren after Finanzen, its master data under Stammdaten › Aufträge.
$withDebtor = NavigationSeeds::merge($seeds, $default);
$lines      = outline($withDebtor['records']);
$labels     = static fn(string $prefix) => array_values(array_map(static fn($l) => explode(' [', substr($l, strlen($prefix)))[0],
    array_filter($lines, static fn($l) => str_starts_with($l, $prefix) && !str_contains(substr($l, strlen($prefix)), ' > '))));
$roots = array_values(array_map(static fn($l) => explode(' [', substr($l, strlen('backend-main: ')))[0],
    array_filter($lines, static fn($l) => str_starts_with($l, 'backend-main: ') && !str_contains(substr($l, strlen('backend-main: ')), ' > '))));
check('NSEED-2g with module-debtor: the area «Aufträge» (Debitoren) after Finanzen, its master data under Stammdaten › Aufträge — nothing of it under Finanzen',
    $roots === ['Webseiten', 'Finanzen', 'Aufträge', 'Kontakte', 'Drive', 'Stammdaten', 'Service']
    && $labels('backend-main: Aufträge > ') === ['Debitoren']
    && $labels('backend-main: Stammdaten > Aufträge > ') === ['Zahlungskonditionen', 'Zahlungsziele', 'Mahnstufen']
    && $labels('backend-main: Stammdaten > ') === ['Firma', 'Finanzen', 'Aufträge', 'Kontakte', 'System']
    && $labels('backend-main: Stammdaten > Finanzen > ') === ['Geschäftsjahre', 'Kontenplan', 'MWST-Codes'],
    implode(' | ', $lines));

// ── NSEED-3 an installation built by hand (the z77.ch shape) ───────────────
echo "NSEED-3 hand-built installation (keyless module leaves, other ids)\n";
// Take the target, drop the module leaves' keys, shift every id by 100 and spread sort_keys.
$handBuilt = [];
foreach ($fresh['records'] as $r) {
    if (in_array($r['key'], ['journal', 'auswertungen', 'geschaeftsjahre', 'kontenplan', 'mwst-codes', 'kontakte-liste', 'adresstypen', 'mandant'], true)) {
        $r['key'] = null;
    }
    $r['id']       += 100;
    $r['parent_id'] = $r['parent_id'] === null ? null : $r['parent_id'] + 100;
    $r['ref']       = $r['ref'] === null ? null : $r['ref'] + 100;
    $r['sort_key'] *= 2;
    $handBuilt[] = $r;
}
$result = NavigationSeeds::merge($chSeeds, $handBuilt);
check('NSEED-3a nothing added', $result['added'] === [], json_encode(array_column($result['added'], 'name'), JSON_UNESCAPED_UNICODE));
check('NSEED-3b nothing changed (records byte-identical)', $result['records'] === $handBuilt);
check('NSEED-3c nothing skipped', $result['skipped'] === [], implode(' | ', $result['skipped']));

// ── NSEED-4 one module entry missing ───────────────────────────────────────
echo "NSEED-4 one missing module entry\n";
// Geschäftsjahre is the FIRST of its siblings in the seed — added, it goes to the END
// (the project's order stands; ADR-050 §3).
$gone    = routeOf($handBuilt, 'backend/finance/fiscal-year/list');
$missing = array_values(array_filter($handBuilt, static fn($r) => $r['id'] !== $gone['id']));
$result  = NavigationSeeds::merge($chSeeds, $missing);
$added   = $result['added'][0] ?? [];
$group   = keyOf($missing, 'stammdaten-finanzen');
$siblingSorts = array_column(array_filter($missing, static fn($r) => $r['parent_id'] === $group['id']), 'sort_key');
check('NSEED-4a exactly one entry added', count($result['added']) === 1, (string) count($result['added']));
check('NSEED-4b it is Geschäftsjahre, under Stammdaten › Finanzen',
    ($added['name'] ?? '') === 'Geschäftsjahre' && ($added['parent_id'] ?? null) === $group['id']);
check('NSEED-4c at the end of its siblings', ($added['sort_key'] ?? -1) === max($siblingSorts) + 1);
check('NSEED-4d with the next free id and its key', ($added['id'] ?? 0) === max(array_column($missing, 'id')) + 1
    && ($added['key'] ?? null) === 'geschaeftsjahre');
check('NSEED-4e every existing record unchanged', array_slice($result['records'], 0, count($missing)) === $missing);

// ── NSEED-5 moved + renamed by the project ─────────────────────────────────
echo "NSEED-5 an entry the project moved and renamed\n";
$moved = $handBuilt;
foreach ($moved as &$r) {
    if ($r['name'] === 'Journal') {
        $r['name']      = 'Buchungen';
        $r['parent_id'] = keyOf($moved, 'stammdaten')['id'];
        $r['sort_key']  = 99;
    }
}
unset($r);
$result = NavigationSeeds::merge($chSeeds, $moved);
check('NSEED-5a nothing added (recognised by route)', $result['added'] === [], json_encode(array_column($result['added'], 'name'), JSON_UNESCAPED_UNICODE));
check('NSEED-5b the project\'s version stays as it is', $result['records'] === $moved);

// ── NSEED-6 parent missing / ambiguity ─────────────────────────────────────
echo "NSEED-6 parent missing, ambiguity\n";
$noFinancial = NavigationSeeds::read(NavigationSeeds::discover(array_map($dataRoot, ['kernel/core', 'module-vat', 'module-debtor'])));
$result = NavigationSeeds::merge($noFinancial, $default);
check('NSEED-6a module-vat without module-financial: MWST-Codes skipped',
    routeOf($result['records'], 'backend/finance/tax-code/list') === null);
check('NSEED-6b … and named, with the missing parent',
    (bool) array_filter($result['skipped'], static fn($l) => str_contains($l, '«MWST-Codes» (module-vat.json)') && str_contains($l, 'stammdaten-finanzen')),
    implode(' | ', $result['skipped']));
check('NSEED-6c the rest (kernel) still added', keyOf($result['records'], 'stammdaten-system') !== null && keyOf($result['records'], 'benutzer') !== null);

// Two keyless entries carry the Journal route (legal, ADR-015): which one is «the» Journal?
$journal = routeOf($handBuilt, 'backend/finance/journal/list');
$copy    = $journal;
$copy['id'] = 999;
$copy['name'] = 'Journal (Kopie)';
$result = NavigationSeeds::merge($chSeeds, array_merge($handBuilt, [$copy]));
check('NSEED-6d two entries with the Journal route: nothing added (ambiguous — no guess)',
    routeOf($result['added'], 'backend/finance/journal/list') === null);
check('NSEED-6e … and it says so', (bool) array_filter($result['skipped'], static fn($l) => str_contains($l, '«Journal»') && str_contains($l, 'ambiguous')),
    implode(' | ', $result['skipped']));

// ── NSEED-7 the installer step on disk ─────────────────────────────────────
echo "NSEED-7 installer step (file I/O)\n";
$tmp = sys_get_temp_dir() . '/z77-nseed-' . getmypid();
@mkdir($tmp . '/data/framework/routing', 0777, true);
$navFile = $tmp . '/data/framework/routing/navigation.json';
copy($defaultFile, $navFile);   // what writeDataFiles() does on a fresh install

$install = (new ReflectionClass(Install::class))->newInstanceWithoutConstructor();
$io = new FakeIo();
(new ReflectionProperty(Install::class, 'io'))->setValue($install, $io);
(new ReflectionProperty(Install::class, 'baseDir'))->setValue($install, $tmp);
$run = static fn(array $roots) => (new ReflectionMethod(Install::class, 'seedNavigationFrom'))->invoke($install, $roots);

$run($chRoots);
$written = json_decode((string) file_get_contents($navFile), true);
check('NSEED-7a fresh: navigation.json holds the target tree', outline($written) === $expected);
check('NSEED-7b the output names the added entries', str_contains($io->out(), 'Navigation seeds → 31 entries added')
    && str_contains($io->out(), '+ «Journal» (journal)'), $io->out());
check('NSEED-7c written like FileStorage (pretty, unescaped unicode, no BOM)',
    str_contains((string) file_get_contents($navFile), '"Übersetzungen"') && !str_starts_with((string) file_get_contents($navFile), "\xEF\xBB\xBF"));
$before = file_get_contents($navFile);
$io->lines = [];
$run($chRoots);
check('NSEED-7d second run: nothing to add, file untouched',
    file_get_contents($navFile) === $before && str_contains($io->out(), 'nothing to add'), $io->out());
$io->lines = [];
$run($allRoots);
check('NSEED-7e module-debtor installed later: its 6 entries added (area, group, four screens)', str_contains($io->out(), '6 entries added'), $io->out());
unlink($navFile);
$io->lines = [];
$run($chRoots);
check('NSEED-7f no navigation.json at all: created (the backend menu, no frontend pages)',
    is_file($navFile) && count(json_decode((string) file_get_contents($navFile), true)) === 31);
file_put_contents($navFile, '{ broken');
$thrown = '';
try { $run($chRoots); } catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
check('NSEED-7g a corrupt navigation.json throws and stays untouched',
    str_contains($thrown, 'Corrupt navigation file') && file_get_contents($navFile) === '{ broken', $thrown);

// ── NSEED-8 the import: vendor source = the union ──────────────────────────
echo "NSEED-8 import vendor source\n";
$aliasFile = $root . '/packages/kernel/core/data/framework/routing/navigation_aliases.default.json';
$vendorFiles = [Navigation::class => $defaultFile, NavigationAlias::class => $aliasFile];
$spec   = ImportServiceFactory::sourceSpec('vendor', 'Vendor-Defaults', $vendorFiles, NavigationSeeds::discover($chRoots));
$source = ImportServiceFactory::sourceFromSpec($spec);
check('NSEED-8a the spec carries the seeds, the source folds them in',
    count($spec['navigation_seeds']) === 6 && $source instanceof NavigationSeedSource);
$navSet = $source->recordSets()[Navigation::class];
check('NSEED-8b Navigation set = default (ids kept) + every seed entry',
    count($navSet) === 8 + 31 && array_slice(array_column($navSet, 'id'), 0, 8) === [3, 4, 5, 8, 9, 10, 11, 12]);

// Target: the hand-built installation without Geschäftsjahre, aliases of the fresh ids +100.
$aliasTarget = array_map(static function (array $a) { $a['navigation_id'] += 100; return $a; },
    json_decode((string) file_get_contents($aliasFile), true));
$plan = (new ImportPlanner())->plan($source->recordSets(), [Navigation::class => $missing, NavigationAlias::class => $aliasTarget]);
$navEntries = array_values(array_filter($plan->entries, static fn($e) => $e->entityClass === Navigation::class));
$new = array_values(array_filter($navEntries, static fn($e) => $e->outcome === ImportOutcome::NewRecord));
check('NSEED-8c exactly one new: Geschäftsjahre', count($new) === 1 && $new[0]->record['name'] === 'Geschäftsjahre',
    implode(', ', array_map(static fn($e) => $e->record['name'], $new)));
$parentSrc = $new[0]->record['parent_id'] ?? null;
$parentEntry = array_values(array_filter($navEntries, static fn($e) => $e->record['id'] === $parentSrc))[0] ?? null;
check('NSEED-8d its parent (parent_key stammdaten-finanzen) resolved to the TARGET group',
    $parentEntry !== null && $parentEntry->targetId === $group['id'] && $parentEntry->outcome === ImportOutcome::Skipped);
$byOutcome = [];
foreach ($navEntries as $e) { $byOutcome[$e->outcome->value][] = $e->record['name']; }
check('NSEED-8e nothing unclear or blocked', !isset($byOutcome['unclear']) && !isset($byOutcome['blocked']),
    json_encode($byOutcome, JSON_UNESCAPED_UNICODE));
$changed = array_filter($navEntries, static fn($e) => $e->outcome === ImportOutcome::Changed);
check('NSEED-8f keyless module leaves: «changed» with the key as the only difference (key backfill, opt-in)',
    count($changed) === 7 && array_filter($changed, static fn($e) => array_keys($e->diff) !== ['key']) === []);
$aliasEntries = array_filter($plan->entries, static fn($e) => $e->entityClass === NavigationAlias::class);
check('NSEED-8g the alias defaults still resolve through the default file ids (all skipped)',
    count($aliasEntries) === 7 && array_filter($aliasEntries, static fn($e) => $e->outcome !== ImportOutcome::Skipped) === []);

// A changed seed file after the plan: stale.
$seedCopyDir = $tmp . '/seedcopy/' . NavigationSeeds::SEED_DIR;
@mkdir($seedCopyDir, 0777, true);
copy($root . '/packages/module-vat/data/framework/routing/navigation.d/module-vat.json', $seedCopyDir . '/module-vat.json');
$spec2 = ImportServiceFactory::sourceSpec('vendor', 'V', $vendorFiles, [$seedCopyDir . '/module-vat.json']);
file_put_contents($seedCopyDir . '/module-vat.json', '[]');
$stale = false;
try { ImportServiceFactory::sourceFromSpec($spec2); } catch (ImportStaleException) { $stale = true; }
check('NSEED-8h a seed file changed since the plan → stale, never a shifted plan', $stale);

// ── cleanup ────────────────────────────────────────────────────────────────
$rm = static function (string $dir) use (&$rm): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $i) {
        if ($i === '.' || $i === '..') continue;
        is_dir("$dir/$i") ? $rm("$dir/$i") : @unlink("$dir/$i");
    }
    @rmdir($dir);
};
$rm($tmp);
$rm($override);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
}
