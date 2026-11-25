<?php

/**
 * The protocol «Abschlüsse und MWST» and the VAT hint of the year close (CLI) — VAT return stage S2,
 * owner decisions of 2026-11-25 (board S2-9; financial.md «the protocol «Abschlüsse und MWST»»):
 *
 *   O. (offline) `CloseProtocolListing` — the standard list's columns, the voucher slot only when the
 *      list can have it, the «Was» parser (a label that names no kind is INVALID), the search object's
 *      guards; source guards (read only, no `vat_return` table named, no input in the ORDER BY);
 *   M. (database) a throwaway schema through the modules' migrations; the books: KMU chart, fiscal
 *      years 2026 and 2027, a liable mandator (quarter from 1.1.2026); protocol rows INSERTED BY THE
 *      HARNESS — three of the year close (close with two warnings, reopen with a reason, close), six
 *      of the VAT returns (a post, its lift, the post of revision 2, a nil return, a post with two
 *      warnings, a post of 2027) and one legacy `filed` row that must not show;
 *   P. `FiscalYearCloseLogRepository::protocolPage()` / `protocolCount()` — the UNION of the two
 *      tables: the year scope (a year's own rows and the VAT periods ENDING in it), «Alle Jahre», each
 *      column search, newest first and ascending, paging, the values of a row;
 *   S. the screen through its trait with a host double and the real templates: the tab «Abschlüsse und
 *      MWST» (main, the tabs in the toolbar, the year selection at the top of the rail, the crumb, the
 *      action cell empty), the rows (badge, «Betrifft» with its link, «Grund / Angaben»), a fetch of the
 *      region, «Alle Jahre», an invalid search, the first tab with its tabs, the voucher column when a
 *      voucher action exists, the help;
 *   C. the year-close modal shows `UnsettledVatPeriodsCheck::hint()` as information without a tick —
 *      liable without «Abrechnung ab», the liability switched off without «Abrechnung bis», beside a
 *      blocking finding — and the close itself is unchanged (it closes with the hint shown).
 *
 * Every name and amount here is synthetic.
 *
 * Run: php tests/run.php financial-close-protocol
 * O needs the monorepo's vendor/; M–C a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-close-protocol: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\DI;
use Z77\Module\Financial\Close\UnsettledVatPeriodsCheck;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Module\Financial\Repositories\CloseProtocolSearch;
use Z77\Module\Financial\Services\FiscalYearCloseService;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Financial\Ui\ChangeLogControllerTrait;
use Z77\Module\Financial\Ui\CloseProtocolListing;
use Z77\Module\Financial\Ui\FiscalYearControllerTrait;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/** The exception thrown by $fn, or null. */
function thrown(callable $fn): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e; }
    return null;
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

/** A close check the harness controls (section C): what a module registers under `period-close`. */
final class HarnessCloseFindings implements \Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface
{
    /** @var list<Finding> */
    public static array $findings = [];

    public function check(string $scope, array $parameters): iterable
    {
        yield from self::$findings;
    }
}

$package = $root . '/packages/module-financial';

// ─────────────────────────────────────────────────────────────────────────
echo "O. The standard list, the «Was» parser, the search object, source guards (offline)\n";

$def   = CloseProtocolListing::definition();
$plain = CloseProtocolListing::definition(false);
check('O1 a standard list: id close-protocol-find, region close-protocol-find-list; the voucher slot · Zeit · Wer · Was · Betrifft · Grund / Angaben; four searched fields; Zeit the one sort, newest first; 50 per page; the extra «all» — without a voucher action the slot is not there at all',
    $def->id === 'close-protocol-find' && $def->region() === 'close-protocol-find-list'
    && array_map(fn($c) => $c->label, $def->columns()) === ['', 'Zeit', 'Wer', 'Was', 'Betrifft', 'Grund / Angaben'] && $def->columns()[0]->isSlot()
    && array_map(fn($c) => $c->label, $plain->columns()) === ['Zeit', 'Wer', 'Was', 'Betrifft', 'Grund / Angaben']
    && $def->searchKeys() === ['f_at', 'f_who', 'f_what', 'f_subject'] && $def->sorts() === ['at'] && $def->defaultSort === 'at' && $def->descendingFirst('at')
    && $def->pageSize === 50 && $def->extras() === ['all' => ['', '1']]);
$parse = CloseProtocolListing::kindParser();
$bad   = $def->read(['f_what' => 'Eingereicht', 'f_subject' => '31.02.2026']);
check('O2 «Was» finds the kinds whose label contains the text, case-insensitive: «MWST» both VAT kinds, «JAHR» both year kinds, «geöffnet» the reopen; «Eingereicht» (no kind since posted means filed) is INVALID and does not narrow; «Betrifft» an impossible day is invalid too',
    $parse('MWST') === ['vat-posted', 'vat-lifted'] && $parse('JAHR') === ['year-closed', 'year-reopened'] && $parse('geöffnet') === ['year-reopened'] && $parse('aufgehoben') === ['vat-lifted']
    && $parse('Eingereicht') === null && $bad->isInvalid('f_what') && $bad->isInvalid('f_subject')
    && CloseProtocolListing::search($bad, null)->kinds === null && CloseProtocolListing::search($bad, null)->subjectFrom === null);
