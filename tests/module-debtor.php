<?php

/**
 * module-debtor harness (CLI) — P3 part 1: the receivables MASTER DATA
 * (plan §6.1) against a REAL MariaDB (ADR-039 decision 16), throwaway
 * schema per run, created by the modules' own MIGRATIONS through the
 * `z77-db` application (never `SchemaTool`): the harness proves the
 * migration as much as the module.
 *
 * What is load-bearing here:
 *
 *   - (A) `migrate` on an empty database creates `debtor_profile` in
 *     `utf8mb4_unicode_ci` / InnoDB next to contact's and financial's
 *     tables, a second `migrate` is a no-op and `diff` reports NO change
 *     afterwards — the mapping and the migration agree; the profile's only
 *     foreign key goes to `contact`, none to the file-based master data;
 *   - (B) the seeds of the two seeded file entities — «30 Tage netto» and
 *     «10 Tage 2 %, 30 Tage netto», three dunning levels — pass their
 *     validators, resolve by code and keep their umlauts (UTF-8 without
 *     BOM, DATA-JSON-001); payment targets are deliberately NOT seeded;
 *   - (C) payment terms: due days, the discount tiers (percent as an
 *     integer in hundredths, ascending days, falling percent, at most two)
 *     and the per-language document text with its default-language rule;
 *   - (D) the IBAN: MOD-97-10 check digits on REAL numbers, a transposed
 *     pair refused, the QR-IBAN IID range 30000–31999 at its edges, CH / LI
 *     only, and the payment target's ledger account checked softly against
 *     financial — refused when it is a group or inactive, unverified when
 *     module-financial is absent;
 *   - (E) dunning levels: unique level, the fee as `Money` from stored
 *     minor units, no VAT field anywhere in the entity (plan §6.5);
 *   - (F) the debtor profile: CRUD through the service, ONE profile per
 *     contact (validator and unique index), validate-before-mutate on an
 *     update, deactivate — never delete;
 *   - (G) the reference rule for EVERY file master-data type (ADR-043
 *     decision 19): a NEW reference needs an active row, an existing one
 *     keeps a deactivated row, the code is immutable, and no delete method
 *     exists on the write side or the screens;
 *   - (H) the `debtorAccounts` config accessor: the KMU defaults, a missing
 *     key, a malformed value and an account that is a group / inactive /
 *     unknown — each refused with a German message naming the key;
 *   - (I) the four backend fragments render through their traits with their
 *     own header slots, and carry no JavaScript of their own (Rule 7).
 *
 * P3 part 2 — `InvoicingService`, the documents and the accounting port
 * (plan §6.2, §6.6), on top of the state the sections above leave:
 *
 *   - (J) `invoice()`: a draft becomes a document in state `invoicing` —
 *     the number drawn ONCE from `invoice`, the full snapshot (address,
 *     language, terms as applied, lines with rate and label, the tax
 *     summary per code, totals), the 0.05 rounding as a separate line —
 *     also when it is 0.00 (no line) —, line types, a parent with children
 *     at 0.00, a negative quantity, a text line, gross price mode, the rate
 *     by SERVICE date across the 2024 rate change, and NOTHING posted;
 *     every refusal a draft can earn, without a number consumed;
 *   - (K) `reinvoice()`: the same number, a new snapshot, the version
 *     bumped, a stale version refused, the kind immutable, still nothing
 *     posted;
 *   - (L) `finalize()`: posted ONCE through `LedgerAccountingGateway`, the
 *     posting shape (receivable = gross, revenue per line with the tax data
 *     of the net method, VAT per code, rounding; Σ debit = Σ credit), the
 *     open amount, a second finalize refused, a batch where one document
 *     fails rolled back whole (no state, no number, no entry), the
 *     `NullAccountingGateway`, the gateway selection by config, the
 *     document immutable in `final`, the credit note as the only correction
 *     with its mirrored posting, a tax code with lines of MIXED signs, a
 *     zero document posting nothing;
 *   - (M) source guards: exactly two classes name module-financial, the
 *     document has no setters, no float, the migration count.
 *
 * Run: php tests/module-debtor.php
 * Needs what tests/module-contact.php needs (vendor/ with Doctrine, a
 * reachable MariaDB, credentials in `%USERPROFILE%\.z77\mariadb.txt` or
 * Z77_TEST_DB_*). Nothing is written into the repository; the schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-debtor: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\I18n;
use Z77\Core\Services\ModuleManager;
use Z77\Module\Contact\Entities\Address;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Services\ContactService;
use Z77\Module\Debtor\Accounting\AccountingGateways;
use Z77\Module\Debtor\Accounting\AccountingRefusedException;
use Z77\Module\Debtor\Accounting\AccountingUnavailableException;
use Z77\Module\Debtor\Accounting\LedgerAccountingGateway;
use Z77\Module\Debtor\Accounting\NullAccountingGateway;
use Z77\Module\Debtor\Accounting\PostingLine as DebtorPostingLine;
use Z77\Module\Debtor\Accounting\PostingRequest as DebtorPostingRequest;
use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\DunningLevel;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceKind;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\InvoiceState;
use Z77\Module\Debtor\Entities\InvoiceTax;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Invoicing\InvoiceDraft;
use Z77\Module\Debtor\Invoicing\LineDraft;
use Z77\Module\Debtor\Invoicing\PostingBuilder;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Services\InvoiceConflictException;
use Z77\Module\Debtor\Services\InvoiceRefusedException;
use Z77\Module\Debtor\Services\InvoicingService;
use Z77\Module\Debtor\Repositories\DunningLevelRepository;
use Z77\Module\Debtor\Repositories\PaymentTargetRepository;
use Z77\Module\Debtor\Repositories\PaymentTermsRepository;
use Z77\Module\Debtor\Services\AccountNotConfiguredException;
use Z77\Module\Debtor\Services\DebtorAccounts;
use Z77\Module\Debtor\Services\DebtorCurrency;
use Z77\Module\Debtor\Services\DebtorMasterData;
use Z77\Module\Debtor\Services\DebtorProfileService;
use Z77\Module\Debtor\Services\Iban;
use Z77\Module\Debtor\Services\InvalidDebtorProfileException;
use Z77\Module\Debtor\Services\InvalidMasterDataException;
use Z77\Module\Debtor\Services\LedgerAccountCheck;
use Z77\Module\Debtor\Services\MasterDataCodeChangedException;
use Z77\Module\Debtor\Ui\DebtorControllerTrait;
use Z77\Module\Debtor\Ui\DebtorLayout;
use Z77\Module\Debtor\Ui\DocumentTextForm;
use Z77\Module\Debtor\Ui\DunningLevelControllerTrait;
use Z77\Module\Debtor\Ui\DunningLevelLayout;
use Z77\Module\Debtor\Ui\PaymentTargetControllerTrait;
use Z77\Module\Debtor\Ui\PaymentTargetLayout;
use Z77\Module\Debtor\Ui\PaymentTermsControllerTrait;
use Z77\Module\Debtor\Ui\PaymentTermsLayout;
use Z77\Module\Debtor\Validators\DebtorProfileValidator;
use Z77\Module\Debtor\Validators\DunningLevelValidator;
use Z77\Module\Debtor\Validators\PaymentTargetValidator;
use Z77\Module\Debtor\Validators\PaymentTermsValidator;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Services\AccountService;
use Z77\Module\Financial\Services\FiscalYearService;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Services\VatMasterData;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Runs $fn and returns the exception when it throws $class, null otherwise. */
function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

function throws(callable $fn, string $class): bool
{
    return caught($fn, $class) !== null;
}

function day(string $ymd): \DateTimeImmutable
{
    return new \DateTimeImmutable($ymd);
}

function chf(string $decimal): Money
{
    return Money::fromDecimal($decimal, 'CHF');
}

// ── credentials: never in the repository ─────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        if (str_starts_with($line, $credentials['user'] . '=')) {
            $credentials['password'] = substr($line, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '') {
    fwrite(STDERR, "module-debtor: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-debtor-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);

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

// The REAL packages are the modules' source paths: their configs announce
// the entities, their res/migrations hold the migrations, their data/ the
// seeds. debtor needs contact (the party) and, for the SOFT account check,
// financial — which a project may leave out, and section H proves that too.
$packages = [
    'Vat'       => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-vat')),
    'Contact'   => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-contact')),
    'Financial' => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-financial')),
    'Debtor'    => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-debtor')),
];
$package      = $packages['Debtor'];
$overrideRoot = $base . '/override/module/debtor';
$namespaces   = '';
foreach ($packages as $name => $path) {
    // The Debtor namespace looks in an override root FIRST — the project layout
    // a `debtorConfig.inc.php` override lands in (BOOT-CONFIG-001, section H).
    $sources     = $name === 'Debtor' ? "'{$overrideRoot}', '{$path}'" : "'{$path}'";
    $namespaces .= "'Z77\\\\Module\\\\{$name}\\\\' => ['sourcePaths' => [{$sources}]],\n";
}
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$writeModules = function (bool $withFinancial) use ($write): void {
    $modules = $withFinancial ? "'vat' => [], 'contact' => [], 'financial' => [], 'debtor' => []" : "'vat' => [], 'contact' => [], 'debtor' => []";
    $write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'debtor', 'modules' => [{$modules}]];");
};
$writeModules(true);
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de', 'fr']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// Seed the way the installer does: `*.default.json` → `data/…` with the marker stripped.
@mkdir($base . '/data/framework/contact', 0777, true);
copy($packages['Contact'] . '/data/framework/contact/address_types.default.json', $base . '/data/framework/contact/address_types.json');
@mkdir($base . '/data/framework/debtor', 0777, true);
foreach (glob($package . '/data/framework/debtor/*.default.json') as $seed) {
    copy($seed, $base . '/data/framework/debtor/' . str_replace('.default.json', '.json', basename($seed)));
}
// The tax codes an invoice line references (module-vat, file-based): the CH seed since 2018.
@mkdir($base . '/data/framework/vat', 0777, true);
foreach (['tax_codes', 'tax_rates'] as $name) {
    copy($packages['Vat'] . '/data/framework/vat/' . $name . '.default.json', $base . '/data/framework/vat/' . $name . '.json');
}

/**
 * The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to
 * what the drivers, the CLI and the master-data rules read (`I18n` — the
 * document texts are validated against the installation's languages).
 * Calling it again is the «fresh request»: a new UnifiedEntityManager, a
 * new Doctrine EntityManager, an empty Identity Map.
 */
$wireDi = static function () use ($base): UnifiedEntityManager {
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new I18n($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return DI::getUnifiedEntityManager();
};
$em = $wireDi();

$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$tables    = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
$tableInfo = fn(string $table) => $db->fetchAssociative('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $table]);
$count     = fn(string $table) => (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");

/** Runs one z77-db command in-process on a fresh application; returns [exit code, output]. */
$run = static function (array $input): array {
    $app = MigrationsApplication::create(
        DoctrineBootstrap::buildEntityManager(),
        MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
        DI::getCacheManager()->generatedPhp(),
        MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
    );
    $app->setAutoExit(false);
    $out  = new BufferedOutput();
    $code = $app->run(new ArrayInput($input + ['--no-interaction' => true]), $out);

    return [$code, $out->fetch()];
};


// ── A. the migration ─────────────────────────────────────────────────────

echo "A. Migration (z77-db migrate on an empty database)\n";
$config = DI::getModuleManager()->getModuleConfig('debtor');
check('A0 the module config announces exactly the FOUR Doctrine entities — the three master-data types are file-based',
    $config?->get('doctrineEntities') === [DebtorProfile::class, Invoice::class, InvoiceLine::class, InvoiceTax::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Debtor\\Migrations', ($dirs['Z77\\Module\\Debtor\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
$executed = $db->fetchFirstColumn('SELECT version FROM schema_migration');
check('A4 … both debtor migrations ran; the run ends at the newest one (timestamp order across the modules)',
    str_contains($out, 'Migrating up to Z77\\Module\\Debtor\\Migrations\\Version20260923043935')
    && in_array('Z77\\Module\\Debtor\\Migrations\\Version20260922173918', $executed, true) && in_array('Z77\\Module\\Debtor\\Migrations\\Version20260923043935', $executed, true));
check('A5 debtor_profile, invoice, invoice_line and invoice_tax exist next to contact\'s and financial\'s tables',
    array_diff(['debtor_profile', 'invoice', 'invoice_line', 'invoice_tax'], $tables()) === []);
$rangeOf = fn(string $name) => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$name]);
check('A5b the ranges invoice and credit-note exist at 0 — created by the migration ahead of the first draw (DOCTRINE-NR-003), nothing consumed',
    (string) $rangeOf('invoice') === '0' && (string) $rangeOf('credit-note') === '0');
$invoiceFks = $db->fetchFirstColumn('SELECT DISTINCT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, 'invoice']);
check('A5c invoice references contact (the party) and itself (credit note → invoice) — no address, no terms, no tax code (they are snapshots / by code)', $invoiceFks === ['contact', 'invoice']);
$addrColumns = $db->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ? ORDER BY ORDINAL_POSITION', [$dbName, 'invoice', 'addr\_%']);
check('A5d the address snapshot is ten flat addr_* columns (the embeddable, contact.md «snapshot shape»)', count($addrColumns) === 10 && in_array('addr_zip', $addrColumns, true) && in_array('addr_country', $addrColumns, true));
foreach (['invoice', 'invoice_line', 'invoice_tax'] as $t) {
    $i = $tableInfo($t);
    check("A5e {$t} is utf8mb4_unicode_ci and InnoDB", ($i['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($i['ENGINE'] ?? '') === 'InnoDB');
}
$info = $tableInfo('debtor_profile');
check('A6 debtor_profile is utf8mb4_unicode_ci and InnoDB although the database default is general_ci',
    ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB');
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'debtor_profile']);
check('A7 … and so is every string column of it', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fks = $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, 'debtor_profile']);
check('A8 debtor_profile\'s only foreign key goes to contact — none to the file-based master data (ADR-043/19)', $fks === ['contact']);
$indexes = $db->fetchFirstColumn('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY 1', [$dbName, 'debtor_profile']);
check('A9 the unique contact index and the payment-terms index carry our names', in_array('uniq_debtor_profile_contact', $indexes, true) && in_array('idx_debtor_profile_terms', $indexes, true));
[$code, $out] = $run(['command' => 'migrate']);
check('A10 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Debtor\\Migrations']);
check('A11 diff after migrate reports NO change — mapping and migration agree (embedded address, money and decimal columns included)', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 2);
check('A12 both migrations are expand-only: no DROP outside down()', array_reduce(glob($package . '/res/migrations/Version*.php'), function ($ok, $f) {
    $s = file_get_contents($f);
    return $ok && substr_count(substr($s, 0, strpos($s, 'function down')), 'DROP') === 0;
}, true));


// ── B. the seeds ─────────────────────────────────────────────────────────

echo "B. Seeded file master data (payment terms, dunning levels)\n";
$em    = $wireDi();
$terms = $em->getRepository(PaymentTerms::class);
$levels = $em->getRepository(DunningLevel::class);
$targets = $em->getRepository(PaymentTarget::class);
check('B1 convention repositories resolve on the File driver', $terms instanceof PaymentTermsRepository && $levels instanceof DunningLevelRepository && $targets instanceof PaymentTargetRepository);
check('B2 two seeded terms rows, in file order: net-30, disc-2-10', array_map(fn(PaymentTerms $t) => $t->getCode(), $terms->allInOrder()) === ['net-30', 'disc-2-10']);
check('B3 every seeded terms row is active and passes its validator', array_reduce($terms->allInOrder(), fn($ok, PaymentTerms $t) => $ok && $t->isActive() && (new PaymentTermsValidator($t, $terms))->isValid(), true));
$net30 = $terms->findByCode('net-30');
$disc  = $terms->findByCode('disc-2-10');
check('B4 net-30: 30 days, no discount tier', $net30?->getDueDays() === 30 && $net30->getDiscounts() === []);
check('B4b the seeded German labels keep their umlauts (UTF-8 without BOM, DATA-JSON-001)',
    $levels->findByCode('reminder')?->getLabel() === 'Zahlungserinnerung' && $terms->findByCode('disc-2-10')?->getLabel() === '10 Tage 2 %, 30 Tage netto');
check('B5 disc-2-10: 30 days with one tier «10 Tage 2 %» — percent in hundredths', $disc?->getDueDays() === 30 && $disc->getDiscounts() === [['days' => 10, 'percent' => 200]]);
check('B6 … formatted back it reads 2.00', PaymentTerms::formatPercent($disc->getDiscounts()[0]['percent']) === '2.00');
check('B7 the seeds ship WITHOUT a document text — an installation in any language starts with valid rows (owner, 2026-09-22)',
    $disc->getDocumentText() === [] && $net30->getDocumentText() === []
    && !str_contains(file_get_contents($package . '/data/framework/debtor/payment_terms.default.json'), 'document_text'));
check('B8 three seeded dunning levels, ascending by level: reminder, dunning-1, dunning-2',
    array_map(fn(DunningLevel $l) => $l->getCode(), $levels->allInOrder()) === ['reminder', 'dunning-1', 'dunning-2']);
check('B9 every seeded level is active and passes its validator', array_reduce($levels->allInOrder(), fn($ok, DunningLevel $l) => $ok && $l->isActive() && (new DunningLevelValidator($l, $levels))->isValid(), true));
check('B10 the ladder is 1/2/3 with growing distance from the due date', array_map(fn(DunningLevel $l) => [$l->getLevel(), $l->getDaysAfterDue()], $levels->allInOrder()) === [[1, 10], [2, 30], [3, 50]]);
check('B11 the reminder is free, the two Mahnungen cost 20.00 and 40.00 (minor units in the file)',
    array_map(fn(DunningLevel $l) => $l->fee('CHF')->toDecimal(), $levels->allInOrder()) === ['0.00', '20.00', '40.00']);
check('B12 the dunning seeds carry no text either — the office writes its own wording',
    array_reduce($levels->allInOrder(), fn($ok, DunningLevel $l) => $ok && $l->getDocumentText() === [], true)
    && !str_contains(file_get_contents($package . '/data/framework/debtor/dunning_levels.default.json'), 'document_text'));
check('B13 payment targets are NOT seeded — an IBAN cannot be guessed', $targets->allInOrder() === [] && !is_file($base . '/data/framework/debtor/payment_targets.json'));
check('B14 no seed file carries a BOM', array_reduce(glob($package . '/data/framework/debtor/*.json'), fn($ok, $f) => $ok && !str_starts_with(file_get_contents($f), "\xEF\xBB\xBF"), true));
check('B15 there is no delete on the file master data — deactivate only (ADR-043/19)',
    !method_exists(DebtorMasterData::class, 'removeTerms') && !method_exists(DebtorMasterData::class, 'removeTarget') && !method_exists(DebtorMasterData::class, 'removeLevel')
    && !method_exists(DebtorMasterData::class, 'delete'));


// ── C. payment terms: rules of the row ───────────────────────────────────

echo "C. Payment terms (tiers, texts, code)\n";
$masterData = new DebtorMasterData($em);
$invalid = fn(callable $fn): ?PaymentTermsValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => 'NET-30', 'label' => 'Doppelt', 'due_days' => 30])));
check('C1 a duplicate code is refused (the setter lower-cases, so NET-30 is net-30)', $v?->hasFieldError('code') === true);
$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => '1st', 'label' => 'x', 'due_days' => 10])));
check('C2 a code starting with a digit is refused', $v?->hasFieldError('code') === true);
$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => 'ok-code', 'label' => '', 'due_days' => 10])));
check('C3 an empty label is refused', $v?->hasFieldError('label') === true);
$v = $invalid(fn() => $masterData->saveTerms(new PaymentTerms(['code' => 'ok-code', 'label' => 'x', 'due_days' => 400])));
check('C4 a due-day count beyond a year is refused', $v?->hasFieldError('due_days') === true);

