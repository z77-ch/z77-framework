<?php

/**
 * Chart-of-accounts screen harness (CLI) — how the screen «Kontenplan» shows the mandator's VAT
 * accounts under the lock (owner decision S4-17 of 2026-11-25, board S4-3 parts 1 and 2; ADR-042
 * addendum 2 of 2026-11-25, point 7: the VAT accounts are READ FROM THE MANDATOR, no flag):
 *
 *   O. (offline) source guards: the templates ask no service (the trait hands the lock in), the
 *      trait reads the lock through `VatAccountMap` only, one read per answer (list, dialog, row
 *      answer) — never per row; the look is the shared `.badge--info` and `.be-modal__alert--info`
 *      (SCSS source and the compiled `base.css`);
 *   M. (database) a throwaway schema: vat, mandator, financial migrated; the KMU chart adopted, a
 *      mandator saved with the prefilled accounts 2200 / 1170 / 1171;
 *   V. the lock on FIXED days through the trait (`accountVatLock()`): shown as soon as «Abrechnung
 *      ab» is SET — also on a day before it (owner C-A, 2026-11-25: the text names the date) —, on
 *      it, after «Abrechnung bis», not liable — account numbers as strings, with «Abrechnung ab»;
 *   L. the list: «nur MWST» with its title on exactly the three rows, «nur Modul» stays on 1100 and
 *      1179, the settlement account 2201 stays free; a VAT account carrying the module flag shows
 *      «nur MWST» only; the badge with a «Abrechnung ab» still ahead; none without liability,
 *      without «Abrechnung ab», after «Abrechnung bis»;
 *      the mandator names another account → the badge moves (read from the mandator, no migration);
 *   D. the dialog «Konto bearbeiten»: the fixed line with the link instead of the switch on a VAT
 *      account, also with a «Abrechnung ab» still ahead; the switch on 1100, on 2201 and on «Konto
 *      anlegen»; the switch back once the lock does not apply (not liable); the row answers (switch «aktiv», «Konto anlegen» of an account the mandator
 *      already names) carry the badge;
 *   H. the list's help names «nur MWST».
 *
 * The lock days of V use fixed dates; L / D need «today» inside or outside the lock and therefore
 * set «Abrechnung ab» / «bis» far in the past or the future. Every name here is synthetic.
 *
 * Run: php tests/financial-account-ui.php (the runner learns it when the harness is registered)
 * O needs the monorepo's vendor/; M–H a reachable MariaDB and the `z77test` credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*; without them M is reported SKIPPED.
 */

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "financial-account-ui: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Financial\Ui\AccountControllerTrait;
use Z77\Module\Financial\Ui\VatReturnLayout;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

// ─────────────────────────────────────────────────────────────────────────
echo "O. Source guards and the look (offline)\n";

$package   = $root . '/packages/module-financial';
$tplDir    = $package . '/res/view/templates/Backend/AccountController';
$trait     = (string) file_get_contents($package . '/src/Ui/AccountControllerTrait.php');
$templates = implode("\n", array_map(static fn(string $f): string => (string) file_get_contents($f), glob($tplDir . '/*.tpl.php') ?: []));

check('O1 the templates ask no service — the lock is handed in (no VatAccountMap / LedgerService / CurrentMandator / em in a template)',
    $templates !== '' && preg_match('/VatAccountMap|LedgerService|CurrentMandator|VatSettings|getRepository|->em\(/', $templates) === 0);
check('O2 the trait reads the lock through VatAccountMap::lockedAccounts() — no record read of its own, no flag on the account',
    str_contains($trait, '->lockedAccounts(') && !str_contains($trait, 'CurrentMandator') && !preg_match('/isModulePostingOnly\(\)\s*\|\|.*vat/i', $trait));
check('O3 one read per answer: accountVatLock() is called by the list, the dialog and the row answer — three places, none inside a loop',
    substr_count($trait, '$this->accountVatLock()') === 3
    && preg_match('/foreach\s*\([^)]*\)\s*\{[^}]*accountVatLock/s', $trait) === 0);