$s = CloseProtocolListing::search($def->read(['all' => '1', 'f_at' => '04.2026', 'f_who' => 'pet', 'f_what' => 'mwst', 'f_subject' => '2027', 'dir' => 'asc']), null);
check('O3 the search object: «Alle Jahre» → no scope; the day range of the event, who, the kinds, the subject range, the direction — parsed, never raw',
    $s->fiscalYearId === null && $s->atFrom === '2026-04-01' && $s->atTo === '2026-04-30' && $s->who === 'pet' && $s->kinds === ['vat-posted', 'vat-lifted']
    && $s->subjectFrom === '2027-01-01' && $s->subjectTo === '2027-12-31' && $s->descending === false);
check('O4 CloseProtocolSearch refuses an unknown kind, an empty kind list (a parser never yields one), a year scope without its days',
    thrown(fn() => new CloseProtocolSearch(kinds: ['filed'])) instanceof \InvalidArgumentException
    && thrown(fn() => new CloseProtocolSearch(kinds: [])) instanceof \InvalidArgumentException
    && thrown(fn() => new CloseProtocolSearch(fiscalYearId: 3)) instanceof \InvalidArgumentException);
/** The source without its comments — a docblock may name a table, code may not. */
$codeOf  = static fn(string $src): string => implode('', array_map(static fn($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all($src)));
$repoSrc = $codeOf((string) file_get_contents($package . '/src/Repositories/FiscalYearCloseLogRepository.php'));
$trait   = (string) file_get_contents($package . '/src/Ui/ChangeLogControllerTrait.php');
$tpl     = (string) file_get_contents($package . '/res/view/templates/Backend/ChangeLogController/closesAndVat.tpl.php');
check('O5 source guards: the protocol is READ ONLY (the trait writes nothing); the union names `vat_return_log` but never the table `vat_return` (that stays the VAT return repository\'s — the posting harness O7); no search value interpolated into the SQL; no script in the templates; no docblock cites docs/temp/',
    !preg_match('/->(persist|remove|flush|update|delete|create)\(/', $trait) && str_contains($repoSrc, 'FROM vat_return_log v') && preg_match('/\bvat_return\b/', $repoSrc) === 0
    && preg_match('/\{\$search->/', $repoSrc) === 0 && stripos($tpl, '<script') === false
    && !str_contains($trait . $tpl . file_get_contents($package . '/src/Ui/CloseProtocolListing.php') . file_get_contents($package . '/src/Close/VatSettingsChangeCheck.php'), 'docs/temp'));

// ── M. database ──────────────────────────────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $credLine) {
        if (str_starts_with($credLine, $credentials['user'] . '=')) {
            $credentials['password'] = substr($credLine, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '' || !class_exists(\Doctrine\DBAL\DriverManager::class)) {
    echo "M. Database — SKIPPED (no z77test password: %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD)\n";
    echo "\n{$pass} passed, {$fail} failed (M skipped)\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database — the books and the protocol rows (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-close-protocol-' . getmypid());
define('DEBUG', false);
$base = ABS_BASE_PATH;

$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

$packages = [
    'Vat'       => $root . '/packages/module-vat',
    'Mandator'  => $root . '/packages/module-mandator',
    'Financial' => $package,
];
$namespaces = "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$root}/packages/kernel/shared']],\n";
foreach ($packages as $name => $path) {
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => ['{$path}']],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'financial', 'modules' => ['vat' => [], 'mandator' => [], 'financial' => []]];");
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
$write('data/framework/vat/tax_codes.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_codes.default.json'));
$write('data/framework/vat/tax_rates.json', (string) file_get_contents($packages['Vat'] . '/data/framework/vat/tax_rates.default.json'));

/**
 * A fresh request: new DI, new UnifiedEntityManager, empty identity map — and the request doubles of the screens.
 * The previous request's connection is CLOSED (the posting harness's way): the finance group runs DB harnesses side
 * by side, and connections left open until exit add up to the server's limit (1040 «Too many connections»).
 */
$wire = static function () use ($base): \Z77\Persistence\Resolver\UnifiedEntityManager {
    gc_collect_cycles();
    static $previous = null;
    if ($previous !== null) {
        foreach ((new \ReflectionProperty($previous, 'managerCache'))->getValue($previous) as $manager) {
            if ($manager instanceof \Z77\Persistence\Doctrine\DoctrineEntityManager) {
                (new \ReflectionProperty($manager, 'holder'))->getValue($manager)->current()->getConnection()->close();
            }
        }
    }
    DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)
        ->set('SessionManager', \Z77\Core\Session\SessionManager::class, true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true)
        ->set('Request', fn() => new class {
            public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
            public function isPost(): bool { return false; }
            public function getJsonBody(): array { return $GLOBALS['harnessBody'] ?? []; }
            public function getMode(): \Z77\Core\Http\RequestMode { return !empty($GLOBALS['harnessFetch']) ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
        }, true)
        ->set('CsrfService', fn() => new class {
            public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
            public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
        }, true);
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = DI::getUnifiedEntityManager();
};
$em = $wire();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
    DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
);
$app->setAutoExit(false);
$migrateOut = new \Symfony\Component\Console\Output\BufferedOutput();
$migrateCode = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $migrateOut);
check('M1 migrate exits 0 (vat, mandator, financial)', $migrateCode === 0, trim($migrateOut->fetch()));