$threeTiers = new PaymentTerms(['code' => 'three', 'label' => 'Drei Stufen', 'due_days' => 30,
    'discounts' => [['days' => 5, 'percent' => 300], ['days' => 10, 'percent' => 200], ['days' => 20, 'percent' => 100]]]);
$v = $invalid(fn() => $masterData->saveTerms($threeTiers));
check('C5 a third discount tier is refused (MAX_TIERS = 2)', $v?->hasFieldError('discounts') === true);

$late = new PaymentTerms(['code' => 'late', 'label' => 'Skonto nach Verfall', 'due_days' => 30, 'discounts' => [['days' => 40, 'percent' => 200]]]);
$v = $invalid(fn() => $masterData->saveTerms($late));
check('C6 a tier after the due date is refused — it could never be taken', $v?->hasFieldError('discounts') === true);
$instant = new PaymentTerms(['code' => 'instant', 'label' => 'Zahlbar sofort mit Skonto', 'due_days' => 0, 'discounts' => [['days' => 10, 'percent' => 200]]]);
$v = $invalid(fn() => $masterData->saveTerms($instant));
check('C6b «zahlbar sofort» (0 Tage) with a tier is refused too — every tier lies after a due date of 0 (review 2026-09-22)', $v?->hasFieldError('discounts') === true);

$rising = new PaymentTerms(['code' => 'rising', 'label' => 'Steigend', 'due_days' => 30, 'discounts' => [['days' => 10, 'percent' => 100], ['days' => 20, 'percent' => 200]]]);
$v = $invalid(fn() => $masterData->saveTerms($rising));
check('C7 a LATER tier giving MORE percent is refused — the earlier one would never be chosen', $v?->hasFieldError('discounts') === true);

$empty = new PaymentTerms(['code' => 'empty-row', 'label' => 'Leere Zeile', 'due_days' => 30, 'discounts' => [['days' => 0, 'percent' => 0], ['days' => 10, 'percent' => 200]]]);
check('C8a an EMPTY tier row (no days, no percent) is dropped by the setter — a blank form row is no tier', $empty->getDiscounts() === [['days' => 10, 'percent' => 200]]);
$zero = new PaymentTerms(['code' => 'zero-pc', 'label' => 'Null Prozent', 'due_days' => 30, 'discounts' => [['days' => 10, 'percent' => 0]]]);
$v = $invalid(fn() => $masterData->saveTerms($zero));
check('C8b a tier with days but 0 % is REFUSED, not silently dropped', $v?->hasFieldError('discounts') === true);

$twoTiers = new PaymentTerms(['code' => 'two', 'label' => '10 Tage 3 %, 20 Tage 2 %, 30 Tage netto', 'due_days' => 30,
    'discounts' => [['days' => 20, 'percent' => 200], ['days' => 10, 'percent' => 300]],
    'document_text' => ['de' => 'Skonto gestaffelt.', 'fr' => 'Escompte échelonné.']]);
$masterData->saveTerms($twoTiers);
check('C9 two tiers save and come back ASCENDING by days, whatever order they were given in',
    $twoTiers->getId() !== null && $twoTiers->getDiscounts() === [['days' => 10, 'percent' => 300], ['days' => 20, 'percent' => 200]]);
$em2 = $wireDi();
$reread = $em2->getRepository(PaymentTerms::class)->findByCode('two');
check('C10 … and survive a fresh read of the collection file, texts and all',
    $reread?->getDiscounts() === [['days' => 10, 'percent' => 300], ['days' => 20, 'percent' => 200]]
    && $reread->getDocumentText() === ['de' => 'Skonto gestaffelt.', 'fr' => 'Escompte échelonné.']);
check('C11 percentToHundredths: 2 → 200, 2.5 → 250, 0.25 → 25', PaymentTerms::percentToHundredths('2') === 200 && PaymentTerms::percentToHundredths('2.5') === 250 && PaymentTerms::percentToHundredths('0.25') === 25);
check('C12 percentToHundredths refuses a third decimal', throws(fn() => PaymentTerms::percentToHundredths('2.555'), \InvalidArgumentException::class));

$em3        = $wireDi();
$masterData = new DebtorMasterData($em3);
$invalid    = fn(callable $fn): ?PaymentTermsValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;
$frOnly = new PaymentTerms(['code' => 'fr-only', 'label' => 'Nur Französisch', 'due_days' => 30, 'document_text' => ['fr' => 'Payable à 30 jours.']]);
$v = $invalid(fn() => $masterData->saveTerms($frOnly));
check('C13 a text only in fr is refused — the default language is the fallback every other one needs', $v?->hasFieldError('document_text') === true);
$itText = new PaymentTerms(['code' => 'it-text', 'label' => 'Italienisch', 'due_days' => 30, 'document_text' => ['de' => 'Deutsch.', 'it' => 'Italiano.']]);
$v = $invalid(fn() => $masterData->saveTerms($itText));
check('C14 a text in a language the installation does not serve is refused (i18n.md whitelist)', $v?->hasFieldError('document_text') === true);
$noText = new PaymentTerms(['code' => 'no-text', 'label' => 'Ohne Belegtext', 'due_days' => 30]);
$masterData->saveTerms($noText);
check('C15 no text at all stays allowed — not every row needs a sentence on the document', $noText->getId() !== null);
check('C16 the entity drops an empty text instead of storing it', (new PaymentTerms(['document_text' => ['de' => '  ', 'fr' => 'x']]))->getDocumentText() === ['fr' => 'x']);

$stored = $em3->getRepository(PaymentTerms::class)->findByCode('two');
$stored->setCode('renamed');
$e = caught(fn() => $masterData->saveTerms($stored), MasterDataCodeChangedException::class);
check('C17 the code of an existing row is immutable — the write service refuses it', $e instanceof MasterDataCodeChangedException && $e->storedCode === 'two' && $e->submittedCode === 'renamed');


// ── D. IBAN, QR-IBAN and the payment target ──────────────────────────────

echo "D. IBAN / QR-IBAN and the payment target\n";
check('D1 a real CH IBAN passes the MOD-97-10 check digits', Iban::hasValidCheckDigits('CH9300762011623852957'));
check('D2 … spaced and lower-cased just the same (normalization)', Iban::hasValidCheckDigits('ch93 0076 2011 6238 5295 7'));
check('D3 a transposed pair of digits fails the check digits', !Iban::hasValidCheckDigits('CH9300762011623852975'));
check('D4 a wrong check-digit pair fails', !Iban::hasValidCheckDigits('CH9400762011623852957'));
check('D5 a German IBAN passes the check digits as well (the algorithm is not Swiss)', Iban::hasValidCheckDigits('DE89370400440532013000'));
check('D6 the official Liechtenstein example passes, and one wrong character does not',
    Iban::hasValidCheckDigits('LI21088100002324013AA') && Iban::isWellFormed('LI21088100002324013AA')
    && !Iban::hasValidCheckDigits('LI21088100002324013AB'));
check('D7 shape: a CH IBAN is exactly 21 characters', !Iban::isWellFormed('CH930076201162385295') && Iban::isWellFormed('CH9300762011623852957'));
check('D8 shape: letters where digits belong is refused', !Iban::isWellFormed('CHXX00762011623852957'));
check('D9 format() groups in fours', Iban::format('CH9300762011623852957') === 'CH93 0076 2011 6238 5295 7');

// The IID sits at positions 5–9. Built at the range edges, then given valid check digits.
$withIid = static function (string $iid): string {
    $bban  = $iid . '000000000000';                       // IID (5) + 12 → the 17-character Swiss BBAN
    $digits = '';
    foreach (str_split($bban . 'CH00') as $c) { $digits .= ctype_digit($c) ? $c : (string) (ord($c) - 55); }
    $remainder = 0;
    foreach (str_split($digits, 7) as $chunk) { $remainder = (int) (((string) $remainder . $chunk) % 97); }

    return 'CH' . str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT) . $bban;
};
$below = $withIid('29999');
$lower = $withIid('30000');
$upper = $withIid('31999');
$above = $withIid('32000');
check('D10 the built probes are valid IBANs', array_reduce([$below, $lower, $upper, $above], fn($ok, $i) => $ok && Iban::hasValidCheckDigits($i), true));
check('D11 IID 30000 and 31999 are QR-IBANs, 29999 and 32000 are not',
    Iban::isQrIban($lower) && Iban::isQrIban($upper) && !Iban::isQrIban($below) && !Iban::isQrIban($above));
check('D12 the IID is read from positions 5–9', Iban::iid($lower) === 30000 && Iban::iid($upper) === 31999);
check('D13 a non-Swiss IBAN has no IID and is never a QR-IBAN', Iban::iid('DE89370400440532013000') === null && !Iban::isQrIban('DE89370400440532013000'));
check('D14 the seeded CH example is a normal IBAN, not a QR one', !Iban::isQrIban('CH9300762011623852957'));

$em4        = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

// The chart a payment target's account is checked against (financial is installed here).
$accounts = new AccountService($em4);
$accounts->adoptKmuChart();
$em4 = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$bank = new PaymentTarget(['code' => 'bank', 'label' => 'Bank', 'iban' => 'CH93 0076 2011 6238 5295 7', 'account_number' => '1020']);
$masterData->saveTarget($bank);
check('D15 a target saves; the IBAN is stored normalized', $bank->getId() !== null && $bank->getIban() === 'CH9300762011623852957');
check('D16 … and the collection file now exists', is_file($base . '/data/framework/debtor/payment_targets.json'));
$qr = new PaymentTarget(['code' => 'qr', 'label' => 'QR-Konto', 'iban' => $lower, 'account_number' => '1020']);
$masterData->saveTarget($qr);
check('D17 a QR-IBAN target says so on the entity', $qr->isQrIban() && !$bank->isQrIban());

$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'bad', 'label' => 'x', 'iban' => 'CH9300762011623852975', 'account_number' => '1020'])));
check('D18 a wrong check digit is refused with the check-digit message', $v?->hasFieldError('iban') === true && str_contains($v->getFieldError('iban'), 'Prüfziffern'));
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'de', 'label' => 'x', 'iban' => 'DE89370400440532013000', 'account_number' => '1020'])));
check('D19 a valid NON-Swiss IBAN is refused — a QR-bill names a CH / LI account', $v?->hasFieldError('iban') === true && str_contains($v->getFieldError('iban'), 'liechtensteinische'));
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'dup', 'label' => 'x', 'iban' => 'CH9300762011623852957', 'account_number' => '1020'])));
check('D20 the same IBAN twice is refused — a payment would be ambiguous', $v?->hasFieldError('iban') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'letters', 'label' => 'x', 'iban' => 'CH9300762011623852957', 'account_number' => '10A0'])));
check('D21 a non-numeric ledger account is refused', $v?->hasFieldError('account_number') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'group', 'label' => 'x', 'iban' => $upper, 'account_number' => '100'])));
check('D22 a GROUP account (100) is refused — soft check against module-financial', $v?->hasFieldError('account_number') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'ghost', 'label' => 'x', 'iban' => $upper, 'account_number' => '9999999'])));
check('D23 an unknown account is refused', $v?->hasFieldError('account_number') === true);

$kasse = $em4->getRepository(Account::class)->findOneBy(['number' => '1000']);
(new AccountService($em4))->setActive($kasse, false);
$em4 = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'inactive', 'label' => 'x', 'iban' => $upper, 'account_number' => '1000'])));
check('D24 a DEACTIVATED account is refused as a new payment target', $v?->hasFieldError('account_number') === true);
(new AccountService($em4))->setActive($em4->getRepository(Account::class)->findOneBy(['number' => '1000']), true);

$em5     = $wireDi();
$present = new LedgerAccountCheck($em5);
check('D25 with financial REGISTERED the check answers true / false', $present->available() && $present->isPostable('1020') === true && $present->isPostable('100') === false);
check('D26 an empty number is «cannot tell» as well (the validator\'s notEmpty says it first)', $present->isPostable('  ') === null);
// Not a constructor seam: the class autoloads either way — what decides is
// whether `financial` is a REGISTERED module (review 2026-09-22).
$writeModules(false);
$rm($base . '/var/cache');
$emUnregistered = $wireDi();
check('D27 the class still autoloads with financial UNREGISTERED — so class_exists alone would have lied',
    class_exists(LedgerAccountCheck::LEDGER_SERVICE) && DI::getModuleManager()->getModuleConfig('financial') === null);
$absent = new LedgerAccountCheck($emUnregistered);
check('D27b … and the check then cannot tell — null, never false, never a fatal',
    !$absent->available() && $absent->isPostable('1020') === null && $absent->isPostable('9999999') === null);
$unregisteredTarget = new PaymentTarget(['code' => 'unreg', 'label' => 'Ohne Buchhaltung', 'iban' => $upper, 'account_number' => '9999999']);
(new DebtorMasterData($emUnregistered))->saveTarget($unregisteredTarget);
check('D27c … and an unverifiable account number SAVES instead of being refused (financial is only suggested)', $unregisteredTarget->getId() !== null);
$writeModules(true);
$rm($base . '/var/cache');
$em5 = $wireDi();