$alerts = (string) file_get_contents($root . '/packages/module-backend/res/scss/components/_alerts.scss');
$css    = (string) file_get_contents($root . '/packages/module-backend/res/assets/css/base.css');
check('O4 the look: the shared `.badge--info` for the badge; the fixed line `.be-modal__alert--info` in the SCSS source AND in the compiled base.css',
    str_contains($templates, 'badge badge--info') && str_contains($templates, 'be-modal__alert be-modal__alert--info')
    && str_contains($alerts, '.be-modal__alert--info') && str_contains($css, '.be-modal__alert--info{'));

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
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

echo "M. Database (throwaway schema)\n";

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-financial-account-ui-' . getmypid());
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
    'Financial' => $root . '/packages/module-financial',
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

/** A fresh request: new DI, new UnifiedEntityManager, empty identity map (the mandator record is read anew). */
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
    \Z77\Core\DI::getInstance(true)
        ->set('CacheManager', \Z77\Core\Libraries\CacheManager::class, true)
        ->set('FileFinder', fn($c) => new \Z77\Core\Libraries\FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new \Z77\Core\Libraries\ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new \Z77\Core\Services\ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new \Z77\Core\Services\I18n($c->get('ConfigManager')), true)
        ->set('Translator', fn() => new \Z77\Core\Services\Translator(), true)
        ->set('DataSourceResolver', fn() => new \Z77\Persistence\Resolver\DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new \Z77\Persistence\Resolver\UnifiedEntityManager($c->get('DataSourceResolver')), true);
    \Z77\Core\DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return $previous = \Z77\Core\DI::getUnifiedEntityManager();
};
$em = $wire();

$db = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);

$app = \Z77\Persistence\Doctrine\Console\MigrationsApplication::create(
    \Z77\Persistence\Doctrine\Bootstrap::buildEntityManager(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::collect(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder()),
    \Z77\Core\DI::getCacheManager()->generatedPhp(),
    \Z77\Persistence\Doctrine\Console\MigrationDirectories::missing(\Z77\Core\DI::getModuleManager(), \Z77\Core\DI::getFileFinder())
);
$app->setAutoExit(false);
$migrateOut = new \Symfony\Component\Console\Output\BufferedOutput();
$code       = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate', '--no-interaction' => true]), $migrateOut);
check('M1 migrate exits 0 (vat, mandator, financial)', $code === 0, trim($migrateOut->fetch()));
if ($code !== 0) {
    echo "\n{$pass} passed, {$fail} failed\n";
    exit(1);
}

(new \Z77\Module\Financial\Services\AccountService($wire()))->adoptKmuChart();
$record = \Z77\Module\Mandator\Services\MandatorAccounts::prefilled();
$record->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich', 'liable_to_vat' => true, 'uid' => 'CHE-116.281.710']);
(new \Z77\Module\Mandator\Services\MandatorService($wire()))->save($record);
/** The mandator's columns, written as the record stores them. */
$mandator = static function (array $columns) use ($db): void {
    $db->executeStatement('UPDATE mandator SET ' . implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($columns))), array_values($columns));
};
$named = $db->fetchAssociative('SELECT account_vat_owed, account_vat_input_material, account_vat_input_other, liable_to_vat FROM mandator');
check('M2 KMU chart adopted, the mandator saved: liable, VAT accounts 2200 / 1170 / 1171 (prefilled)',
    $named !== false && [$named['account_vat_owed'], $named['account_vat_input_material'], $named['account_vat_input_other']] === ['2200', '1170', '1171'] && (int) $named['liable_to_vat'] === 1,
    json_encode($named));