$em = $wire();
(new \Z77\Module\Financial\Services\AccountService($em))->adoptKmuChart();
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
$em = $wire();
(new FiscalYearService($em))->open(new FiscalYear('2027', day('2027-01-01'), day('2027-12-31')));
$em = $wire();
$mandator = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$mandator->mapFromArray([
    'name' => 'Protokoll AG', 'country' => 'CH', 'zip' => '3000', 'city' => 'Bern', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710',
    'vat_method' => 'effective', 'vat_consideration' => 'agreed', 'vat_settlement_rhythm' => 'quarter', 'vat_settlement_from' => '2026-01-01',
]);
(new \Z77\Module\Mandator\Services\MandatorService($em))->save($mandator);
$yearId = static fn(string $code): int => (int) $db->fetchOne('SELECT id FROM fiscal_year WHERE code = ?', [$code]);

// The close protocol of 2026 — rows as FiscalYearCloseService writes them (the close itself is section C's).
$closeRow = static function (string $code, string $action, string $by, string $at, ?string $reason = null, ?string $warnings = null) use ($db, $yearId): void {
    $db->insert('fiscal_year_close_log', ['fiscal_year_id' => $yearId($code), 'fiscal_year_code' => $code, 'action' => $action, 'actor' => $by, 'acted_at' => $at, 'reason' => $reason, 'confirmed_warnings' => $warnings]);
};
$closeRow('2026', 'close', 'peter', '2027-02-10 09:00:00', null, "Ertragskonto 3400: zwei Buchungen ohne MWST-Code\nAuftrag 4711 nicht fakturiert");
$closeRow('2026', 'reopen', 'admin', '2027-02-12 10:00:00', 'Abschreibung Fahrzeug fehlte');
$closeRow('2026', 'close', 'peter', '2027-02-14 11:00:00');