$stored = $em5->getRepository(PaymentTarget::class)->findByCode('bank');
$stored->setCode('renamed');
check('D28 a payment target\'s code is immutable too', throws(fn() => (new DebtorMasterData($em5))->saveTarget($stored), MasterDataCodeChangedException::class));

echo "D. … deactivating a row that has BECOME invalid (review 2026-09-22)\n";
$em5b = $wireDi();
(new AccountService($em5b))->setActive($em5b->getRepository(Account::class)->findOneBy(['number' => '1020']), false);
$em5b = $wireDi();
$brokenTarget = $em5b->getRepository(PaymentTarget::class)->findByCode('bank');
check('D29 the target is now invalid — its ledger account went inactive in the bookkeeping',
    (new PaymentTargetValidator($brokenTarget, $em5b->getRepository(PaymentTarget::class), new LedgerAccountCheck($em5b)))->isValid() === false);
(new DebtorMasterData($em5b))->setTargetActive($brokenTarget, false);
$em5c = $wireDi();
check('D30 … and it can still be DEACTIVATED: setActive is a pure state change, the validator runs on a form save only',
    $em5c->getRepository(PaymentTarget::class)->findByCode('bank')?->isActive() === false);
check('D31 … while SAVING it through the form is still refused',
    throws(fn() => (new DebtorMasterData($em5c))->saveTarget($em5c->getRepository(PaymentTarget::class)->findByCode('bank')), InvalidMasterDataException::class));
(new AccountService($em5c))->setActive($em5c->getRepository(Account::class)->findOneBy(['number' => '1020']), true);
$em5d = $wireDi();
(new DebtorMasterData($em5d))->setTargetActive($em5d->getRepository(PaymentTarget::class)->findByCode('bank'), true);
check('D32 … and reactivating works again once the account is back', $em5d->getRepository(PaymentTarget::class)->findByCode('bank')?->isActive() === true);


// ── E. dunning levels ────────────────────────────────────────────────────

echo "E. Dunning levels (ladder, fee as Money, no VAT)\n";
$em6        = $wireDi();
$masterData = new DebtorMasterData($em6);
$badLevel   = fn(callable $fn): ?DunningLevelValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'dupe', 'label' => 'Doppelte Stufe', 'level' => 2, 'days_after_due' => 40])));
check('E1 a second row on level 2 is refused — the ladder must have one order', $v?->hasFieldError('level') === true);
$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'zero', 'label' => 'x', 'level' => 0, 'days_after_due' => 5])));
check('E2 level 0 is refused (the ladder starts at 1)', $v?->hasFieldError('level') === true);
$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'neg-fee', 'label' => 'x', 'level' => 4, 'days_after_due' => 60, 'fee' => -100])));
check('E3 a negative fee is refused', $v?->hasFieldError('fee') === true);
$v = $badLevel(fn() => $masterData->saveLevel(new DunningLevel(['code' => 'huge-fee', 'label' => 'x', 'level' => 4, 'days_after_due' => 60, 'fee' => 500000])));
check('E4 a fee beyond 1000.00 is refused — a dunning fee is a fee, not an invoice', $v?->hasFieldError('fee') === true);

$fourth = new DunningLevel(['code' => 'betreibung', 'label' => 'Betreibungsandrohung', 'level' => 4, 'days_after_due' => 70,
    'fee' => Money::fromDecimal('60.00', 'CHF')->minor, 'document_text' => ['de' => 'Letzte Frist.', 'fr' => 'Dernier délai.']]);
$masterData->saveLevel($fourth);
$em7   = $wireDi();
$stored = $em7->getRepository(DunningLevel::class)->findByCode('betreibung');
check('E5 a fourth level saves and reads back with its fee as Money', $stored?->fee('CHF')->toDecimal() === '60.00' && $stored->getFee() === 6000);
check('E6 the ladder now reads 1,2,3,4 in level order', array_map(fn(DunningLevel $l) => $l->getLevel(), $em7->getRepository(DunningLevel::class)->allInOrder()) === [1, 2, 3, 4]);
check('E7 the fee is Money in the installation\'s base currency (systemConfig → baseCurrency)', DebtorCurrency::base() === 'CHF' && $stored->fee(DebtorCurrency::base())->equals(Money::of(6000, 'CHF')));
$levelProperties = array_map(fn(\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(DunningLevel::class))->getProperties());
check('E8 a dunning level carries NO tax code and no VAT field — a dunning fee is not a supply (plan §6.5)',
    !in_array('taxCode', $levelProperties, true) && !in_array('vat', $levelProperties, true) && !in_array('taxRate', $levelProperties, true));
check('E9 the fee is stored as INTEGER minor units in the JSON row, never as a decimal string',
    is_int(json_decode(file_get_contents($base . '/data/framework/debtor/dunning_levels.json'), true)[1]['fee']));
$stored->setCode('renamed');
check('E10 a dunning level\'s code is immutable too', throws(fn() => (new DebtorMasterData($em7))->saveLevel($stored), MasterDataCodeChangedException::class));


// ── F. the debtor profile ────────────────────────────────────────────────

echo "F. Debtor profile (one per contact, CRUD, deactivate)\n";
$em8     = $wireDi();
$contacts = new ContactService($em8);
$mueller = new Contact(['kind' => 'organisation', 'company' => 'Müller & Söhne AG', 'language' => 'de', 'email' => 'info@mueller.ch']);
$mueller->addAddress(new ContactAddress($mueller, 'invoice', new Address(['name' => 'Müller & Söhne AG', 'street' => 'Bahnhofstrasse', 'house_no' => '1', 'zip' => '8001', 'city' => 'Zürich'])));
$contacts->save($mueller);
$anna = new Contact(['kind' => 'person', 'first_name' => 'Anna', 'last_name' => 'Ébauche', 'language' => 'fr']);
$contacts->save($anna);
check('F1 two contacts exist and no profile does', $count('contact') === 2 && $count('debtor_profile') === 0);

$profiles = new DebtorProfileService($em8);
$profile  = new DebtorProfile(['payment_terms_code' => 'disc-2-10']);
$profile->setContact($mueller);
$profiles->save($profile);
check('F2 a profile saves for a contact', $profile->getId() !== null && $count('debtor_profile') === 1);
$em9  = $wireDi();
$read = $em9->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
check('F3 it reads back through a fresh EntityManager, keyed by contact id', $read?->getContact()?->getId() === $mueller->getId() && $read->getPaymentTermsCode() === 'disc-2-10' && !$read->hasDunningBlock() && $read->isActive());
check('F4 the profile knows its contact, the contact knows no profile (plan §2)',
    $read->getContact()?->displayName() === 'Müller & Söhne AG' && !method_exists(Contact::class, 'getDebtorProfile') && !method_exists(Contact::class, 'setDebtorProfile'));
$contactProperties = array_map(fn(\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(Contact::class))->getProperties());
check('F5 … and `contact` carries no debtor column', !in_array('paymentTermsCode', $contactProperties, true) && !in_array('dunningBlock', $contactProperties, true));

$profileProperties = array_map(fn(\ReflectionProperty $p) => $p->getName(), (new \ReflectionClass(DebtorProfile::class))->getProperties());
check('F6 the profile carries NO language — Contact::$language is the one truth (Rule 2)', !in_array('language', $profileProperties, true) && in_array('language', $contactProperties, true));
check('F7 the profile carries NO currency — Q6, and §6.2 puts it on the document', !in_array('currency', $profileProperties, true));

$second = new DebtorProfile(['payment_terms_code' => 'net-30']);
$second->setContact($em9->getRepository(Contact::class)->find($mueller->getId()));
$e = caught(fn() => (new DebtorProfileService($em9))->save($second), InvalidDebtorProfileException::class);
check('F8 a SECOND profile for the same contact is refused by the validator', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('contact_id'));
check('F9 … and nothing was written', $count('debtor_profile') === 1);