// ── host double: the backend surface the trait uses ──
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';
$useRequest = function (array $get, bool $post = false, array $json = []) use ($wire): void {
    $wire();
    \Z77\Core\DI::getInstance()->set('Request', fn() => new class($get, $post, $json) {
        public function __construct(private array $get, private bool $post, private array $json) {}
        public function getGetParameter(string $p): mixed { return $this->get[$p] ?? null; }
        public function isPost(): bool { return $this->post; }
        public function getPostParameters(): array { return $this->json; }
        public function getMode(): \Z77\Core\Http\RequestMode { return \Z77\Core\Http\RequestMode::Fetch; }
        public function getJsonBody(): array { return $this->json; }
    }, true);
    \Z77\Core\DI::getInstance()->set('CsrfService', fn() => new class {
        public function getToken(): string { return 'tok-session'; }
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
};
$host = static fn() => new class {
    use AccountControllerTrait { listAction as public; editAction as public; addAction as public; toggleActiveAction as public; accountVatLock as public; }
    public array $context = [];
    public object $layoutManager;
    public object $messageService;
    public object $help;
    public function __construct()
    {
        $this->help          = new \Z77\Core\Services\HelpService();
        $this->layoutManager = new class {
            public array $sections = [];
            public function removeSection(string $s): void { unset($this->sections[$s]); }
            public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
        };
        $this->messageService = new class {
            public array $flashes = [];
            public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message, 'in-place']; }
        };
    }
    protected function em() { return \Z77\Core\DI::getUnifiedEntityManager(); }
    protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
    protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
    protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->messageService->pushFlash('error', $text); return $this->fetch()->setStatus('error'); }
};
$render   = static fn(string $path, array $context): string => (new \Z77\Core\Services\TemplateRenderer('Z77\\Module\\Financial'))->partial($path, $context + ['csrfToken' => 'tok-session']);
$envelope = static fn(\Z77\Core\Http\Response\FetchResponse $r): string => json_encode((fn() => $this->build())->call($r), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$idOf     = static fn(string $number): int => (int) $db->fetchOne('SELECT id FROM account WHERE number = ?', [$number]);

/** The lock of the trait on a fixed day, on a fresh request: [numbers sorted, «Abrechnung ab» or null]. */
$lockOn = static function (string $ymd) use ($useRequest, $host): array {
    $useRequest([]);
    $lock = $host()->accountVatLock(new \DateTimeImmutable($ymd));
    $numbers = $lock['accounts'];
    sort($numbers);

    return [$numbers, $lock['from']?->format('Y-m-d'), array_filter($lock['accounts'], 'is_string') === $lock['accounts']];
};
/** The list page on a fresh request: the rendered list. */
$list = static function () use ($useRequest, $host, $render): string {
    $useRequest([]);
    $h = $host();
    $h->listAction();

    return $render('Backend/AccountController/listAction', $h->context);
};
/** One row of the rendered list, by account number. */
$rowOf = static function (string $html, string $number): string {
    foreach (explode('<div class="be-tree__node', $html) as $chunk) {
        if (str_contains($chunk, '<code>' . $number . '</code>')) {
            return $chunk;
        }
    }

    return '';
};
/** The dialog «Konto bearbeiten» of $number on a fresh request. */
$dialog = static function (string $number) use ($useRequest, $host, $render, $idOf): string {
    $useRequest(['id' => (string) $idOf($number)]);
    $h = $host();
    $h->editAction();

    return $render('Backend/AccountController/edit', $h->context);
};
$badge = static fn(string $from): string => '<span class="badge badge--info" title="MWST-Konto des Mandanten: ab ' . $from . ' nur Steuerzeilen aus MWST-Codes">nur MWST</span>';
$line  = static fn(string $from): string => 'MWST-Konto des Mandanten — ab ' . $from . ' («Abrechnung ab») nur Steuerzeilen, die z77 aus den MWST-Codes rechnet.';

// ─────────────────────────────────────────────────────────────────────────
echo "V. The lock on fixed days (through the trait)\n";

check('V1 liable, no «Abrechnung ab»: no VAT account is locked', $lockOn('2027-03-01') === [[], null, true], json_encode($lockOn('2027-03-01')));
$mandator(['vat_settlement_from' => '2027-01-01']);
check('V2 «Abrechnung ab» 01.01.2027 — shown as soon as it is SET (owner C-A): on 31.12.2026 already the three accounts 1170 / 1171 / 2200, as STRINGS, with «Abrechnung ab»; on 01.01.2027 the same',
    $lockOn('2026-12-31') === [['1170', '1171', '2200'], '2027-01-01', true] && $lockOn('2027-01-01') === [['1170', '1171', '2200'], '2027-01-01', true], json_encode([$lockOn('2026-12-31'), $lockOn('2027-01-01')]));
$mandator(['vat_settlement_until' => '2027-12-31']);
check('V3 «Abrechnung bis» 31.12.2027 — on that day still locked, on 01.01.2028 the lock is off',
    $lockOn('2027-12-31') === [['1170', '1171', '2200'], '2027-01-01', true] && $lockOn('2028-01-01') === [[], null, true]);
$mandator(['liable_to_vat' => 0]);
check('V4 not liable: nothing locked, also inside the window', $lockOn('2027-06-30') === [[], null, true]);
$mandator(['liable_to_vat' => 1, 'vat_settlement_until' => null]);

// ─────────────────────────────────────────────────────────────────────────
echo "L. The list «Kontenplan»\n";

$mandator(['vat_settlement_from' => '2020-01-01']);   // the lock applies today, whatever day the harness runs
$html = $list();
check('L1 «nur MWST» with its title on exactly the three VAT accounts 1170 / 1171 / 2200 — beside the existing badges, in the state cell',
    substr_count($html, '>nur MWST</span>') === 3
    && str_contains($rowOf($html, '1170'), $badge('01.01.2020')) && str_contains($rowOf($html, '1171'), $badge('01.01.2020')) && str_contains($rowOf($html, '2200'), $badge('01.01.2020'))
    && preg_match('~data-field="state">\s*<span class="badge badge--info" title="MWST-Konto~', $rowOf($html, '1170')) === 1,
    $rowOf($html, '1170'));
check('L2 «nur Modul» stays on 1100 (Debitoren) and 1179 (Vorsteuer, noch nicht abgerechnet); the settlement account 2201 and the rounding account 6945 carry no badge',
    str_contains($rowOf($html, '1100'), '>nur Modul</span>') && str_contains($rowOf($html, '1179'), '>nur Modul</span>')
    && !str_contains($rowOf($html, '1179'), 'nur MWST') && $rowOf($html, '2201') !== '' && !str_contains($rowOf($html, '2201'), 'badge')
    && ($rowOf($html, '6945') === '' || !str_contains($rowOf($html, '6945'), 'nur MWST')));
$db->executeStatement("UPDATE account SET module_posting_only = 1 WHERE number = '1170'");
$html = $list();
check('L3 a VAT account that also carries the module flag shows «nur MWST» only — under the lock the flag does not decide',
    str_contains($rowOf($html, '1170'), 'nur MWST') && !str_contains($rowOf($html, '1170'), 'nur Modul'));
$db->executeStatement("UPDATE account SET module_posting_only = 0 WHERE number = '1170'");

$mandator(['liable_to_vat' => 0]);
$notLiable = $list();
$mandator(['liable_to_vat' => 1, 'vat_settlement_from' => null]);
$withoutFrom = $list();
$mandator(['vat_settlement_from' => '2099-01-01']);
$ahead = $list();
$mandator(['vat_settlement_from' => '2020-01-01', 'vat_settlement_until' => '2020-12-31']);
$after = $list();
$mandator(['vat_settlement_until' => null]);
check('L4 no badge without liability, without «Abrechnung ab» or after «Abrechnung bis» (31.12.2020); with «Abrechnung ab» still ahead (01.01.2099) the badge stands on the three rows with that date (owner C-A)',
    !str_contains($notLiable . $withoutFrom . $after, 'nur MWST') && substr_count($withoutFrom, 'data-entity="account:') > 100
    && substr_count($ahead, '>nur MWST</span>') === 3 && str_contains($rowOf($ahead, '1170'), $badge('01.01.2099')));

$mandator(['account_vat_input_other' => '1176']);
$html = $list();
check('L5 the mandator names 1176 for «Vorsteuer Investitionen, übriger Betriebsaufwand»: the badge moves at once — 1176 locked, 1171 free (no flag, no migration)',
    str_contains($rowOf($html, '1176'), 'nur MWST') && !str_contains($rowOf($html, '1171'), 'nur MWST') && substr_count($html, '>nur MWST</span>') === 3);
$mandator(['account_vat_input_other' => '1171']);

// ─────────────────────────────────────────────────────────────────────────
echo "D. The dialog «Konto bearbeiten» and the row answers\n";

$d1170 = $dialog('1170');
check('D1 1170: the fixed line with the badge and the link «Mandanten, Reiter «Konten»» stands where the switch was — the switch is not rendered',
    str_contains($d1170, $line('01.01.2020')) && str_contains($d1170, 'class="be-modal__alert be-modal__alert--info" data-vat-account')
    && str_contains($d1170, 'Festgelegt im <a href="' . VatReturnLayout::MANDATOR_URL . '">Mandanten, Reiter «Konten»</a>.')
    && str_contains($d1170, '<span class="badge badge--info">nur MWST</span>')
    && !str_contains($d1170, 'name="module_posting_only"') && !str_contains($d1170, 'Nur durch Module bebuchbar'), $d1170);
$d1100 = $dialog('1100');
$d2201 = $dialog('2201');
check('D2 1100 (Debitoren) and 2201 (Abrechnungskonto MWST) keep the switch «Nur durch Module bebuchbar», no fixed line',
    str_contains($d1100, 'name="module_posting_only"') && str_contains($d2201, 'name="module_posting_only"') && !str_contains($d1100 . $d2201, 'data-vat-account'));
$useRequest([]);
$addHost = $host();
$addHost->addAction();
$dAdd = $render('Backend/AccountController/edit', $addHost->context);
check('D3 «Konto anlegen» keeps the switch (a new account is no VAT account yet)', str_contains($dAdd, 'name="module_posting_only"') && !str_contains($dAdd, 'data-vat-account'));
$mandator(['vat_settlement_from' => '2099-01-01']);
$dAhead = $dialog('1170');
$mandator(['liable_to_vat' => 0]);
$dOff = $dialog('1170');
$mandator(['liable_to_vat' => 1, 'vat_settlement_from' => '2020-01-01']);
check('D4 with «Abrechnung ab» still ahead (01.01.2099) the dialog of 1170 already shows the fixed line with that date (owner C-A); not liable: the switch as before, no fixed line',
    str_contains($dAhead, $line('01.01.2099')) && !str_contains($dAhead, 'name="module_posting_only"')
    && str_contains($dOff, 'name="module_posting_only"') && !str_contains($dOff, 'data-vat-account'));

$useRequest(['id' => (string) $idOf('1171')], true, ['value' => true]);
$toggle = $envelope($host()->toggleActiveAction());
check('D5 the switch «aktiv» answers with the row (replaceRow) — the badge stays on it', str_contains($toggle, 'nur MWST') && str_contains($toggle, 'MWST-Konto des Mandanten: ab 01.01.2020'), $toggle);
$mandator(['account_vat_input_other' => '1172']);
$useRequest([], true, ['number' => '1172', 'name' => 'Vorsteuer MWST übriger Betriebsaufwand (neu)', 'type' => 'asset', 'postable' => '1', 'parent_id' => (string) $idOf('114')]);
$added = $envelope($host()->addAction());
check('D6 «Konto anlegen» of 1172, which the mandator already names: the inserted row carries «nur MWST»', $idOf('1172') > 0 && str_contains($added, 'nur MWST'), $added);
$mandator(['account_vat_input_other' => '1171']);

// ─────────────────────────────────────────────────────────────────────────
echo "H. The help\n";

$help = $render('Backend/AccountController/list.help', []);
check('H1 the list\'s help names «nur MWST»: read from the mandator (Reiter «Konten»), «Abrechnung ab» to «Abrechnung bis», the fixed line in the dialog, the settlement account stays free',
    str_contains($help, '<strong>nur MWST</strong>') && str_contains($help, 'legt der Mandant fest (Reiter «Konten»)')
    && str_contains($help, '«Abrechnung ab» bis «Abrechnung bis»') && str_contains($help, 'eine feste Zeile') && str_contains($help, 'Das Abrechnungskonto MWST bleibt frei.'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