/** A raw row of `vat_return` — every NOT NULL column filled, each overridable; nil returns (no settlement entry). */
$rawReturn = static function (array $o) use ($db): int {
    $db->insert('vat_return', $o + [
        'revision' => 1, 'state' => 'posted', 'live' => 1,
        'rhythm' => 'quarter', 'method' => 'effective', 'consideration' => 'agreed', 'country' => 'CH', 'form_edition' => 'harness',
        'mapping_hash' => str_repeat('a', 64), 'mapping_snapshot' => '[]', 'currency' => 'CHF', 'payable' => '0.00', 'posted_tax' => '0.00',
        'rounding_difference' => '0.00', 'posted_by' => 'peter', 'posted_at' => '2026-04-10 09:00:00', 'figures_fingerprint' => str_repeat('b', 64),
        'voucher_path' => 'data/archive/financial/vat-return/2026/harness-' . bin2hex(random_bytes(4)) . '.pdf', 'voucher_sha256' => str_repeat('c', 64), 'voucher_name' => 'harness.pdf',
    ]);

    return (int) $db->lastInsertId();
};
$logRow = static function (int $returnId, string $from, string $to, int $revision, string $action, string $by, string $at, ?string $reason = null, array $detail = []) use ($db): void {
    $db->insert('vat_return_log', ['vat_return_id' => $returnId, 'period_from' => $from, 'period_to' => $to, 'revision' => $revision, 'action' => $action,
        'actor' => $by, 'acted_at' => $at, 'reason' => $reason, 'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
};
$q1r1 = $rawReturn(['period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'state' => 'lifted', 'live' => null, 'payable' => '100.00', 'lifted_by' => 'admin', 'lifted_at' => '2026-04-12 08:30:00', 'lift_reason' => 'Rechnung 2603 fehlte']);
$q1r2 = $rawReturn(['period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'revision' => 2, 'payable' => '1482.35', 'posted_at' => '2026-04-13 14:00:00']);
$q2   = $rawReturn(['period_from' => '2026-04-01', 'period_to' => '2026-06-30', 'posted_at' => '2026-07-10 10:00:00']);
$q4   = $rawReturn(['period_from' => '2026-10-01', 'period_to' => '2026-12-31', 'payable' => '-210.40', 'posted_at' => '2027-01-15 16:20:00']);
$q127 = $rawReturn(['period_from' => '2027-01-01', 'period_to' => '2027-03-31', 'payable' => '667.83', 'posted_at' => '2027-04-10 08:00:00']);
$logRow($q1r1, '2026-01-01', '2026-03-31', 1, 'post', 'peter', '2026-04-10 09:00:00', null, ['entry' => '2026/5', 'warnings' => ['Ertrag ohne MWST-Code 30.00 auf 6950'], 'voucher' => 'data/archive/financial/vat-return/2026/x.pdf']);
$logRow($q1r1, '2026-01-01', '2026-03-31', 1, 'lift', 'admin', '2026-04-12 08:30:00', 'Rechnung 2603 fehlte', ['entry' => '2026/5', 'reversal' => '2026/9']);
$logRow($q1r2, '2026-01-01', '2026-03-31', 2, 'post', 'peter', '2026-04-13 14:00:00', null, ['entry' => '2026/10']);
$logRow($q1r2, '2026-01-01', '2026-03-31', 2, 'filed', 'peter', '2026-04-14 09:00:00', null, ['filed_on' => '2026-04-14']);   // a development leftover of the dropped «Eingereicht am»
$logRow($q2, '2026-04-01', '2026-06-30', 1, 'post', 'peter', '2026-07-10 10:00:00');
$logRow($q4, '2026-10-01', '2026-12-31', 1, 'post', 'peter', '2027-01-15 16:20:00', null, ['entry' => '2026/40', 'warnings' => ['Rundungsdifferenz 0.02', 'Ertrag ohne MWST-Code 12.00 auf 3400']]);
$logRow($q127, '2027-01-01', '2027-03-31', 1, 'post', 'peter', '2027-04-10 08:00:00', null, ['entry' => '2027/3']);
check('M2 the books: KMU chart, fiscal years 2026 and 2027, a liable mandator; 3 close-log rows of 2026, 5 VAT returns, 7 VAT-log rows (one of them the legacy action `filed`)',
    (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year') === 2 && (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year_close_log') === 3
    && (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return') === 5 && (int) $db->fetchOne('SELECT COUNT(*) FROM vat_return_log') === 7);

// ─────────────────────────────────────────────────────────────────────────
echo "P. The repository: ONE list over two tables (UNION ALL), scope, search, order, paging\n";

$em     = $wire();
/** @var \Z77\Module\Financial\Repositories\FiscalYearCloseLogRepository $repo */
$repo   = $em->getRepository(FiscalYearCloseLog::class);
$scope  = static fn(string $code): array => ['fiscalYearId' => $yearId($code), 'yearFrom' => $code . '-01-01', 'yearTo' => $code . '-12-31'];
$kinds  = static fn(array $rows): array => array_map(static fn($e): string => $e->kind . ' ' . $e->actedAt->format('m-d'), $rows);
$of     = static fn(array $args, int $offset = 0, int $limit = 50): array => $repo->protocolPage(new CloseProtocolSearch(...$args), $offset, $limit);
$count  = static fn(array $args): int => $repo->protocolCount(new CloseProtocolSearch(...$args));
$y2026  = $of($scope('2026'));
check('P1 the year 2026: its own close / reopen rows AND the VAT rows of the periods ENDING in it (Q1 post, lift, post r2; Q2; Q4 — posted in 2027) — newest first across both tables; Q1 2027 is 2027\'s; the legacy `filed` row is not listed; counted the same',
    $kinds($y2026) === ['year-closed 02-14', 'year-reopened 02-12', 'year-closed 02-10', 'vat-posted 01-15', 'vat-posted 07-10', 'vat-posted 04-13', 'vat-lifted 04-12', 'vat-posted 04-10']
    && $count($scope('2026')) === 8 && $kinds($of($scope('2027'))) === ['vat-posted 04-10'],
    json_encode($kinds($y2026)));
check('P2 «Alle Jahre» (no scope): all nine, still without the `filed` row; ascending turns the order',
    $count([]) === 9 && count($of([])) === 9 && $kinds($of(['descending' => false]))[0] === 'vat-posted 04-10' && $of(['descending' => false])[0]->actedAt->format('Y') === '2026');
check('P3 each column search narrows with a bound value: who («ADMIN», case-insensitive — the reopen and the lift), the kinds (the lift alone), the day of the event (04.2026 — three, the `filed` row of 14.04. not among them), the subject OVERLAPS the range (05.2026 → Q2 and the fiscal year 2026\'s rows; 2027 → Q1 2027 only; 2026 → every 2026 row); a LIKE wildcard typed is a literal',
    $kinds($of(['who' => 'ADMIN'])) === ['year-reopened 02-12', 'vat-lifted 04-12']
    && $kinds($of(['kinds' => ['vat-lifted']])) === ['vat-lifted 04-12']
    && $kinds($of(['atFrom' => '2026-04-01', 'atTo' => '2026-04-30'])) === ['vat-posted 04-13', 'vat-lifted 04-12', 'vat-posted 04-10']
    && $kinds($of(['subjectFrom' => '2026-05-01', 'subjectTo' => '2026-05-31'])) === ['year-closed 02-14', 'year-reopened 02-12', 'year-closed 02-10', 'vat-posted 07-10']
    && $kinds($of(['subjectFrom' => '2027-01-01', 'subjectTo' => '2027-12-31'])) === ['vat-posted 04-10']
    && $count(['subjectFrom' => '2026-01-01', 'subjectTo' => '2026-12-31']) === 8
    && $of(['who' => '%']) === [] && $of(['who' => '_']) === [] && $count(['who' => 'pet', 'kinds' => ['year-closed', 'vat-lifted']]) === 2);
check('P4 paging: three per page, page 2 continues where page 1 stopped, the last page holds the rest',
    $kinds($of($scope('2026'), 3, 3)) === ['vat-posted 01-15', 'vat-posted 07-10', 'vat-posted 04-13'] && count($of($scope('2026'), 6, 3)) === 2);
$lift  = $of(['kinds' => ['vat-lifted']])[0];
$close = $of(['kinds' => ['year-closed'], 'atFrom' => '2027-02-10', 'atTo' => '2027-02-10'])[0];
$post  = $of(['kinds' => ['vat-posted'], 'atFrom' => '2026-04-10', 'atTo' => '2026-04-10'])[0];
check('P5 a row\'s values: the lift — its return, period, revision, reason, detail (entry, reversal); the close — year id and code, the confirmed warnings one per line, no VAT fields; the post — its confirmed warnings from the detail',
    $lift->isVat() && $lift->vatReturnId === $q1r1 && $lift->periodFrom?->format('Y-m-d') === '2026-01-01' && $lift->periodTo?->format('Y-m-d') === '2026-03-31' && $lift->revision === 1
    && $lift->reason === 'Rechnung 2603 fehlte' && $lift->detail === ['entry' => '2026/5', 'reversal' => '2026/9'] && $lift->actor === 'admin'
    && !$close->isVat() && $close->yearId === $yearId('2026') && $close->yearCode === '2026' && $close->periodFrom === null && $close->revision === null && $close->reason === null
    && $close->warnings === ['Ertragskonto 3400: zwei Buchungen ohne MWST-Code', 'Auftrag 4711 nicht fakturiert'] && $close->detail === []
    && $post->warnings === ['Ertrag ohne MWST-Code 30.00 auf 6950'] && $post->detail['entry'] === '2026/5');

// ─────────────────────────────────────────────────────────────────────────
echo "S. The screen: the tab «Abschlüsse und MWST» through the trait and the real templates\n";

/**
 * Renders a module-financial template with a partial() that resolves the module's own tree and the
 * kernel's shared partials — `TemplateRenderer::partial()` does the same through the FileFinder.
 */
$renderer = new class($package . '/res/view/templates/', $root . '/packages/kernel/shared/res/view/templates/') {
    public function __construct(private string $dir, private string $sharedDir) {}
    public function partial(string $path, array $context = [], ?string $ns = null): string
    {
        $dir = $ns === 'Z77\\Shared' ? $this->sharedDir : $this->dir;
        return (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })->call($this, $dir . $path . '.tpl.php', $context);
    }
};
$GLOBALS['harnessVoucherBase'] = null;
/** A host double of the change log trait: context, sections, help; the voucher action as the harness says. */
$clHost = static function () {
    return new class {
        use ChangeLogControllerTrait { listAction as public; closesAndVatAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public function __construct()
        {
            $this->help = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public function pushFlashAfterRedirect(string $type, string $message): void {}
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function changeLogVoucherBase(): ?string { return $GLOBALS['harnessVoucherBase']; }
    };
};
/** One page: [host, the rendered sections by name]. */
$clPage = static function (array $get, string $action = 'closesAndVat') use ($wire, $clHost, $renderer): array {
    $wire();
    $_GET     = $get;
    $_SESSION = [];
    $host     = $clHost();
    $host->{$action . 'Action'}();
    $out = [];
    foreach ($host->layoutManager->sections as $section => $partials) {
        $out[$section] = implode('', array_map(static fn(string $p): string => $renderer->partial($p, $host->context), $partials));
    }
    // `main` of the first tab is pinned by the layout (ChangeLogLayout), not added by the action.
    $out['main'] ??= $action === 'list' ? $renderer->partial('Backend/ChangeLogController/listAction', $host->context) : '';

    return [$host, $out];
};
[$h, $out] = $clPage(['year' => '2026']);
$main = $out['main'];
check('S1 the tab of 2026: its own list in main (swapping the layout\'s pin to the first tab), the tabs in the TOOLBAR (hc2), the year selection at the top of the rail, the crumb (hc3), the action cell (hc1) EMPTY; the region, the kernel head and find form; eight rows; the help in the toolbar',
    $h->layoutManager->sections['main'] === ['Backend/ChangeLogController/closesAndVat'] && $h->layoutManager->sections['hc2'] === ['Backend/ChangeLogController/tabs']
    && $h->layoutManager->sections['railSelect'] === ['Backend/partials/fiscalYearSelect'] && $h->layoutManager->sections['hc3'] === ['Backend/ChangeLogController/crumb']
    && empty($h->layoutManager->sections['hc1'])
    && str_contains($main, 'data-fetch-region="close-protocol-find-list"') && str_contains($main, '<form id="close-protocol-find" method="get" action="/backend/finance/change-log/closes-and-vat"')
    && str_contains($main, 'id="close-protocol-find-f_what"') && str_contains($main, 'Geschäftsjahr 2026') && count($h->context['rows']) === 8 && $h->context['paging']->total === 8
    && substr_count($main, 'data-protocol-kind=') === 8 && $h->help->has() && $h->help->inToolbar() && $h->help->title() === 'Hilfe: Abschlüsse und MWST');
check('S2 the kinds as badges in the board\'s colours: «MWST gebucht» and «Jahr abgeschlossen» green, «MWST aufgehoben» grey, «Jahr geöffnet» amber; no «eingereicht» anywhere',
    str_contains($main, '<span class="badge badge--success">MWST gebucht</span>') && str_contains($main, '<span class="badge badge--muted">MWST aufgehoben</span>')
    && str_contains($main, '<span class="badge badge--success">Jahr abgeschlossen</span>') && str_contains($main, '<span class="badge badge--warning">Jahr geöffnet</span>')
    && stripos($main, 'eingereicht') === false);
check('S3 «Betrifft»: the VAT period by its label with the revision, linked to the return page of the period («1. Quartal 2026 · Fassung 2» → form?from=2026-01-01); the fiscal year linked to the years',
    str_contains($main, '<a href="/backend/finance/vat-return/form?from=2026-01-01">1. Quartal 2026 · Fassung 2</a>') && str_contains($main, '>1. Quartal 2026 · Fassung 1</a>')
    && str_contains($main, '<a href="/backend/finance/vat-return/form?from=2026-10-01">4. Quartal 2026 · Fassung 1</a>')
    && str_contains($main, '<a href="/backend/finance/fiscal-year/list">Geschäftsjahr 2026</a>') && str_contains($main, 'for="close-protocol-find-f_subject"'));
check('S4 «Grund / Angaben»: a post — the entry, the DECLARED payable of that revision, the confirmed warning (one named, several counted, each in the title); a nil return «Buchung: keine»; a lift — the reason in quotes, the reversal muted; a close — «keine Warnung» or «bestätigt: 2 Warnungen»; a reopen — its reason',
    str_contains($main, 'Buchung 2026/10 · Zahllast 1&apos;482.35') && str_contains($main, 'Buchung 2026/5 · Zahllast 100.00 · bestätigt: Ertrag ohne MWST-Code 30.00 auf 6950')
    && str_contains($main, 'Rundungsdifferenz 0.02') && str_contains($main, 'Ertrag ohne MWST-Code 12.00 auf 3400"')
    && str_contains($main, 'Buchung 2026/40 · Zahllast ' . e(\Z77\Shared\Money\AmountFormat::of(\Z77\Shared\Money\Money::fromDecimal('-210.40', 'CHF'))) . ' · bestätigt: 2 Warnungen')
    && str_contains($main, 'Buchung: keine · Zahllast 0.00') && str_contains($main, '«Rechnung 2603 fehlte» <small class="be-list__cell--muted">· Storno 2026/9</small>')
    && str_contains($main, '<small class="be-list__cell--muted">keine Warnung</small>') && str_contains($main, '<small class="be-list__cell--muted">bestätigt: 2 Warnungen</small>')
    && str_contains($main, '«Abschreibung Fahrzeug fehlte»') && str_contains($main, '>14.02.2027 11:00<'),
    preg_match_all('/data-field="detail"[^>]*>(.*?)<\/span>\s*<\/div>/s', $main, $m) ? json_encode($m[1], JSON_UNESCAPED_UNICODE) : '');
check('S5 no voucher action yet → the list has NO voucher column (no slot, no empty cell, no «Beleg»): the grid starts with «Zeit»',
    $h->context['vouchers'] === false && !str_contains($main, 'aria-label="Beleg') && str_contains($main, '--be-list-cols: 8.5rem 7rem 9.5rem'));
check('S6 the tabs: «Buchungen» → list?year=2026, «Abschlüsse und MWST» active; the crumb «Finanzen › Änderungsprotokoll › Abschlüsse und MWST»; the rail selection links this tab per year and «Alle Jahre»',
    str_contains($out['hc2'], '<a class="be-viewtabs__tab" href="/backend/finance/change-log/list?year=2026">Buchungen</a>')
    && str_contains($out['hc2'], '<a class="be-viewtabs__tab is-active" href="/backend/finance/change-log/closes-and-vat?year=2026" aria-current="page">Abschlüsse und MWST</a>')
    && str_contains($out['hc3'], '<a href="/backend/finance/change-log/list?year=2026">Änderungsprotokoll</a>') && str_contains($out['hc3'], '<span class="be-crumb__here">Abschlüsse und MWST</span>')
    && str_contains($out['railSelect'], 'href="/backend/finance/change-log/closes-and-vat?year=2026" aria-current="true"') && str_contains($out['railSelect'], 'href="/backend/finance/change-log/closes-and-vat?all=1"'));
[$h, $out] = $clPage(['year' => '2026', 'all' => '1']);
check('S7 «Alle Jahre»: nine rows (2027 too), «alle Geschäftsjahre», the tabs keep all=1, «Zurücksetzen» keeps it',
    count($h->context['rows']) === 9 && str_contains($out['main'], 'alle Geschäftsjahre') && str_contains($out['hc2'], 'href="/backend/finance/change-log/list?all=1"')
    && str_contains($out['railSelect'], '?all=1" aria-current="true"'));
[$h, $out] = $clPage(['year' => '2026', 'f_what' => 'Eingereicht']);
[$h2, $out2] = $clPage(['year' => '2026', 'f_what' => 'aufgehoben', 'f_who' => 'adm']);
check('S8 a «Was» no kind carries is marked invalid and narrows nothing (eight rows); «aufgehoben» + «adm» → the lift alone',
    $h->context['state']->isInvalid('f_what') && count($h->context['rows']) === 8 && count($h2->context['rows']) === 1 && $h2->context['rows'][0]['kind'] === 'vat-lifted');
$GLOBALS['harnessFetch'] = true;
[$h, $out] = $clPage(['year' => '2026', 'f_what' => 'Jahr']);
$GLOBALS['harnessFetch'] = false;
check('S9 a FETCH of the region (search, page, sort) answers the list alone: main only, no tabs, no rail selection, no crumb, no help',
    array_keys($h->layoutManager->sections) === ['main'] && count($h->context['rows']) === 3 && !$h->help->has());
[$h, $out] = $clPage(['year' => '2027', 'f_subject' => '01.01.2020']);
check('S10 a search that finds nothing → «Kein Eintrag gefunden.», «Zurücksetzen» offered', $h->context['rows'] === [] && str_contains($out['main'], 'Kein Eintrag gefunden.')
    && str_contains($out['main'], 'Zurücksetzen'));
[$h, $out] = $clPage(['year' => '2026'], 'list');
check('S11 the first tab «Buchungen» carries the same tabs (it active, the other linked with the year), its year selection, no crumb of its own; its list unchanged',
    $h->layoutManager->sections['hc2'] === ['Backend/ChangeLogController/tabs'] && $h->layoutManager->sections['railSelect'] === ['Backend/partials/fiscalYearSelect'] && empty($h->layoutManager->sections['hc3'])
    && str_contains($out['hc2'], 'is-active" href="/backend/finance/change-log/list?year=2026" aria-current="page">Buchungen</a>')
    && str_contains($out['hc2'], 'href="/backend/finance/change-log/closes-and-vat?year=2026">Abschlüsse und MWST</a>') && str_contains($out['main'], 'data-fetch-region="change-log-find-list"'));
$GLOBALS['harnessVoucherBase'] = '/backend/finance/vat-return/voucher';
[$h, $out] = $clPage(['year' => '2026']);
$GLOBALS['harnessVoucherBase'] = null;
check('S12 once a voucher action exists, the voucher slot leads every row: a VAT row links the archived PDF of ITS revision (?id=), opened in a new tab without the pending state; a year row keeps the cell empty',
    $h->context['vouchers'] === true && str_contains($out['main'], 'href="/backend/finance/vat-return/voucher?id=' . $q1r1 . '" target="_blank" rel="noopener" data-no-pending')
    && substr_count($out['main'], '/backend/finance/vat-return/voucher?id=') === 5 && str_contains($out['main'], '--be-list-cols: 2rem 8.5rem'));
$help = (string) file_get_contents($package . '/res/view/templates/Backend/ChangeLogController/closesAndVat.help.tpl.php');
check('S13 the help of the tab: a general part naming both tabs and the four kinds, one section per search field; the first tab\'s help names the second tab',
    str_contains($help, '<h3>Abschlüsse und MWST</h3>') && array_reduce(['f_at', 'f_who', 'f_what', 'f_subject'], fn($ok, $f) => $ok && str_contains($help, 'data-help-field="' . $f . '"'), true)
    && array_reduce(array_values(CloseProtocolListing::KIND_LABELS), fn($ok, $l) => $ok && str_contains($help, $l), true)
    && str_contains((string) file_get_contents($package . '/res/view/templates/Backend/ChangeLogController/list.help.tpl.php'), '«Abschlüsse und MWST»'));

// ─────────────────────────────────────────────────────────────────────────
echo "C. The year-close modal shows the VAT hint as information — no tick, nothing blocked by it\n";

$GLOBALS['harnessChecks'] = new OpenWorkChecks([]);
/** A host double of the fiscal-year trait (the YC harness's): the registry the harness controls, an admin actor. */
$fyHost = static function () {
    return new class {
        use FiscalYearControllerTrait { confirmCloseAction as public; closeAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public function __construct()
        {
            $this->help = new \Z77\Core\Services\HelpService();
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function fiscalYearCanReach(string $action): bool { return true; }
        private function fiscalYearCloseService(): FiscalYearCloseService { return new FiscalYearCloseService($this->em(), 'admin', $GLOBALS['harnessChecks']); }
    };
};
$modal = static function () use ($wire, $fyHost, $renderer, $yearId): array {
    $wire();
    $_GET = ['id' => (string) $yearId('2026')];
    $h    = $fyHost();
    $h->confirmCloseAction();

    return [$h, implode('', array_map(static fn(string $p): string => $renderer->partial($p, $h->context), $h->layoutManager->sections['main'] ?? []))];
};
[$h, $html] = $modal();
check('C1 liable with «Abrechnung ab» (the VAT check runs) → no hint; the modal as before', $h->context['hint'] === null && !str_contains($html, 'data-close-hint') && str_contains($html, 'data-fetch-post'));
$warningsOnly = $h->context['warningsHash'];   // C1: the close's own warning «endet erst am …» (2026 has not ended)
$db->executeStatement('UPDATE mandator SET vat_settlement_from = NULL WHERE id = 1');
[$h, $html] = $modal();
$listed = preg_match('/<ul data-close-warnings>(.*?)<\/ul>/s', $html, $wm) === 1 ? $wm[1] : '';
check('C2 liable WITHOUT «Abrechnung ab» → the hint (UnsettledVatPeriodsCheck::HINT_NO_START) AFTER the findings, marked «Hinweis MWST», as information: not among the warnings that need the tick, the warnings fingerprint unchanged by it, the form still submits',
    $h->context['hint'] === UnsettledVatPeriodsCheck::HINT_NO_START && str_contains($html, '<p class="be-form__hint" data-close-hint><strong>Hinweis MWST:</strong> ' . e(UnsettledVatPeriodsCheck::HINT_NO_START) . '</p>')
    && str_contains($html, 'data-fetch-post="/backend/finance/fiscal-year/close"') && $h->context['warningsHash'] === $warningsOnly
    && $listed !== '' && !str_contains($listed, 'Abrechnung ab') && strpos($html, 'data-close-warnings') < strpos($html, 'data-close-hint'));
HarnessCloseFindings::$findings = [Finding::blocking('Rechnung 7 ist noch in Fakturierung', 'invoice:7')];
$GLOBALS['harnessChecks'] = new OpenWorkChecks(['period-close' => [HarnessCloseFindings::class]]);
[$h, $html] = $modal();
check('C3 beside a BLOCKING finding the hint stands under the list — the block is the finding\'s, not the hint\'s',
    str_contains($html, 'Abschliessen nicht möglich') && str_contains($html, 'Rechnung 7 ist noch in Fakturierung') && str_contains($html, 'data-close-hint') && !str_contains($html, 'data-fetch-post'));
$GLOBALS['harnessChecks'] = new OpenWorkChecks([]);
$db->executeStatement("UPDATE mandator SET vat_settlement_from = '2026-01-01', liable_to_vat = 0, vat_settlement_until = NULL WHERE id = 1");
[$h, $html] = $modal();
check('C4 returns are posted and the liability switched off without «Abrechnung bis» → HINT_NO_END (the false pass of review finding 5 is now SEEN at the close)',
    $h->context['hint'] === UnsettledVatPeriodsCheck::HINT_NO_END && str_contains($html, 'data-close-hint') && str_contains($html, '«Abrechnung bis» festlegen'));
// The tick confirms the warnings the modal listed (the close's own «endet erst am …») — the hint is not among them.
$GLOBALS['harnessBody'] = ['id' => $yearId('2026'), 'entity_csrf' => 'tok-fiscalYear.close-' . $yearId('2026'), 'confirm_warnings' => true, 'warnings_hash' => $h->context['warningsHash']];
$wire();
$hc  = $fyHost();
$env = (fn() => $this->build())->call($hc->closeAction());
check('C5 what blocks is unchanged: with the hint shown, the POST with the tick for the LISTED warnings closes 2026 (the hint is no finding and is not part of the confirmed fingerprint)',
    ($env['status'] ?? '') !== 'error' && (int) $db->fetchOne("SELECT COUNT(*) FROM fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id WHERE y.code = '2026' AND p.state <> 'closed'") === 0,
    json_encode([$env, $hc->messageService->flashes], JSON_UNESCAPED_UNICODE));

// ── result ───────────────────────────────────────────────────────────────

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