$em10 = $wireDi();
$raced = $db->executeStatement('INSERT INTO debtor_profile (payment_terms_code, dunning_block, active, contact_id) VALUES (?, 0, 1, ?)', ['net-30', $anna->getId()]);
check('F10 a profile for another contact is fine (the unique index is per contact, not global)', $raced === 1 && $count('debtor_profile') === 2);
$e = caught(fn() => $db->executeStatement('INSERT INTO debtor_profile (payment_terms_code, dunning_block, active, contact_id) VALUES (?, 0, 1, ?)', ['net-30', $anna->getId()]),
    \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
check('F11 the unique index decides under a race — a second row for one contact is impossible in the schema', $e !== null && $count('debtor_profile') === 2);

$em11    = $wireDi();
$profiles = new DebtorProfileService($em11);
$managed  = $em11->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$profiles->update($managed, ['payment_terms_code' => 'net-30', 'dunning_block' => true]);
$em12 = $wireDi();
$read = $em12->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
check('F12 an update writes terms and the dunning block', $read?->getPaymentTermsCode() === 'net-30' && $read->hasDunningBlock());

$em13    = $wireDi();
$profiles = new DebtorProfileService($em13);
$managed  = $em13->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$e = caught(fn() => $profiles->update($managed, ['payment_terms_code' => 'ghost-terms']), InvalidDebtorProfileException::class);
check('F13 an unknown payment-terms code is refused', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code'));
check('F14 … and the MANAGED entity was not touched — a refused change never reaches the next flush (ADR-039/9)', $managed->getPaymentTermsCode() === 'net-30');
$em13->flush();
$em14 = $wireDi();
check('F15 … proven after a flush and a fresh read', $em14->getRepository(DebtorProfile::class)->findByContact($mueller->getId())?->getPaymentTermsCode() === 'net-30');

$em15    = $wireDi();
$profiles = new DebtorProfileService($em15);
$managed  = $em15->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$profiles->setActive($managed, false);
$em16 = $wireDi();
check('F16 a profile is DEACTIVATED, not deleted', $em16->getRepository(DebtorProfile::class)->findByContact($mueller->getId())?->isActive() === false && $count('debtor_profile') === 2);
check('F17 there is no delete method on the write side or the repository',
    !method_exists(DebtorProfileService::class, 'delete') && !method_exists(DebtorProfileService::class, 'remove')
    && !method_exists(DebtorProfileRepository::class, 'delete'));
check('F18 save() refuses an existing profile, update() a new one',
    throws(fn() => (new DebtorProfileService($em16))->save($em16->getRepository(DebtorProfile::class)->findByContact($mueller->getId())), \LogicException::class)
    && throws(fn() => (new DebtorProfileService($em16))->update(new DebtorProfile(), ['active' => true]), \LogicException::class));
$e = caught(fn() => (new DebtorProfileService($em16))->save(new DebtorProfile(['payment_terms_code' => 'net-30'])), InvalidDebtorProfileException::class);
check('F19 a profile without a contact is refused', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('contact_id'));
$noTerms = new DebtorProfile();
$noTerms->setContact($em16->getRepository(Contact::class)->find($mueller->getId()));
$e = caught(fn() => (new DebtorProfileService($em16))->save($noTerms), InvalidDebtorProfileException::class);
check('F20 a profile without payment terms is refused — there is no global default to fall back on', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code'));

echo "F. … the reference rule applied to the PARTY (review 2026-09-22)\n";
$em16b   = $wireDi();
$retired = new Contact(['kind' => 'person', 'first_name' => 'Ruhe', 'last_name' => 'Stand', 'language' => 'de']);
(new ContactService($em16b))->save($retired);
(new ContactService($em16b))->setActive($retired, false);
$em16c   = $wireDi();
$onDead  = new DebtorProfile(['payment_terms_code' => 'disc-2-10']);
$onDead->setContact($em16c->getRepository(Contact::class)->find($retired->getId()));
$e = caught(fn() => (new DebtorProfileService($em16c))->save($onDead), InvalidDebtorProfileException::class);
check('F21 a NEW profile on an INACTIVE contact is refused — a new reference needs an active row',
    $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('contact_id') && $onDead->getId() === null);

// Müller keeps its profile; deactivating the contact must not lock it.
$em16d = $wireDi();
(new ContactService($em16d))->setActive($em16d->getRepository(Contact::class)->find($mueller->getId()), false);
$em16e   = $wireDi();
$keeper  = $em16e->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
(new DebtorProfileService($em16e))->update($keeper, ['dunning_block' => true]);
$em16f = $wireDi();
check('F22 an EXISTING profile whose contact was deactivated SINCE stays editable (history resolves)',
    $em16f->getRepository(DebtorProfile::class)->findByContact($mueller->getId())?->hasDunningBlock() === true);
(new ContactService($em16f))->setActive($em16f->getRepository(Contact::class)->find($mueller->getId()), true);
$em16g = $wireDi();
(new DebtorProfileService($em16g))->update($em16g->getRepository(DebtorProfile::class)->findByContact($mueller->getId()), ['dunning_block' => false]);


// ── G. the reference rule (ADR-043 decision 19) ──────────────────────────

echo "G. Reference rule for the file master data\n";
$em17       = $wireDi();
$masterData = new DebtorMasterData($em17);
$masterData->setTermsActive($em17->getRepository(PaymentTerms::class)->findByCode('net-30'), false);
$em18 = $wireDi();
check('G1 deactivating payment terms keeps the row — no delete anywhere', $em18->getRepository(PaymentTerms::class)->findByCode('net-30')?->isActive() === false && count($em18->getRepository(PaymentTerms::class)->allInOrder()) === 4);

$profiles = new DebtorProfileService($em18);
$managed  = $em18->getRepository(DebtorProfile::class)->findByContact($mueller->getId());
$profiles->update($managed, ['dunning_block' => false]);
check('G2 an EXISTING profile keeps its now-deactivated terms — history resolves', $managed->getPaymentTermsCode() === 'net-30' && !$managed->hasDunningBlock());

$em19    = $wireDi();
$profiles = new DebtorProfileService($em19);
$managed  = $em19->getRepository(DebtorProfile::class)->findByContact($anna->getId());
$profiles->update($managed, ['payment_terms_code' => 'disc-2-10']);   // an ACTIVE row: allowed
$e = caught(fn() => $profiles->update($managed, ['payment_terms_code' => 'net-30']), InvalidDebtorProfileException::class);
check('G3 … but SWITCHING to a deactivated row is refused — a new reference needs an active one',
    $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code') && $managed->getPaymentTermsCode() === 'disc-2-10');

$em20 = $wireDi();
$fresh = new DebtorProfile(['payment_terms_code' => 'net-30']);
$thirdContact = new Contact(['kind' => 'person', 'first_name' => 'Rita', 'last_name' => 'Neu', 'language' => 'de']);
(new ContactService($em20))->save($thirdContact);
$fresh->setContact($thirdContact);
$e = caught(fn() => (new DebtorProfileService($em20))->save($fresh), InvalidDebtorProfileException::class);
check('G4 a NEW profile cannot start on a deactivated row either', $e instanceof InvalidDebtorProfileException && $e->validator->hasFieldError('payment_terms_code'));
$fresh->setPaymentTermsCode('disc-2-10');
(new DebtorProfileService($em20))->save($fresh);
check('G5 … with an active row it saves', $fresh->getId() !== null);

$em21       = $wireDi();
$masterData = new DebtorMasterData($em21);
$masterData->setTermsActive($em21->getRepository(PaymentTerms::class)->findByCode('net-30'), true);
check('G6 reactivating is the counterpart of deactivating', $em21->getRepository(PaymentTerms::class)->findByCode('net-30')?->isActive() === true);
check('G7 countByPaymentTermsCode counts what references a row', $em21->getRepository(DebtorProfile::class)->countByPaymentTermsCode('disc-2-10') === 2 && $em21->getRepository(DebtorProfile::class)->countByPaymentTermsCode('ghost') === 0);

$masterData->setTargetActive($em21->getRepository(PaymentTarget::class)->findByCode('bank'), false);
$masterData->setLevelActive($em21->getRepository(DunningLevel::class)->findByCode('dunning-2'), false);
$em22 = $wireDi();
check('G9 a payment target and a dunning level deactivate the same way, rows intact',
    $em22->getRepository(PaymentTarget::class)->findByCode('bank')?->isActive() === false
    && $em22->getRepository(DunningLevel::class)->findByCode('dunning-2')?->isActive() === false
    && count($em22->getRepository(PaymentTarget::class)->allInOrder()) === 3
    && count($em22->getRepository(DunningLevel::class)->allInOrder()) === 4);
check('G10 every file entity normalizes its code the same way (lower, trimmed)',
    PaymentTerms::normalizeCode('  NET-30 ') === 'net-30' && PaymentTarget::normalizeCode(' BANK ') === 'bank' && DunningLevel::normalizeCode(' Reminder ') === 'reminder');
check('G11 a deactivated row still RESOLVES by code — that is what «never delete» buys',
    $em22->getRepository(PaymentTarget::class)->findByCode('bank') !== null && $em22->getRepository(DunningLevel::class)->findByCode('dunning-2') !== null);

echo "G. … a row that has become invalid can still be switched off (review 2026-09-22)
";
// The installation drops `fr`: every row whose document text stands there is
// now invalid — and that is exactly when the office wants to switch it off.
$em22b      = $wireDi();
$masterData = new DebtorMasterData($em22b);
$masterData->saveTerms(new PaymentTerms(['code' => 'fr-terms', 'label' => 'Mit Französisch', 'due_days' => 20,
    'document_text' => ['de' => 'Deutsch.', 'fr' => 'Français.']]));
$masterData->saveLevel(new DunningLevel(['code' => 'fr-level', 'label' => 'Mit Französisch', 'level' => 5, 'days_after_due' => 90,
    'document_text' => ['de' => 'Deutsch.', 'fr' => 'Français.']]));
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$rm($base . '/var/cache');
$em23a      = $wireDi();
$masterData = new DebtorMasterData($em23a);
$frTerms    = $em23a->getRepository(PaymentTerms::class)->findByCode('fr-terms');
$frLevel    = $em23a->getRepository(DunningLevel::class)->findByCode('fr-level');
check('G12 both rows are invalid now — their text stands in a language the installation no longer serves',
    (new PaymentTermsValidator($frTerms, $em23a->getRepository(PaymentTerms::class)))->isValid() === false
    && (new DunningLevelValidator($frLevel, $em23a->getRepository(DunningLevel::class)))->isValid() === false);
$masterData->setTermsActive($frTerms, false);
$masterData->setLevelActive($frLevel, false);
$em23b = $wireDi();
check('G13 … and both can still be DEACTIVATED — setActive is a pure state change (the VatMasterData model)',
    $em23b->getRepository(PaymentTerms::class)->findByCode('fr-terms')?->isActive() === false
    && $em23b->getRepository(DunningLevel::class)->findByCode('fr-level')?->isActive() === false);
check('G14 … while a form SAVE of either is still refused — new input keeps the full rules',
    throws(fn() => (new DebtorMasterData($em23b))->saveTerms($em23b->getRepository(PaymentTerms::class)->findByCode('fr-terms')), InvalidMasterDataException::class)
    && throws(fn() => (new DebtorMasterData($em23b))->saveLevel($em23b->getRepository(DunningLevel::class)->findByCode('fr-level')), InvalidMasterDataException::class));
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de', 'fr']];");
$rm($base . '/var/cache');
$em23c      = $wireDi();
$masterData = new DebtorMasterData($em23c);
$masterData->setTermsActive($em23c->getRepository(PaymentTerms::class)->findByCode('fr-terms'), true);
$masterData->setLevelActive($em23c->getRepository(DunningLevel::class)->findByCode('fr-level'), true);
$em23d = $wireDi();
check('G15 … and with the language back both reactivate and validate again',
    $em23d->getRepository(PaymentTerms::class)->findByCode('fr-terms')?->isActive() === true
    && (new PaymentTermsValidator($em23d->getRepository(PaymentTerms::class)->findByCode('fr-terms'), $em23d->getRepository(PaymentTerms::class)))->isValid()
    && $em23d->getRepository(DunningLevel::class)->findByCode('fr-level')?->isActive() === true);
$renamed = $em23d->getRepository(PaymentTerms::class)->findByCode('fr-terms');
$renamed->setCode('renamed-row');
check('G16 setActive still refuses a row whose stored code differs — the ONE rule it keeps',
    throws(fn() => (new DebtorMasterData($em23d))->setTermsActive($renamed, false), MasterDataCodeChangedException::class));


// ── H. the account settings ──────────────────────────────────────────────

echo "H. debtorAccounts (the config accessor and its refusals)\n";
$em23 = $wireDi();
$configured = array_combine(DebtorAccounts::KEYS, array_map(DebtorAccounts::number(...), DebtorAccounts::KEYS));
check('H1 the KMU defaults are what the package config names — rounding has its OWN account (owner, 2026-09-22)', $configured === [
    'receivable' => '1100', 'discount' => '3800', 'loss' => '3805', 'rounding' => '3809', 'dunningFee' => '6950',
]);
$chart = $em23->getRepository(Account::class);
check('H2 … and every one of them exists in the shipped KMU chart',
    array_reduce($configured, fn($ok, $n) => $ok && $chart->findOneBy(['number' => $n]) !== null, true));
check('H2b discount and rounding are DIFFERENT accounts — the reports must keep them apart',
    $configured['discount'] !== $configured['rounding']
    && $chart->findOneBy(['number' => '3809'])?->getName() === 'Rundungsdifferenzen'
    && $chart->findOneBy(['number' => '3809'])?->getParent()?->getNumber() === '38');
check('H3 1100 is «Forderungen aus Lieferungen und Leistungen (Debitoren)», 3805 the loss account, 6950 Finanzertrag',
    $chart->findOneBy(['number' => '1100'])?->getName() === 'Forderungen aus Lieferungen und Leistungen (Debitoren)'
    && str_starts_with($chart->findOneBy(['number' => '3805'])?->getName() ?? '', 'Verluste aus Forderungen')
    && $chart->findOneBy(['number' => '6950'])?->getName() === 'Finanzertrag');
$settings = new DebtorAccounts($em23);
check('H4 postableNumber() answers for every key while the chart is sound', array_reduce(DebtorAccounts::KEYS, fn($ok, $k) => $ok && $settings->postableNumber($k) !== '', true));
check('H5 status() reports no error for any key', array_reduce($settings->status(), fn($ok, $row) => $ok && $row['error'] === null, true));
check('H6 an unknown key is a programming error, not a configuration one', throws(fn() => DebtorAccounts::number('nope'), \InvalidArgumentException::class));

// A project override that dropped a key (BOOT-CONFIG-001) and a malformed value.
// FileFinder memoizes the resolved path, so the cache goes with the file.
$writeOverride = function (?string $php) use ($write, $wireDi, $base, $rm): UnifiedEntityManager {
    $file = $base . '/override/module/debtor/src/App/Config/debtorConfig.inc.php';
    if ($php === null) { @unlink($file); } else { $write('override/module/debtor/src/App/Config/debtorConfig.inc.php', $php); }
    $rm($base . '/var/cache');

    return $wireDi();
};
$emOverride = $writeOverride("<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class], 'debtorAccounts' => ['receivable' => '1100']];");
$overrideActive = throws(fn() => DebtorAccounts::number('loss'), AccountNotConfiguredException::class);
if ($overrideActive) {
    $e = caught(fn() => DebtorAccounts::number('loss'), AccountNotConfiguredException::class);
    check('H7 a key a project override dropped is refused AT THE POINT OF USE, naming the key (BOOT-CONFIG-001)',
        $e instanceof AccountNotConfiguredException && $e->key === 'loss' && str_contains($e->getMessage(), "debtorAccounts['loss']") && str_contains($e->getMessage(), 'Debitorenverlust'));
    check('H8 … status() reports it while the key that is there still answers', (new DebtorAccounts($emOverride))->status()['loss']['error'] !== null
        && DebtorAccounts::number('receivable') === '1100');

    $emOverride = $writeOverride("<?php return ['viewArea' => false, 'debtorAccounts' => ['receivable' => 1100]];");
    check('H9 a non-string account number fails loudly — a typo in a config file is reported, not skipped', throws(fn() => DebtorAccounts::number('receivable'), \UnexpectedValueException::class));

    $emOverride = $writeOverride("<?php return ['viewArea' => false, 'debtorAccounts' => 'nope'];");
    check('H10 a `debtorAccounts` that is not a map fails loudly as well', throws(fn() => DebtorAccounts::number('receivable'), \UnexpectedValueException::class));

    $emOverride = $writeOverride("<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class], 'debtorAccounts' => ['receivable' => '100', 'discount' => '9999999', 'loss' => '1000', 'rounding' => '3800', 'dunningFee' => '6950']];");
    $emOverride = $wireDi();
    (new AccountService($emOverride))->setActive($emOverride->getRepository(Account::class)->findOneBy(['number' => '1000']), false);
    $emOverride = $wireDi();
    $settings   = new DebtorAccounts($emOverride);
    $e = caught(fn() => $settings->postableNumber('receivable'), AccountNotConfiguredException::class);
    check('H11 a GROUP account is refused at the point of use, in German, naming the key',
        $e instanceof AccountNotConfiguredException && str_contains($e->getMessage(), 'Gruppe') && str_contains($e->getMessage(), "debtorAccounts['receivable']"));
    check('H12 an unknown and an inactive account are refused the same way',
        throws(fn() => $settings->postableNumber('discount'), AccountNotConfiguredException::class)
        && throws(fn() => $settings->postableNumber('loss'), AccountNotConfiguredException::class));
    check('H13 the two sound keys still answer', $settings->postableNumber('rounding') === '3800' && $settings->postableNumber('dunningFee') === '6950');
    $status = $settings->status();
    check('H14 status() marks exactly the three broken keys and keeps their configured numbers visible',
        $status['receivable']['error'] !== null && $status['discount']['error'] !== null && $status['loss']['error'] !== null
        && $status['rounding']['error'] === null && $status['dunningFee']['error'] === null
        && $status['receivable']['number'] === '100');
    (new AccountService($emOverride))->setActive($emOverride->getRepository(Account::class)->findOneBy(['number' => '1000']), true);
} else {
    check('H7 a project override replaces the package config (BOOT-CONFIG-001) — skipped, the override did not take effect', false);
}
$em24 = $writeOverride(null);
check('H15 without the override the package defaults are back', DebtorAccounts::number('loss') === '3805' && DebtorAccounts::number('rounding') === '3809');
$writeModules(false);
$rm($base . '/var/cache');
$emNoLedger = $wireDi();
check('H16 with financial UNREGISTERED nothing is refused — the check cannot tell (financial is only suggested)',
    (new DebtorAccounts($emNoLedger))->postableNumber('receivable') === '1100'
    && array_reduce((new DebtorAccounts($emNoLedger))->status(), fn($ok, $row) => $ok && $row['error'] === null, true));
$writeModules(true);
$rm($base . '/var/cache');
$em24 = $wireDi();


// ── I. the backend fragments ─────────────────────────────────────────────

echo "I. Backend fragments (ADR-018) — wiring, shape and source guards by reflection\n";
$em25 = $wireDi();
foreach ([
    ['Zahlungskonditionen', PaymentTermsControllerTrait::class, PaymentTermsLayout::class, 'Backend/PaymentTermsController', '/backend/finance/payment-terms'],
    ['Zahlungsziele',       PaymentTargetControllerTrait::class, PaymentTargetLayout::class, 'Backend/PaymentTargetController', '/backend/finance/payment-target'],
    ['Mahnstufen',          DunningLevelControllerTrait::class, DunningLevelLayout::class, 'Backend/DunningLevelController', '/backend/finance/dunning-level'],
    ['Debitoren',           DebtorControllerTrait::class, DebtorLayout::class, 'Backend/DebtorController', '/backend/finance/debtor'],
] as $i => [$title, $trait, $layout, $path, $url]) {
    $n      = $i + 1;
    $config = $layout::config();
    check("I{$n}a {$title}: the layout pins the body to the fragment's listAction in module-debtor",
        ($config['levelElements']['body']['main'][0]['nameSpace'] ?? '') === 'Z77\\Module\\Debtor'
        && ($config['levelElements']['body']['main'][0]['path'] ?? '') === $path
        && ($config['levelElements']['body']['main'][0]['name'] ?? '') === 'listAction');
    $methods = get_class_methods(new class ($trait) { public function __construct(public string $t) {} });
    $ref = new \ReflectionClass($trait);
    check("I{$n}b {$title}: the fragment offers list and no delete action",
        $ref->hasMethod('listAction') && !$ref->hasMethod('deleteAction') && !$ref->hasMethod('removeAction') && !$ref->hasMethod('confirmDeleteAction'));
    $source = file_get_contents($ref->getFileName());
    check("I{$n}c {$title}: the mount URL is built in ONE place", substr_count($source, "'" . $url . "'") === 1);
}
foreach ([
    ['Zahlungskonditionen', PaymentTermsControllerTrait::class],
    ['Zahlungsziele',       PaymentTargetControllerTrait::class],
    ['Mahnstufen',          DunningLevelControllerTrait::class],
] as $i => [$title, $trait]) {
    // The service-level behaviour is section D29–D32 / G12–G16; here: the
    // switch must ANSWER, never let the refusal become a 500.
    $source = file_get_contents((new \ReflectionClass($trait))->getFileName());
    $toggle = substr($source, strpos($source, 'function toggleActiveAction'));
    check('I' . ($i + 1) . 'd ' . $title . ': the active switch catches DebtorException and answers with a fetchError, never a 500',
        str_contains($toggle, 'catch (DebtorException') && str_contains($toggle, 'fetchError'));
}
check('I5 the three master-data fragments carry an add action and an active switch, the debtor one too',
    array_reduce([PaymentTermsControllerTrait::class, PaymentTargetControllerTrait::class, DunningLevelControllerTrait::class, DebtorControllerTrait::class],
        fn($ok, $t) => $ok && (new \ReflectionClass($t))->hasMethod('addAction') && (new \ReflectionClass($t))->hasMethod('toggleActiveAction'), true));

$templateDir = $package . '/res/view/templates/Backend';
$templates   = glob($templateDir . '/*/*.tpl.php');
check('I6 every screen has its list template, its edit template and its header slot', count($templates) === 12);
check('I7 no template carries a <script> tag or an inline handler (Rule 7)',
    array_reduce($templates, fn($ok, $f) => $ok && !preg_match('/<script|\son[a-z]+\s*=/i', file_get_contents($f)), true));
check('I8 the package ships no JavaScript at all', glob($package . '/res/**/*.js') === [] && glob($package . '/res/*.js') === []);
check('I9 the header slots are partials OF THE FRAGMENT (financial.md, «fragment slots»)',
    is_file($templateDir . '/PaymentTermsController/addButton.tpl.php')
    && is_file($templateDir . '/PaymentTargetController/addButton.tpl.php')
    && is_file($templateDir . '/DunningLevelController/addButton.tpl.php')
    && is_file($templateDir . '/DebtorController/search.tpl.php'));
$backend = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-backend'));
check('I10 module-backend mounts all four under the finance group',
    is_file($backend . '/src/Ui/Controllers/Finance/PaymentTermsController.php')
    && is_file($backend . '/src/Ui/Controllers/Finance/PaymentTargetController.php')
    && is_file($backend . '/src/Ui/Controllers/Finance/DunningLevelController.php')
    && is_file($backend . '/src/Ui/Controllers/Finance/DebtorController.php')
    && is_file($backend . '/src/Ui/Config/Finance/paymentTermsControllerConfig.inc.php')
    && is_file($backend . '/src/Ui/Config/Finance/paymentTargetControllerConfig.inc.php')
    && is_file($backend . '/src/Ui/Config/Finance/dunningLevelControllerConfig.inc.php')
    && is_file($backend . '/src/Ui/Config/Finance/debtorControllerConfig.inc.php'));
check('I11 the debtor screen is its own — module-contact was not touched',
    !str_contains(file_get_contents($packages['Contact'] . '/src/Ui/ContactControllerTrait.php'), 'Debtor')
    && !array_filter(glob($packages['Contact'] . '/res/view/templates/Backend/*/*.tpl.php'), fn($f) => str_contains(file_get_contents($f), 'Debtor')));
check('I12 the document-text form offers the installation\'s languages, default first', DocumentTextForm::languages() === ['de', 'fr']);
check('I13 … and a posted text for a language the site does not serve is dropped',
    DocumentTextForm::posted(['document_text' => ['de' => 'A', 'it' => 'B']], DocumentTextForm::languages()) === ['de' => 'A', 'fr' => '']);

$sources = array_merge(
    glob($package . '/src/*/*.php'),
    glob($package . '/src/*/*/*.php'),
);
check('I14 no float anywhere in the module: no (float) cast, no floatval, no number_format on an amount',
    array_reduce($sources, fn($ok, $f) => $ok && !preg_match('/\(float\)|\(double\)|floatval\(|number_format\(/', file_get_contents($f)), true));
check('I15 no module-debtor class touches $_POST / $_GET / $_SERVER (Rule 4)',
    array_reduce($sources, fn($ok, $f) => $ok && !preg_match('/\$_(POST|GET|SERVER|REQUEST)\b/', file_get_contents($f)), true));
$namingFinancial = array_map('basename', array_filter($sources, fn($f) => str_contains(file_get_contents($f), 'Module\\\\Financial') || str_contains(file_get_contents($f), 'Module\\Financial')));
sort($namingFinancial);
check('I16 exactly TWO classes name module-financial — the soft check and the port adapter (ADR-040 decision 5)',
    $namingFinancial === ['LedgerAccountCheck.php', 'LedgerAccountingGateway.php']);


// ═════════════════════════════════════════════════════════════════════════
// P3 part 2 — InvoicingService, the documents, the accounting port
// ═════════════════════════════════════════════════════════════════════════
//
// State inherited: the KMU chart (every account active again), Müller
// (active, an `invoice` address, profile net-30), Anna (fr, profile
// disc-2-10, no address yet), Rita (profile disc-2-10, no address), the
// retired contact (inactive, no profile), financial REGISTERED, the vat seed.

$journalCount = fn() => $count('journal_entry');
$invoiceRow   = fn(int $id) => $db->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$id]);
$lineRows     = fn(int $id) => $db->fetchAllAssociative('SELECT * FROM invoice_line WHERE invoice_id = ? ORDER BY position', [$id]);
$taxRows      = fn(int $id) => $db->fetchAllAssociative('SELECT * FROM invoice_tax WHERE invoice_id = ? ORDER BY position', [$id]);
$entryRow     = fn(string $ref) => $db->fetchAssociative("SELECT e.* FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE CONCAT(y.code, '/', e.number) = ?", [$ref]);
$entryLines   = fn(string $ref) => $db->fetchAllAssociative("SELECT l.*, a.number AS account_number FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id JOIN fiscal_year y ON y.id = e.fiscal_year_id JOIN account a ON a.id = l.account_id WHERE CONCAT(y.code, '/', e.number) = ? ORDER BY l.position", [$ref]);
$sumOf        = fn(array $rows, string $col) => array_reduce($rows, fn(Money $s, array $r) => $s->add(Money::fromDecimal((string) $r[$col], 'CHF')), chf('0.00'));
$service      = fn(UnifiedEntityManager $em) => new InvoicingService($em, 'tester');
$refusal      = fn(callable $fn): ?string => caught($fn, InvoiceRefusedException::class)?->reason;
$readInvoice  = fn(int $id): Invoice => $wireDi()->getRepository(Invoice::class)->withLines($id);
$stateOf      = fn(int $id): string => (string) $db->fetchOne('SELECT state FROM invoice WHERE id = ?', [$id]);
/** The `{id, version}` pair finalize() takes, with the version the row carries NOW (what a screen would have shown). */
$at           = fn(int $id): array => ['id' => $id, 'version' => (int) $db->fetchOne('SELECT version FROM invoice WHERE id = ?', [$id])];
$mid          = $mueller->getId();
/** The standard draft: two services (UN), a text line, a lump sum (UR) — 310.00 net, 23.46 tax, 333.46 → 333.45. */
$standardDraft = fn(string $invoiceDate = '2026-03-10', string $serviceFrom = '2026-03-01', ?string $terms = null) => InvoiceDraft::invoice($mid, day($invoiceDate), day($serviceFrom), 'CHF', [
    LineDraft::service("Beratung\nvor Ort", '2.000', 'Std.', chf('50.00'), 'UN', '3200', sourceType: 'order', sourceRef: 'A-17'),
    LineDraft::service('Programmierung', '1.500', 'h', chf('120.00'), 'UN', '3400'),
    LineDraft::text('Danke für den Auftrag.'),
    LineDraft::lumpSum('Fachbuch', chf('30.00'), 'UR', '3200'),
], paymentTermsCode: $terms, sourceType: 'order', sourceRef: 'A-17');
$oneLine = fn(int $contactId, string $date, string $quantity, string $price, string $code = 'UN', string $account = '3400') => InvoiceDraft::invoice($contactId, day($date), day($date), 'CHF', [
    LineDraft::service('Stunden', $quantity, 'h', chf($price), $code, $account),
]);

// ── J. invoice() ─────────────────────────────────────────────────────────

echo "J. invoice(): a draft becomes a document in `invoicing` — number once, snapshot, VAT by service date, rounding line, nothing posted\n";
$emJ = $wireDi();
(new FiscalYearService($emJ))->open(new FiscalYear('2026', day('2026-01-01'), day('2026-12-31')));
// Müller's profile was deactivated in F16 — a NEW document needs an active debtor.
(new DebtorProfileService($emJ))->setActive($emJ->getRepository(DebtorProfile::class)->findByContact($mid), true);
$emJ  = $wireDi();
check('J0 an inactive DEBTOR (profile) refuses a new document → debtor-inactive; reactivated, it invoices', (function () use ($wireDi, $service, $oneLine, $anna, $refusal): bool {
    $em = $wireDi();
    (new DebtorProfileService($em))->setActive($em->getRepository(DebtorProfile::class)->findByContact($anna->getId()), false);
    $refused = $refusal(fn() => $service($wireDi())->invoice($oneLine($anna->getId(), '2026-03-10', '1.000', '10.00'))) === InvoiceRefusedException::DEBTOR_INACTIVE;
    $em = $wireDi();
    (new DebtorProfileService($em))->setActive($em->getRepository(DebtorProfile::class)->findByContact($anna->getId()), true);
    return $refused;
})());
$inv1 = $service($emJ)->invoice($standardDraft());
check('J1 the document exists: number 1 from the `invoice` range (credit-note untouched), state invoicing, NOTHING posted',
    $inv1->getId() !== null && $inv1->getNumber() === 1 && $stateOf($inv1->getId()) === InvoiceState::Invoicing->value && !$inv1->isFinal()
    && (string) $rangeOf('invoice') === '1' && (string) $rangeOf('credit-note') === '0' && $journalCount() === 0);
check('J2 totals: net 310.00, tax 23.46 (UN 22.68 + UR 0.78), 333.46 rounded to 333.45 — rounding −0.01 at the DOCUMENT level',
    $inv1->getNetTotal()->toDecimal() === '310.00' && $inv1->getTaxTotal()->toDecimal() === '23.46'
    && $inv1->getRounding()->toDecimal() === '-0.01' && $inv1->getGrossTotal()->toDecimal() === '333.45');
$read  = $readInvoice($inv1->getId());
$lines = $read->getLines();
check('J3 five lines in order — service, service, text, lump-sum — and the rounding line LAST on 3809 without a tax code',
    array_map(fn(InvoiceLine $l) => $l->type()->value, $lines) === ['service', 'service', 'text', 'lump-sum', 'rounding']
    && $lines[4]->getRevenueAccount() === '3809' && $lines[4]->getTaxCode() === null && $lines[4]->getAmount()->toDecimal() === '-0.01' && $lines[4]->getText() === 'Rundung');
check('J4 a service line: quantity 2.000, unit, unit price 50.00, amount 100.00, UN 810 with the LABEL snapshotted, account 3200, opaque origin, multi-line text kept',
    $lines[0]->getQuantity() === '2.000' && $lines[0]->getUnit() === 'Std.' && $lines[0]->getUnitPrice()?->toDecimal() === '50.00' && $lines[0]->getAmount()->toDecimal() === '100.00'
    && $lines[0]->getTaxCode() === 'UN' && $lines[0]->getTaxRate() === 810 && ($lines[0]->getTaxLabel() ?? '') !== '' && $lines[0]->getRevenueAccount() === '3200'
    && $lines[0]->getSourceType() === 'order' && $lines[0]->getSourceRef() === 'A-17' && str_contains($lines[0]->getText(), "\n"));
check('J5 1.5 h × 120.00 = 180.00; the lump sum carries no quantity; the text line no amount, code or account',
    $lines[1]->getAmount()->toDecimal() === '180.00' && $lines[3]->getQuantity() === null && $lines[3]->getAmount()->toDecimal() === '30.00'
    && $lines[2]->getAmount()->isZero() && $lines[2]->getTaxCode() === null && $lines[2]->getRevenueAccount() === null && $lines[2]->getUnitPrice() === null);
$taxes = $read->getTaxes();
check('J6 the tax summary per code, rounded ONCE: UN 810 on 280.00 = 22.68 (standard), UR 260 on 30.00 = 0.78 (reduced) — category and label snapshotted',
    count($taxes) === 2 && $taxes[0]->getTaxCode() === 'UN' && $taxes[0]->getTaxRate() === 810 && $taxes[0]->getBase()->toDecimal() === '280.00' && $taxes[0]->getTax()->toDecimal() === '22.68'
    && $taxes[0]->getTaxCategory() === 'standard' && $taxes[0]->getTaxLabel() !== ''
    && $taxes[1]->getTaxCode() === 'UR' && $taxes[1]->getTaxRate() === 260 && $taxes[1]->getBase()->toDecimal() === '30.00' && $taxes[1]->getTax()->toDecimal() === '0.78' && $taxes[1]->getTaxCategory() === 'reduced');
check('J7 the address snapshot is Müller\'s invoice address, the language the contact\'s, the party by id, the origin opaque',
    $read->getAddress()->getName() === 'Müller & Söhne AG' && $read->getAddress()->getStreet() === 'Bahnhofstrasse' && $read->getAddress()->getZip() === '8001' && $read->getAddress()->getCity() === 'Zürich'
    && $read->getAddress()->getCountry() === 'CH' && $read->getLanguage() === 'de' && $read->getContact()->getId() === $mid && $read->getSourceType() === 'order' && $read->getSourceRef() === 'A-17');
check('J8 the terms AS APPLIED: net-30 → due 2026-04-09, no tiers, no text; CHF, no exchange rate, price mode net, service date kept',
    $read->getPaymentTermsCode() === 'net-30' && $read->getDueDate()->format('Y-m-d') === '2026-04-09' && $read->getDiscountTiers() === [] && $read->getTermsText() === null
    && $read->getCurrency() === 'CHF' && $read->getPriceMode() === 'net' && $read->getServiceFrom()->format('Y-m-d') === '2026-03-01' && $read->getServiceTo() === null);
check('J9 created by the named actor, not changed, no ledger reference, version 1, not a credit note',
    $read->getCreatedBy() === 'tester' && $read->getChangedBy() === null && $read->getLedgerEntryRef() === null && $read->getVersion() === 1 && $read->getCreditNoteOf() === null && !$read->isCreditNote());
$row = $invoiceRow($inv1->getId());
check('J10 the row: DECIMAL totals, the address in flat addr_* columns, the tiers as JSON, kind and state as strings',
    $row['gross_total'] === '333.45' && $row['rounding'] === '-0.01' && $row['addr_zip'] === '8001' && $row['addr_name'] === 'Müller & Söhne AG'
    && $row['discount_tiers'] === '[]' && $row['kind'] === 'invoice' && $row['state'] === 'invoicing' && $row['exchange_rate'] === null && $row['ledger_entry_ref'] === null);
check('J10b the line rows: quantity DECIMAL(12,3), the rounding line\'s account, positions 1–5, the text line without price',
    count($lineRows($inv1->getId())) === 5 && $lineRows($inv1->getId())[0]['quantity'] === '2.000' && $lineRows($inv1->getId())[4]['revenue_account'] === '3809'
    && $lineRows($inv1->getId())[2]['unit_price'] === null && array_map('intval', array_column($lineRows($inv1->getId()), 'position')) === [1, 2, 3, 4, 5]);

$inv2 = $service($emJ)->invoice($oneLine($mid, '2026-03-11', '1.000', '100.00'));
check('J11 the next document takes number 2; 100.00 + 8.10 = 108.10 needs no rounding: NO rounding line, rounding 0.00',
    $inv2->getNumber() === 2 && count($inv2->getLines()) === 1 && $inv2->getRounding()->isZero() && $inv2->getGrossTotal()->toDecimal() === '108.10'
    && count($lineRows($inv2->getId())) === 1 && (string) $rangeOf('invoice') === '2');

echo "J. … line types, parent lines, negative quantities, discount, gross mode\n";
$emJ2 = $wireDi();
$inv3 = $service($emJ2)->invoice(InvoiceDraft::invoice($mid, day('2026-03-12'), day('2026-03-12'), 'CHF', [
    LineDraft::lumpSum('Paket Basis', chf('200.00'), 'UN', '3200')->beneath(
        LineDraft::service('Handbuch', '1.000', 'Stk.', chf('0.00'), 'UN', '3200'),
        LineDraft::text('Support während 12 Monaten inbegriffen'),
    ),
    LineDraft::service('Treuerabatt', '-1.000', null, chf('20.00'), 'UN', '3200'),
]));
$ls = $readInvoice($inv3->getId())->getLines();
check('J12 a priced line carries children at 0.00 (A-Pos, §13): positions 1–3, both children point at the parent, the parent and the rest at the top level',
    count($ls) === 5 && $ls[1]->getParentLine()?->getId() === $ls[0]->getId() && $ls[2]->getParentLine()?->getId() === $ls[0]->getId() && $ls[0]->getParentLine() === null
    && $ls[1]->getAmount()->isZero() && $ls[1]->getTaxCode() === 'UN' && $ls[1]->getTaxRate() === 810 && $ls[2]->type() === LineType::Text && $ls[3]->getParentLine() === null
    && (int) $lineRows($inv3->getId())[1]['parent_line_id'] === $ls[0]->getId());
check('J13 a negative quantity is a deduction: −1.000 × 20.00 = −20.00; net 180.00, tax 14.58, 194.58 → 194.60 (rounding +0.02, a rounding line)',
    $ls[3]->getQuantity() === '-1.000' && $ls[3]->getAmount()->toDecimal() === '-20.00' && $inv3->getNetTotal()->toDecimal() === '180.00' && $inv3->getTaxTotal()->toDecimal() === '14.58'
    && $inv3->getRounding()->toDecimal() === '0.02' && $inv3->getGrossTotal()->toDecimal() === '194.60' && $ls[4]->type() === LineType::Rounding && $ls[4]->getAmount()->toDecimal() === '0.02');

$inv4 = $service($emJ2)->invoice(InvoiceDraft::invoice($mid, day('2026-03-13'), day('2026-03-13'), 'CHF', [
    LineDraft::service('Lizenz', '3.000', 'Stk.', chf('100.00'), 'UN', '3200', discountPercent: 1000),
]));
check('J14 a 10 % discount (1000 hundredths): 3 × 100.00 − 30.00 = 270.00, tax 21.87, 291.87 → 291.85',
    $inv4->getLines()[0]->getDiscountPercent() === 1000 && $inv4->getLines()[0]->getAmount()->toDecimal() === '270.00' && $inv4->getTaxTotal()->toDecimal() === '21.87'
    && $inv4->getGrossTotal()->toDecimal() === '291.85' && $inv4->getRounding()->toDecimal() === '-0.02');

$inv5 = $service($emJ2)->invoice(InvoiceDraft::invoice($mid, day('2026-03-14'), day('2026-03-14'), 'CHF', [
    LineDraft::lumpSum('Pauschale inkl. MWST', chf('108.10'), 'UN', '3200'),
], priceMode: PriceMode::Gross));
check('J15 gross price mode: the line prints 108.10, the summary holds base 100.00 and tax 8.10, gross 108.10, mode stored',
    $inv5->getLines()[0]->getAmount()->toDecimal() === '108.10' && $inv5->getNetTotal()->toDecimal() === '100.00' && $inv5->getTaxTotal()->toDecimal() === '8.10'
    && $inv5->getGrossTotal()->toDecimal() === '108.10' && $inv5->getPriceMode() === 'gross' && $inv5->getTaxes()[0]->getBase()->toDecimal() === '100.00');

echo "J. … the rate by SERVICE date (ADR-041 decision 4)\n";
$inv6 = $service($emJ2)->invoice($standardDraft('2026-03-15', '2023-06-01'));
check('J16 a 2023 service invoiced in 2026 resolves the 2023 rates: UN 770 (280.00 → 21.56), UR 250 (30.00 → 0.75); 332.31 → 332.30',
    $inv6->getTaxes()[0]->getTaxRate() === 770 && $inv6->getTaxes()[0]->getTax()->toDecimal() === '21.56' && $inv6->getTaxes()[1]->getTaxRate() === 250 && $inv6->getTaxes()[1]->getTax()->toDecimal() === '0.75'
    && $inv6->getLines()[0]->getTaxRate() === 770 && $inv6->getTaxTotal()->toDecimal() === '22.31' && $inv6->getGrossTotal()->toDecimal() === '332.30'
    && $inv6->getServiceFrom()->format('Y-m-d') === '2023-06-01' && $inv6->getInvoiceDate()->format('Y-m-d') === '2026-03-15');
$inv6b = $service($emJ2)->invoice(InvoiceDraft::invoice($mid, day('2026-03-15'), day('2023-12-01'), 'CHF', [LineDraft::service('Abo', '1.000', 'Mt.', chf('100.00'), 'UN', '3400')], serviceTo: day('2024-02-29')));
check('J16b a service PERIOD is stored from–to; the rate resolves by its start (2023-12 → 7.7 %) — a period across a rate change is split into two documents by the source',
    $inv6b->getServiceTo()?->format('Y-m-d') === '2024-02-29' && $inv6b->getTaxes()[0]->getTaxRate() === 770);

echo "J. … the terms snapshot: tiers with their dates, the printed sentence in the document's language\n";
$emJ3 = $wireDi();
$disc = $emJ3->getRepository(PaymentTerms::class)->findByCode('disc-2-10');
$disc->setDocumentText(['de' => 'Zahlbar innert 30 Tagen, 2 % Skonto innert 10 Tagen.', 'fr' => 'Payable à 30 jours, 2 % d\'escompte à 10 jours.']);
(new DebtorMasterData($emJ3))->saveTerms($disc);
$emJ3 = $wireDi();
$inv7 = $service($emJ3)->invoice($standardDraft('2026-03-10', '2026-03-01', 'disc-2-10'));
check('J17 explicit terms disc-2-10: due 2026-04-09, ONE tier 10 days 2 % until 2026-03-20, the German sentence snapshotted',
    $inv7->getPaymentTermsCode() === 'disc-2-10' && $inv7->getDueDate()->format('Y-m-d') === '2026-04-09'
    && $inv7->getDiscountTiers() === [['days' => 10, 'percent' => 200, 'until' => '2026-03-20']]
    && $inv7->getTermsText() === 'Zahlbar innert 30 Tagen, 2 % Skonto innert 10 Tagen.');
$annaManaged = $emJ3->getRepository(Contact::class)->find($anna->getId());
(new ContactService($emJ3))->addAddress(new ContactAddress($annaManaged, 'main', new Address(['salutation' => 'Madame', 'first_name' => 'Anna', 'name' => 'Ébauche', 'street' => 'Rue du Lac', 'house_no' => '3', 'zip' => '1200', 'city' => 'Genève'])));
$emJ3 = $wireDi();
$inv8 = $service($emJ3)->invoice($oneLine($anna->getId(), '2026-03-16', '2.000', '60.00'));
check('J18 a French contact: the profile\'s terms (disc-2-10) apply, the sentence is the French one, the `main` address serves when there is no `invoice` one, the salutation is kept',
    $inv8->getLanguage() === 'fr' && $inv8->getPaymentTermsCode() === 'disc-2-10' && $inv8->getTermsText() === 'Payable à 30 jours, 2 % d\'escompte à 10 jours.'
    && $inv8->getAddress()->getCity() === 'Genève' && $inv8->getAddress()->getSalutation() === 'Madame' && $inv8->getAddress()->getFirstName() === 'Anna' && $inv8->getAddress()->getName() === 'Ébauche');
$itContact = new Contact(['kind' => 'organisation', 'company' => 'Ticino SA', 'language' => 'it']);
$itContact->addAddress(new ContactAddress($itContact, 'invoice', new Address(['name' => 'Ticino SA', 'street' => 'Via Nassa', 'house_no' => '1', 'zip' => '6900', 'city' => 'Lugano'])));
(new ContactService($emJ3))->save($itContact);
$itProfile = new DebtorProfile(['payment_terms_code' => 'disc-2-10']);
$itProfile->setContact($itContact);
(new DebtorProfileService($emJ3))->save($itProfile);
$emJ3 = $wireDi();
$inv9 = $service($emJ3)->invoice($oneLine($itContact->getId(), '2026-03-16', '1.000', '10.00'));
check('J19 a language without a text falls back to the DEFAULT language (it → de) — the resolver part 1 left out', $inv9->getLanguage() === 'it' && $inv9->getTermsText() === 'Zahlbar innert 30 Tagen, 2 % Skonto innert 10 Tagen.');

echo "J. … the snapshot is unaffected by later master-data changes\n";
$emJ4 = $wireDi();
$muellerLink = $emJ4->getRepository(ContactAddress::class)->findByContact($emJ4->getRepository(Contact::class)->find($mid))[0];
(new ContactService($emJ4))->saveAddress($muellerLink, ['type_code' => 'invoice'], ['name' => 'Müller & Söhne AG', 'street' => 'Seestrasse', 'house_no' => '99', 'zip' => '8002', 'city' => 'Zürich', 'country' => 'CH']);
$net30 = $emJ4->getRepository(PaymentTerms::class)->findByCode('net-30');
$net30->setDueDays(20);
(new DebtorMasterData($emJ4))->saveTerms($net30);
$again = $readInvoice($inv1->getId());
check('J20 the contact moved and net-30 became 20 days — the issued document still shows Bahnhofstrasse 1, 8001 and is due on 2026-04-09',
    $again->getAddress()->getStreet() === 'Bahnhofstrasse' && $again->getAddress()->getZip() === '8001' && $again->getDueDate()->format('Y-m-d') === '2026-04-09'
    && $emJ4->getRepository(Contact::class)->find($mid)->getAddresses()[0]->getAddress()->getZip() === '8002');
$emJ5 = $wireDi();
$inv10 = $service($emJ5)->invoice($oneLine($mid, '2026-03-17', '1.000', '10.00'));
check('J21 … while a NEW document takes the changed data: Seestrasse 99, due in 20 days', $inv10->getAddress()->getStreet() === 'Seestrasse' && $inv10->getDueDate()->format('Y-m-d') === '2026-04-06');

echo "J. … refusals — no number consumed, nothing written\n";
$emJ6    = $wireDi();
$before  = (string) $rangeOf('invoice');
$svc     = $service($emJ6);
$lonely  = new Contact(['kind' => 'person', 'first_name' => 'Ohne', 'last_name' => 'Profil', 'language' => 'de']);
(new ContactService($emJ6))->save($lonely);
check('J22 an unknown contact → contact-unknown; an inactive one → contact-inactive; one without a profile → no-debtor-profile; one without an address → no-address',
    $refusal(fn() => $svc->invoice($oneLine(999999, '2026-03-18', '1.000', '10.00'))) === InvoiceRefusedException::CONTACT_UNKNOWN
    && $refusal(fn() => $svc->invoice($oneLine($retired->getId(), '2026-03-18', '1.000', '10.00'))) === InvoiceRefusedException::CONTACT_INACTIVE
    && $refusal(fn() => $svc->invoice($oneLine($lonely->getId(), '2026-03-18', '1.000', '10.00'))) === InvoiceRefusedException::NO_DEBTOR_PROFILE
    && $refusal(fn() => $svc->invoice($oneLine($thirdContact->getId(), '2026-03-18', '1.000', '10.00'))) === InvoiceRefusedException::NO_ADDRESS);
check('J23 EUR → currency (Q6: base currency only); a period ending before it starts → dates; no lines → no-lines',
    $refusal(fn() => $svc->invoice(InvoiceDraft::invoice($mid, day('2026-03-18'), day('2026-03-18'), 'EUR', [LineDraft::text('x')]))) === InvoiceRefusedException::CURRENCY
    && $refusal(fn() => $svc->invoice(InvoiceDraft::invoice($mid, day('2026-03-18'), day('2026-03-18'), 'CHF', [LineDraft::text('x')], serviceTo: day('2026-03-17')))) === InvoiceRefusedException::DATES
    && $refusal(fn() => $svc->invoice(InvoiceDraft::invoice($mid, day('2026-03-18'), day('2026-03-18'), 'CHF', []))) === InvoiceRefusedException::NO_LINES);
$draftWith = fn(LineDraft ...$lines) => InvoiceDraft::invoice($mid, day('2026-03-18'), day('2026-03-18'), 'CHF', $lines);
check('J24 line rules → line: a priced line without tax code / without account / with a bad quantity, a lump sum with a quantity, a text line with a price, a drafted rounding line, a child with children, children under a text line, a 101 % discount',
    $refusal(fn() => $svc->invoice($draftWith(LineDraft::service('x', '1.000', null, chf('1.00'), '', '3200')))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(LineDraft::service('x', '1.000', null, chf('1.00'), 'UN', 'bank')))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(LineDraft::service('x', '1.5555', null, chf('1.00'), 'UN', '3200')))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(new LineDraft(LineType::LumpSum, 'x', '2.000', null, chf('1.00'), 0, 'UN', '3200')))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(new LineDraft(LineType::Text, 'x', null, null, chf('1.00'))))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(new LineDraft(LineType::Rounding, 'Rundung')))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(LineDraft::lumpSum('a', chf('1.00'), 'UN', '3200')->beneath(LineDraft::text('b')->beneath(LineDraft::text('c')))))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(LineDraft::text('a')->beneath(LineDraft::text('b'))))) === InvoiceRefusedException::LINE
    && $refusal(fn() => $svc->invoice($draftWith(LineDraft::lumpSum('a', chf('1.00'), 'UN', '3200', discountPercent: 10100)))) === InvoiceRefusedException::LINE);
check('J25 an unknown tax code → tax-code-unknown; a service date before the seed (2017) → no-tax-rate — never a silent 0 %',
    $refusal(fn() => $svc->invoice($draftWith(LineDraft::lumpSum('a', chf('1.00'), 'XX', '3200')))) === InvoiceRefusedException::TAX_CODE_UNKNOWN
    && $refusal(fn() => $svc->invoice(InvoiceDraft::invoice($mid, day('2026-03-18'), day('2017-06-01'), 'CHF', [LineDraft::lumpSum('a', chf('1.00'), 'UN', '3200')]))) === InvoiceRefusedException::NO_TAX_RATE);
check('J26 a GROUP account (32) and an unknown account → account-not-postable (the soft check against financial)',
    $refusal(fn() => $svc->invoice($draftWith(LineDraft::lumpSum('a', chf('1.00'), 'UN', '32')))) === InvoiceRefusedException::ACCOUNT_NOT_POSTABLE
    && $refusal(fn() => $svc->invoice($draftWith(LineDraft::lumpSum('a', chf('1.00'), 'UN', '9999999')))) === InvoiceRefusedException::ACCOUNT_NOT_POSTABLE);
check('J27 an invoice whose total is negative → negative-total (that is a credit note)',
    $refusal(fn() => $svc->invoice($draftWith(LineDraft::service('Rückvergütung', '-1.000', null, chf('100.00'), 'UN', '3200')))) === InvoiceRefusedException::NEGATIVE_TOTAL);
check('J28 a credit note against a document still `invoicing` → credit-note-target (it is re-invoiced, not credited); against an unknown one, or an invoice naming a target → the same',
    $refusal(fn() => $svc->invoice(InvoiceDraft::creditNote($inv2->getId(), $mid, day('2026-03-18'), 'CHF', [LineDraft::lumpSum('a', chf('1.00'), 'UN', '3200')]))) === InvoiceRefusedException::CREDIT_NOTE_TARGET
    && $refusal(fn() => $svc->invoice(InvoiceDraft::creditNote(999999, $mid, day('2026-03-18'), 'CHF', [LineDraft::lumpSum('a', chf('1.00'), 'UN', '3200')]))) === InvoiceRefusedException::CREDIT_NOTE_TARGET
    && $refusal(fn() => $svc->invoice(new InvoiceDraft(InvoiceKind::Invoice, $mid, day('2026-03-18'), day('2026-03-18'), null, 'CHF', PriceMode::Net, null, null, [LineDraft::text('x')], null, null, $inv2->getId()))) === InvoiceRefusedException::CREDIT_NOTE_TARGET);
check('J28b an INVOICE without a service date → dates (the rate resolves by it); a credit note may leave it out (it takes the invoice\'s — L17c)',
    $refusal(fn() => $svc->invoice(new InvoiceDraft(InvoiceKind::Invoice, $mid, day('2026-03-18'), null, null, 'CHF', PriceMode::Net, null, null, [LineDraft::text('x')]))) === InvoiceRefusedException::DATES);
(new VatMasterData($emJ6))->setActive($emJ6->getRepository(TaxCode::class)->findByCode('US'), false);
(new DebtorMasterData($emJ6))->setTermsActive($emJ6->getRepository(PaymentTerms::class)->findByCode('net-30'), false);
$emJ7 = $wireDi();
$svc7 = $service($emJ7);
check('J29 the reference rule for a NEW document: a deactivated tax code → tax-code-inactive',
    $refusal(fn() => $svc7->invoice(InvoiceDraft::invoice($mid, day('2026-03-18'), day('2026-03-18'), 'CHF', [LineDraft::lumpSum('Übernachtung', chf('1.00'), 'US', '3200')], paymentTermsCode: 'disc-2-10'))) === InvoiceRefusedException::TAX_CODE_INACTIVE);
check('J29b … the profile\'s deactivated terms → terms-inactive (the debtor is corrected first); unknown terms → terms-unknown',
    $refusal(fn() => $svc7->invoice($oneLine($mid, '2026-03-18', '1.000', '10.00'))) === InvoiceRefusedException::TERMS_INACTIVE
    && $refusal(fn() => $svc7->invoice($standardDraft('2026-03-18', '2026-03-18', 'ghost'))) === InvoiceRefusedException::TERMS_UNKNOWN);
(new VatMasterData($emJ7))->setActive($emJ7->getRepository(TaxCode::class)->findByCode('US'), true);
(new DebtorMasterData($emJ7))->setTermsActive($emJ7->getRepository(PaymentTerms::class)->findByCode('net-30'), true);
check('J30 after every refusal: the range stands where it stood, no document and no journal entry was written',
    (string) $rangeOf('invoice') === $before && $count('invoice') === 11 && $journalCount() === 0 && !$wireDi()->getTransaction(Invoice::class)->isOpen());


// ── K. reinvoice() ───────────────────────────────────────────────────────

echo "K. reinvoice(): the same number, a new snapshot, the version bumped — still nothing posted\n";
$emK = $wireDi();
$re  = $service($emK)->reinvoice($inv1->getId(), 1, InvoiceDraft::invoice($mid, day('2026-03-15'), day('2026-03-01'), 'CHF', [
    LineDraft::service('Beratung, korrigiert', '3.000', 'Std.', chf('50.00'), 'UN', '3200'),
]));
check('K1 number 1 kept; new date, ONE line, 150.00 + 12.15 = 162.15 (no rounding), version 2, changed by tester; nothing posted, no number drawn',
    $re->getNumber() === 1 && $re->getId() === $inv1->getId() && $re->getInvoiceDate()->format('Y-m-d') === '2026-03-15' && count($re->getLines()) === 1
    && $re->getGrossTotal()->toDecimal() === '162.15' && $re->getRounding()->isZero() && $re->getVersion() === 2 && $re->getChangedBy() === 'tester'
    && $journalCount() === 0 && (string) $rangeOf('invoice') === $before);
check('K2 the old lines and tax rows are GONE (orphan removal), the new ones there', count($lineRows($inv1->getId())) === 1 && count($taxRows($inv1->getId())) === 1 && $taxRows($inv1->getId())[0]['tax_amount'] === '12.15');
check('K3 the snapshot moved with it: the address is the CURRENT one now (Seestrasse), the due date follows the new invoice date',
    $readInvoice($inv1->getId())->getAddress()->getStreet() === 'Seestrasse' && $readInvoice($inv1->getId())->getDueDate()->format('Y-m-d') === '2026-04-04');
$emK2 = $wireDi();
check('K4 a STALE version (1) is refused — another writer was first (InvoiceConflictException); an unknown id the same way',
    caught(fn() => $service($emK2)->reinvoice($inv1->getId(), 1, $oneLine($mid, '2026-03-15', '1.000', '10.00')), InvoiceConflictException::class)?->expectedVersion === 1
    && throws(fn() => $service($wireDi())->reinvoice(999999, 1, $oneLine($mid, '2026-03-15', '1.000', '10.00')), InvoiceConflictException::class));
check('K5 the kind is immutable: re-issuing an invoice as a credit note → kind-changed',
    $refusal(fn() => $service($wireDi())->reinvoice($inv1->getId(), 2, InvoiceDraft::creditNote($inv2->getId(), $mid, day('2026-03-15'), 'CHF', [LineDraft::lumpSum('a', chf('1.00'), 'UN', '3200')]))) === InvoiceRefusedException::KIND_CHANGED);
check('K6 a refused re-issue leaves the document as it was (version 2, one line)', $readInvoice($inv1->getId())->getVersion() === 2 && count($lineRows($inv1->getId())) === 1);


// ── L. finalize() and the accounting port ────────────────────────────────

echo "L. finalize(): posted once through the port, state final, the posting shape, the open amount\n";
$emL = $wireDi();
$svc = $service($emL);
check('L0 an `invoicing` document has NO open amount yet (plan §6.2)', $svc->openAmount($emL->getRepository(Invoice::class)->find($inv1->getId()))->isZero());
$staleBefore = $journalCount();
check('L0b finalize() with a STALE version (1 — the document is at 2 since K1) → InvoiceConflictException, nothing posted, still invoicing',
    caught(fn() => $service($wireDi())->finalize([['id' => $inv1->getId(), 'version' => 1]]), InvoiceConflictException::class)?->expectedVersion === 1
    && $journalCount() === $staleBefore && $stateOf($inv1->getId()) === 'invoicing');
check('L0c finalize() refuses a bare id — it takes {id, version} pairs (DEBTOR-FINAL-001)', throws(fn() => $service($wireDi())->finalize([$inv1->getId()]), \InvalidArgumentException::class));
$done = $svc->finalize([$at($inv1->getId())]);
$f1   = $done[0];
check('L1 final: state, ledger reference 2026/1, ONE journal entry, the actor stamped, version bumped',
    count($done) === 1 && $f1->isFinal() && $stateOf($inv1->getId()) === InvoiceState::Final->value && $f1->getLedgerEntryRef() === '2026/1' && $journalCount() === 1 && $f1->getChangedBy() === 'tester' && $f1->getVersion() === 3);
$jl = $entryLines('2026/1');
check('L2 the posting shape: 1100 DEBIT 162.15 | 3200 CREDIT 150.00 with UN 810, base 150.00, tax 12.15 (the net line) | 2200 CREDIT 12.15 (VAT by category); Σ debit = Σ credit',
    count($jl) === 3
    && $jl[0]['account_number'] === '1100' && $jl[0]['debit'] === '162.15' && $jl[0]['tax_code'] === null
    && $jl[1]['account_number'] === '3200' && $jl[1]['credit'] === '150.00' && $jl[1]['tax_code'] === 'UN' && (int) $jl[1]['tax_rate'] === 810 && $jl[1]['tax_base'] === '150.00' && $jl[1]['tax_amount'] === '12.15' && $jl[1]['text'] === 'Beratung, korrigiert'
    && $jl[2]['account_number'] === '2200' && $jl[2]['credit'] === '12.15' && $jl[2]['tax_code'] === null
    && $sumOf($jl, 'debit')->equals($sumOf($jl, 'credit')) && $sumOf($jl, 'debit')->toDecimal() === '162.15');
$e1 = $entryRow('2026/1');
check('L3 the entry is GENERATED, dated the invoice date, origin invoice / 1 (opaque), key invoice:{id}:final, text names document and party, author tester',
    $e1['kind'] === 'generated' && $e1['entry_date'] === '2026-03-15' && $e1['source_type'] === 'invoice' && $e1['source_ref'] === '1'
    && $e1['idempotency_key'] === 'invoice:' . $inv1->getId() . ':final' && $e1['text'] === 'Rechnung 1 · Müller & Söhne AG' && $e1['created_by'] === 'tester');
check('L4 the open amount of the final invoice is its gross', $svc->openAmount($f1)->toDecimal() === '162.15');
$emL2 = $wireDi();
check('L5 finalize TWICE is refused (not-invoicing), not silently accepted — and nothing was posted again',
    $refusal(fn() => $service($emL2)->finalize([$at($inv1->getId())])) === InvoiceRefusedException::NOT_INVOICING && $journalCount() === 1);
check('L6 a final document is immutable: reinvoice → not-invoicing, the row unchanged (version 3, one line)',
    $refusal(fn() => $service($wireDi())->reinvoice($inv1->getId(), 3, $oneLine($mid, '2026-03-15', '1.000', '10.00'))) === InvoiceRefusedException::NOT_INVOICING
    && $readInvoice($inv1->getId())->getVersion() === 3 && count($lineRows($inv1->getId())) === 1);
$finalEntity   = $readInvoice($inv1->getId());
$blankSnapshot = (new \ReflectionClass(\Z77\Module\Debtor\Invoicing\DocumentSnapshot::class))->newInstanceWithoutConstructor();
check('L7 the ENTITY refuses as well, not only the service: finalize() and reissue() on a final document throw',
    throws(fn() => $finalEntity->finalize(null, 'x', new \DateTimeImmutable()), \LogicException::class)
    && throws(fn() => $finalEntity->reissue($blankSnapshot, [], [], 'x', new \DateTimeImmutable()), \LogicException::class));
check('L8 a batch naming an unknown id → not-found; an empty batch does nothing; a credit note at 0.00 against the final invoice → negative-total (it must be positive)',
    $refusal(fn() => $service($wireDi())->finalize([['id' => 999999, 'version' => 1]])) === InvoiceRefusedException::NOT_FOUND && $service($wireDi())->finalize([]) === []
    && $refusal(fn() => $service($wireDi())->invoice(InvoiceDraft::creditNote($inv1->getId(), $mid, day('2026-03-18'), 'CHF', [LineDraft::text('nichts')]))) === InvoiceRefusedException::NEGATIVE_TOTAL);

echo "L. … a batch where one document fails rolls back WHOLE — no state, no number, no entry\n";
$emL3  = $wireDi();
$inv11 = $service($emL3)->invoice($oneLine($mid, '2027-03-01', '1.000', '10.00'));   // no fiscal year covers 2027
$emL4  = $wireDi();
$rangeBefore = (string) $rangeOf('journal-entry.2026');
$e = caught(fn() => $service($emL4)->finalize([$at($inv2->getId()), $at($inv11->getId())]), AccountingRefusedException::class);
check('L9 the ledger refuses the second document (no-fiscal-year) through the port — AccountingRefusedException with financial\'s reason and exception behind it',
    $e instanceof AccountingRefusedException && $e->reason === 'no-fiscal-year' && $e->getPrevious() !== null && str_contains($e->getMessage(), '2027'));
check('L10 … and the FIRST document of the batch is still invoicing, the journal range untouched, no entry written, no unit of work open',
    $stateOf($inv2->getId()) === 'invoicing' && $readInvoice($inv2->getId())->getLedgerEntryRef() === null
    && (string) $rangeOf('journal-entry.2026') === $rangeBefore && $journalCount() === 1 && !$wireDi()->getTransaction(Invoice::class)->isOpen());

echo "L. … NullAccountingGateway and the gateway selection by config\n";
$emL5 = $wireDi();
$null = new InvoicingService($emL5, 'tester', new NullAccountingGateway($emL5, 'tester'));
$null->finalize([$at($inv2->getId())]);
check('L11 with the Null gateway finalize() works and posts NOTHING: final, ledger reference null, no entry, open amount = gross',
    $readInvoice($inv2->getId())->isFinal() && $readInvoice($inv2->getId())->getLedgerEntryRef() === null && $journalCount() === 1
    && $null->openAmount($readInvoice($inv2->getId()))->toDecimal() === '108.10');
check('L12 the package config names the ledger gateway, and fromConfig() builds it while financial is registered',
    DI::getModuleManager()->getModuleConfig('debtor')?->get('accountingGateway') === LedgerAccountingGateway::class
    && AccountingGateways::fromConfig($emL5, 'tester') instanceof LedgerAccountingGateway);
$writeModules(false);
$rm($base . '/var/cache');
$emNoFin = $wireDi();
check('L13 with financial UNREGISTERED the default gateway refuses to run (AccountingUnavailableException) — never a silent «books nothing»',
    throws(fn() => AccountingGateways::fromConfig($emNoFin, 'tester'), AccountingUnavailableException::class)
    && throws(fn() => new LedgerAccountingGateway($emNoFin, 'tester'), AccountingUnavailableException::class));
$writeModules(true);
$rm($base . '/var/cache');
$fullConfig = fn(string $gateway) => "<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class, \\Z77\\Module\\Debtor\\Entities\\Invoice::class, \\Z77\\Module\\Debtor\\Entities\\InvoiceLine::class, \\Z77\\Module\\Debtor\\Entities\\InvoiceTax::class], "
    . "'debtorAccounts' => ['receivable' => '1100', 'discount' => '3800', 'loss' => '3805', 'rounding' => '3809', 'dunningFee' => '6950'], {$gateway}];";
$emOv = $writeOverride($fullConfig("'accountingGateway' => \\Z77\\Module\\Debtor\\Accounting\\NullAccountingGateway::class"));
check('L14 a project override naming NullAccountingGateway selects it', AccountingGateways::fromConfig($emOv, 'tester') instanceof NullAccountingGateway);
$emOv = $writeOverride($fullConfig("'x' => 1"));
check('L15 a missing key fails loudly (UnexpectedValueException — BOOT-CONFIG-001: an override carries the full config)', throws(fn() => AccountingGateways::fromConfig($emOv, 'tester'), \UnexpectedValueException::class));
$emOv = $writeOverride($fullConfig("'accountingGateway' => 'Nope\\\\Gateway'"));
$unknownClass = throws(fn() => AccountingGateways::fromConfig($emOv, 'tester'), AccountingUnavailableException::class);
$emOv = $writeOverride($fullConfig("'accountingGateway' => \\Z77\\Module\\Debtor\\Services\\DebtorAccounts::class"));
check('L16 an unknown class, or one that is no gateway → AccountingUnavailableException',
    $unknownClass && throws(fn() => AccountingGateways::fromConfig($emOv, 'tester'), AccountingUnavailableException::class));
$emL6 = $writeOverride(null);

echo "L. … the credit note: the only correction of a final document, the mirrored posting, the open amount\n";
$svcC = $service($emL6);
$cn   = $svcC->invoice(InvoiceDraft::creditNote($inv1->getId(), $mid, day('2026-03-20'), 'CHF', [
    LineDraft::service('Beratung — 1 Std. zu viel verrechnet', '1.000', 'Std.', chf('50.00'), 'UN', '3200'),
]));
check('L17 a credit note against the FINAL invoice: number 1 of the credit-note range, lines AS PRINTED (50.00 + 4.05 = 54.05), state invoicing, references the invoice',
    $cn->isCreditNote() && $cn->kind() === InvoiceKind::CreditNote && $cn->getNumber() === 1 && $cn->getCreditNoteOf()?->getId() === $inv1->getId() && !$cn->isFinal()
    && $cn->getGrossTotal()->toDecimal() === '54.05' && $cn->getLines()[0]->getAmount()->toDecimal() === '50.00' && (string) $rangeOf('credit-note') === '1' && $cn->documentName() === 'Gutschrift 1');
check('L17b … its service dates are the INVOICE\'s (2026-03-01, none) — not the credit note\'s own date', $cn->getServiceFrom()->format('Y-m-d') === '2026-03-01' && $cn->getServiceTo() === null);
check('L18 … while it is invoicing the invoice\'s open amount is unchanged', $svcC->openAmount($emL6->getRepository(Invoice::class)->find($inv1->getId()))->toDecimal() === '162.15');
$svcC->finalize([$at($cn->getId())]);

echo "L. … the credit note follows the rate of the ORIGINAL supply (review 2026-09-23, ADR-041 decision 4)\n";
$emL6b = $wireDi();
$service($emL6b)->finalize([$at($inv6->getId())]);   // the 2023 service (UN 7.7 %, UR 2.5 %) invoiced in 2026
$emL6c = $wireDi();
$cn2023 = $service($emL6c)->invoice(InvoiceDraft::creditNote($inv6->getId(), $mid, day('2026-04-10'), 'CHF', [
    LineDraft::service('Beratung 2023, Teilgutschrift', '1.000', 'Std.', chf('50.00'), 'UN', '3200'),
]));
check('L17c a credit note issued in 2026 against the 2023 invoice takes the invoice\'s service date and resolves 7.7 %: tax 3.85, not 4.05',
    $cn2023->getServiceFrom()->format('Y-m-d') === '2023-06-01' && $cn2023->getTaxes()[0]->getTaxRate() === 770 && $cn2023->getTaxes()[0]->getTax()->toDecimal() === '3.85'
    && $cn2023->getLines()[0]->getTaxRate() === 770);
check('L17d … a credit note that names its OWN service date in 2026 (8.1 % against the invoice\'s 7.7 %) → credit-note-rate, the message names both rates',
    (function () use ($service, $wireDi, $inv6, $mid): bool {
        $e = caught(fn() => $service($wireDi())->invoice(InvoiceDraft::creditNote($inv6->getId(), $mid, day('2026-04-10'), 'CHF',
            [LineDraft::service('x', '1.000', 'Std.', chf('50.00'), 'UN', '3200')], serviceFrom: day('2026-04-10'))), InvoiceRefusedException::class);
        return $e?->reason === InvoiceRefusedException::CREDIT_NOTE_RATE && str_contains($e->getMessage(), '8.1 %') && str_contains($e->getMessage(), '7.7 %');
    })());
$ownDate = $service($wireDi())->invoice(InvoiceDraft::creditNote($inv6->getId(), $mid, day('2026-04-10'), 'CHF',
    [LineDraft::service('x', '1.000', 'Std.', chf('10.00'), 'UN', '3200'), LineDraft::lumpSum('Export', chf('5.00'), 'UE', '3200')], serviceFrom: day('2023-09-01')));
$ownRates = [];
foreach ($ownDate->getTaxes() as $t) { $ownRates[$t->getTaxCode()] = $t->getTaxRate(); }
check('L17e … an own service date INSIDE the old rate\'s validity (2023-09-01) passes: same rate as the invoice; a code the invoice never carried (UE) resolves freely',
    $ownDate->getServiceFrom()->format('Y-m-d') === '2023-09-01' && $ownRates === ['UE' => 0, 'UN' => 770]);
$service($wireDi())->finalize([$at($cn2023->getId())]);
$c23 = $entryLines((string) $readInvoice($cn2023->getId())->getLedgerEntryRef());
check('L17f … its posting carries UN 770 with base −50.00 / tax −3.85 and a 2200 debit of 3.85 — the VAT return sees the reduction under the rate of the supply',
    count(array_filter($c23, fn($r) => $r['account_number'] === '3200' && (int) $r['tax_rate'] === 770 && $r['tax_amount'] === '-3.85')) === 1
    && count(array_filter($c23, fn($r) => $r['account_number'] === '2200' && $r['debit'] === '3.85')) === 1);
$cl = $entryLines('2026/2');
check('L19 the MIRRORED posting: 1100 CREDIT 54.05 | 3200 DEBIT 50.00 with base −50.00 and tax −4.05 | 2200 DEBIT 4.05 — the same shape, every side swapped, tax data negated',
    count($cl) === 3
    && $cl[0]['account_number'] === '1100' && $cl[0]['credit'] === '54.05'
    && $cl[1]['account_number'] === '3200' && $cl[1]['debit'] === '50.00' && $cl[1]['tax_code'] === 'UN' && $cl[1]['tax_base'] === '-50.00' && $cl[1]['tax_amount'] === '-4.05'
    && $cl[2]['account_number'] === '2200' && $cl[2]['debit'] === '4.05' && $sumOf($cl, 'debit')->equals($sumOf($cl, 'credit')));
$e2 = $entryRow('2026/2');
check('L20 its entry: origin credit-note / 1, key credit-note:{id}:final, text «Gutschrift 1 · …», ledger reference 2026/2 on the document',
    $e2['source_type'] === 'credit-note' && $e2['source_ref'] === '1' && $e2['idempotency_key'] === 'credit-note:' . $cn->getId() . ':final'
    && str_starts_with($e2['text'], 'Gutschrift 1 · Müller') && $readInvoice($cn->getId())->getLedgerEntryRef() === '2026/2');
$emL7 = $wireDi();
check('L21 the invoice\'s open amount drops by the credit note: 162.15 − 54.05 = 108.10 (derived, not stored); the credit note itself has none',
    $service($emL7)->openAmount($emL7->getRepository(Invoice::class)->find($inv1->getId()))->toDecimal() === '108.10'
    && $service($emL7)->openAmount($emL7->getRepository(Invoice::class)->find($cn->getId()))->isZero());
$creditRow = $invoiceRow($cn->getId());
check('L22 the credit note row: kind credit-note, credit_note_of_id → the invoice, positive gross', $creditRow['kind'] === 'credit-note' && (int) $creditRow['credit_note_of_id'] === $inv1->getId() && $creditRow['gross_total'] === '54.05');

echo "L. … the tax share per line: allocate(), mixed signs, a code deactivated after issue, a zero document, a batch of two\n";
$emL8 = $wireDi();
$mixed = $service($emL8)->invoice(InvoiceDraft::invoice($mid, day('2026-04-01'), day('2026-04-01'), 'CHF', [
    LineDraft::service('Ware', '1.000', 'Stk.', chf('100.00'), 'UN', '3200'),
    LineDraft::service('Retoure', '-1.000', 'Stk.', chf('30.00'), 'UN', '3400'),
]));
check('L23 one code with MIXED signs: base 70.00, tax 5.67 (once), 75.67 → 75.65', $mixed->getTaxes()[0]->getBase()->toDecimal() === '70.00' && $mixed->getTaxes()[0]->getTax()->toDecimal() === '5.67' && $mixed->getGrossTotal()->toDecimal() === '75.65' && $mixed->getRounding()->toDecimal() === '-0.02');
$service($emL8)->finalize([$at($mixed->getId())]);
$ml = $entryLines((string) $readInvoice($mixed->getId())->getLedgerEntryRef());
$byAccount = [];
foreach ($ml as $r) { $byAccount[$r['account_number']][] = $r; }
check('L24 its posting: 1100 debit 75.65 | 3200 credit 100.00 (base 100.00, tax 8.10) | 3400 DEBIT 30.00 (base −30.00, tax −2.43) | 2200 credit 5.67 | 3809 DEBIT 0.02 (rounded down); Σ tax_amount = the tax row; balanced',
    $byAccount['1100'][0]['debit'] === '75.65' && $byAccount['3200'][0]['credit'] === '100.00' && $byAccount['3200'][0]['tax_amount'] === '8.10'
    && $byAccount['3400'][0]['debit'] === '30.00' && $byAccount['3400'][0]['tax_base'] === '-30.00' && $byAccount['3400'][0]['tax_amount'] === '-2.43'
    && $byAccount['2200'][0]['credit'] === '5.67' && $byAccount['3809'][0]['debit'] === '0.02' && $byAccount['3809'][0]['tax_code'] === null
    && $sumOf(array_filter($ml, fn($r) => $r['tax_code'] !== null), 'tax_amount')->toDecimal() === '5.67' && $sumOf($ml, 'debit')->equals($sumOf($ml, 'credit')));
$emL9  = $wireDi();
$third = $service($emL9)->invoice(InvoiceDraft::invoice($mid, day('2026-04-02'), day('2026-04-02'), 'CHF', [
    LineDraft::lumpSum('Teil 1', chf('33.33'), 'UN', '3200'),
    LineDraft::lumpSum('Teil 2', chf('33.33'), 'UN', '3200'),
    LineDraft::lumpSum('Teil 3', chf('33.34'), 'UN', '3200'),
    LineDraft::lumpSum('Buch', chf('30.00'), 'UR', '3200'),
]));
$service($emL9)->finalize([$at($third->getId())]);
$tl = array_values(array_filter($entryLines((string) $readInvoice($third->getId())->getLedgerEntryRef()), fn($r) => $r['tax_code'] === 'UN'));
check('L25 three lines of one code share its tax 8.10 by allocate(): 2.70 / 2.70 / 2.70 — Σ exactly the tax row, no Rappen smeared, nothing lost',
    count($tl) === 3 && array_column($tl, 'tax_amount') === ['2.70', '2.70', '2.70'] && $sumOf($tl, 'tax_amount')->toDecimal() === '8.10');
$vatLinesOfThird = array_values(array_filter($entryLines((string) $readInvoice($third->getId())->getLedgerEntryRef()), fn($r) => $r['account_number'] === '2200'));
check('L26 VAT per CODE: two 2200 lines (UN 8.10, UR 0.78), both without tax data — the tax data sits on the net lines only (ADR-041 decision 7)',
    count($vatLinesOfThird) === 2 && array_column($vatLinesOfThird, 'credit') === ['8.10', '0.78'] && array_column($vatLinesOfThird, 'tax_code') === [null, null]);

$emL10 = $wireDi();
(new VatMasterData($emL10))->setActive($emL10->getRepository(TaxCode::class)->findByCode('UR'), false);
$emL11 = $wireDi();
$service($emL11)->finalize([$at($inv7->getId())]);   // issued with UR while it was active
check('L27 a tax code deactivated AFTER issue still posts on finalize — the document carries its snapshot, the ledger asks for existence only (ADR-043/19)',
    $readInvoice($inv7->getId())->isFinal() && $readInvoice($inv7->getId())->getLedgerEntryRef() !== null
    && $refusal(fn() => $service($wireDi())->invoice($draftWith(LineDraft::lumpSum('Buch', chf('1.00'), 'UR', '3200')))) === InvoiceRefusedException::TAX_CODE_INACTIVE);
$emR = $wireDi();
(new VatMasterData($emR))->setActive($emR->getRepository(TaxCode::class)->findByCode('UR'), true);

$emL12 = $wireDi();
$zero  = $service($emL12)->invoice(InvoiceDraft::invoice($mid, day('2026-04-03'), day('2026-04-03'), 'CHF', [LineDraft::text('Nur ein Hinweis, kein Betrag.')]));
$countBefore = $journalCount();
$service($emL12)->finalize([$at($zero->getId())]);
check('L28 a document without an amount (text only) finalizes and posts NOTHING: final, ledger reference null, no entry, PostingBuilder yields null',
    $zero->getGrossTotal()->isZero() && count($zero->getLines()) === 1 && $readInvoice($zero->getId())->isFinal() && $readInvoice($zero->getId())->getLedgerEntryRef() === null
    && $journalCount() === $countBefore && PostingBuilder::build($readInvoice($zero->getId()), '1100') === null);

$emL13 = $wireDi();
$countBefore = $journalCount();
$pair  = $service($emL13)->finalize([$at($inv4->getId()), $at($inv3->getId())]);
check('L29 a batch of two in ONE unit of work: both final, two consecutive journal numbers, in the order given',
    count($pair) === 2 && $pair[0]->getId() === $inv4->getId() && $pair[0]->isFinal() && $pair[1]->isFinal() && $journalCount() === $countBefore + 2
    && (int) explode('/', (string) $pair[0]->getLedgerEntryRef())[1] + 1 === (int) explode('/', (string) $pair[1]->getLedgerEntryRef())[1]);
$rl = $entryLines((string) $pair[1]->getLedgerEntryRef());
check('L30 the rounding line posts to 3809 without tax: rounded UP by 0.02 → 3809 CREDIT 0.02 (inv3); the children at 0.00 post nothing',
    count(array_filter($rl, fn($r) => $r['account_number'] === '3809' && $r['credit'] === '0.02' && $r['tax_code'] === null)) === 1
    && count(array_filter($rl, fn($r) => $r['account_number'] === '3200')) === 2);   // Paket 200.00 credit, Treuerabatt 20.00 debit — the 0.00 child is absent
check('L31 the gross-mode document posts net 100.00 with tax 8.10 on the revenue line and 108.10 on the receivable',
    (function () use ($service, $wireDi, $inv5, $entryLines, $readInvoice, $at): bool {
        $service($wireDi())->finalize([$at($inv5->getId())]);
        $rows = $entryLines((string) $readInvoice($inv5->getId())->getLedgerEntryRef());
        $rev  = array_values(array_filter($rows, fn($r) => $r['account_number'] === '3200'))[0];
        $rec  = array_values(array_filter($rows, fn($r) => $r['account_number'] === '1100'))[0];
        return $rev['credit'] === '100.00' && $rev['tax_base'] === '100.00' && $rev['tax_amount'] === '8.10' && $rec['debit'] === '108.10';
    })());


echo "L. … gross mode, Rappen lines (review 2026-09-23): the share must leave every line a positive net\n";
$emL14  = $wireDi();
$rappen = fn(int $n, string ...$more) => InvoiceDraft::invoice($mid, day('2026-04-05'), day('2026-04-05'), 'CHF', array_merge(
    array_map(fn($i) => LineDraft::lumpSum("Rappen {$i}", chf('0.01'), 'UN', '3200'), range(1, $n)),
    array_map(fn($a) => LineDraft::lumpSum('Grösser', chf($a), 'UN', '3200'), $more),
), priceMode: PriceMode::Gross);
$countInvoicesBefore = $count('invoice');
$e = caught(fn() => $service($emL14)->invoice($rappen(100)), InvoiceRefusedException::class);
check('L32 100 × 0.01 gross (tax 0.07 on 1.00): no line can carry a share and keep a positive net → REFUSED at issue (line-tax-share), no number drawn',
    $e?->reason === InvoiceRefusedException::LINE_TAX_SHARE && str_contains($e->getMessage(), '0.07') && $e->getPrevious() instanceof \Z77\Module\Debtor\Invoicing\NoTaxShareException
    && $count('invoice') === $countInvoicesBefore);
$small = $service($emL14)->invoice($rappen(20, '0.30'));
check('L33 20 × 0.01 + 0.30 gross (0.50, tax 0.04) issues: the shares the Rappen lines cannot carry move to the largest line',
    $small->getGrossTotal()->toDecimal() === '0.50' && $small->getTaxTotal()->toDecimal() === '0.04' && count($small->getLines()) === 21);
$service($wireDi())->finalize([$at($small->getId())]);
$sl  = $entryLines((string) $readInvoice($small->getId())->getLedgerEntryRef());
$net = array_values(array_filter($sl, fn($r) => $r['account_number'] === '3200'));
check('L34 … its posting: all 21 revenue lines present with net > 0, Σ tax_amount of the net lines = 0.04 = the VAT line, balanced',
    count($net) === 21 && array_reduce($net, fn($ok, $r) => $ok && Money::fromDecimal($r['credit'], 'CHF')->isPositive(), true)
    && $sumOf($net, 'tax_amount')->toDecimal() === '0.04' && array_values(array_filter($sl, fn($r) => $r['account_number'] === '2200'))[0]['credit'] === '0.04'
    && $sumOf($sl, 'debit')->equals($sumOf($sl, 'credit')) && $sumOf($net, 'credit')->add($sumOf($net, 'tax_amount'))->toDecimal() === '0.50');
check('L35 TaxShares::distribute() directly: [0.01 × 7, 0.20] gross at 8.1 % (tax on 0.27 = 0.02) → the small lines keep 0, the largest takes 0.02; in NET mode nothing moves',
    (function (): bool {
        $amounts = array_merge(array_fill(0, 7, chf('0.01')), [chf('0.20')]);
        $gross   = \Z77\Module\Debtor\Invoicing\TaxShares::distribute($amounts, chf('0.02'), 810, PriceMode::Gross);
        $netMode = \Z77\Module\Debtor\Invoicing\TaxShares::distribute([chf('0.01'), chf('0.01')], chf('0.02'), 810, PriceMode::Net);
        return count($gross) === 8 && $gross[7]->toDecimal() === '0.02' && array_reduce(array_slice($gross, 0, 7), fn($ok, Money $s) => $ok && $s->isZero(), true)
            && $netMode[0]->toDecimal() === '0.01' && $netMode[1]->toDecimal() === '0.01';
    })());


// ── M. source guards ─────────────────────────────────────────────────────

echo "M. Source guards for part 2\n";
check('M1 Invoice has NO setter — the header is written whole by issue() / reissue(), the state by finalize()',
    array_filter(get_class_methods(Invoice::class), fn($m) => str_starts_with($m, 'set')) === []
    && array_filter(get_class_methods(InvoiceLine::class), fn($m) => str_starts_with($m, 'set')) === []
    && array_filter(get_class_methods(InvoiceTax::class), fn($m) => str_starts_with($m, 'set')) === []);
check('M2 InvoicingService has no delete, cancel, void or remove — a posted document is corrected by a credit note (plan §1)',
    array_filter(get_class_methods(InvoicingService::class), fn($m) => preg_match('/delete|cancel|void|remove|storno|reverse/i', $m)) === []);
check('M3 the debtor posting DTO is validated on construction: unbalanced → refused; a line naming neither account nor category → refused; both → refused',
    throws(fn() => new DebtorPostingRequest(day('2026-01-01'), 'x', 'invoice', '1', 'k', [DebtorPostingLine::debit('1100', chf('1.00')), DebtorPostingLine::credit('3200', chf('0.99'))]), \InvalidArgumentException::class)
    && throws(fn() => new DebtorPostingLine(null, null, chf('1.00'), chf('0.00')), \InvalidArgumentException::class)
    && throws(fn() => new DebtorPostingLine('1100', 'standard', chf('1.00'), chf('0.00')), \InvalidArgumentException::class)
    && (new DebtorPostingRequest(day('2026-01-01'), 'x', 'invoice', '1', 'k', [DebtorPostingLine::debit('1100', chf('1.00')), DebtorPostingLine::vatCredit('standard', chf('1.00'))]))->currency() === 'CHF');
check('M3b nothing in stock (review 2026-09-23): no toArray/lines on the snapshot, no findByNumber, no hasTax/total on the DTOs, no state()/getExchangeRate() on Invoice — exchange_rate stays a COLUMN',
    !method_exists(AddressSnapshot::class, 'toArray') && !method_exists(AddressSnapshot::class, 'lines') && !method_exists(InvoiceRepository::class, 'findByNumber')
    && !method_exists(DebtorPostingLine::class, 'hasTax') && !method_exists(DebtorPostingRequest::class, 'total') && !method_exists(Invoice::class, 'state') && !method_exists(Invoice::class, 'getExchangeRate')
    && array_key_exists('exchange_rate', $invoiceRow($inv1->getId())) && $invoiceRow($inv1->getId())['currency'] === 'CHF');
check('M4 no Accounting or Invoicing class but the adapter names financial; the interface signature is the plan\'s (§6.6)',
    !str_contains(file_get_contents($package . '/src/Accounting/AccountingGateway.php'), 'Financial')
    && !str_contains(file_get_contents($package . '/src/Services/InvoicingService.php'), 'Financial')
    && !str_contains(file_get_contents($package . '/src/Invoicing/PostingBuilder.php'), 'Financial')
    && str_contains(file_get_contents($package . '/src/Accounting/AccountingGateway.php'), 'public function post(PostingRequest $request): ?string;'));
check('M5 the AddressSnapshot is an embeddable with no reference to address or contact_address (§4a: copied, never referenced)',
    str_contains(file_get_contents($package . '/src/Entities/AddressSnapshot.php'), '#[ORM\\Embeddable]')
    && !str_contains(file_get_contents($package . '/src/Entities/Invoice.php'), 'ContactAddress') && !str_contains(file_get_contents($package . '/src/Entities/Invoice.php'), 'targetEntity: Address::class'));
check('M6 the migration seeds the two ranges with the statement create() runs and drops them only at 0', (function () use ($package): bool {
    $s = file_get_contents($package . '/res/migrations/Version20260923043935.php');
    return str_contains($s, "VALUES ('invoice', 0), ('credit-note', 0) ON DUPLICATE KEY UPDATE last_number = last_number") && str_contains($s, 'AND last_number = 0');
})());
check('M7 InvoicingService::finalize() locks every row BEFORE any number is drawn (id order) — the source keeps that order',
    strpos(file_get_contents($package . '/src/Services/InvoicingService.php'), 'lockForUpdate($id)') < strpos(file_get_contents($package . '/src/Services/InvoicingService.php'), '$gateway->post($request)'));
check('M8 the migration says what a development rollback does to a drawn range (leaves the row; up() is idempotent)',
    str_contains(file_get_contents($package . '/res/migrations/Version20260923043935.php'), 'idempotent'));


// ── result ───────────────────────────────────────────────────────────────

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
