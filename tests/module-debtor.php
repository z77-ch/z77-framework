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
 *   - (H) the `DebtorAccounts` accessor — since owner decision E2
 *     (2026-09-23) reading the MANDATOR record (`z77/module-mandator`), no
 *     longer `debtorConfig → debtorAccounts`: the KMU start values, a
 *     leftover config key refused loudly, no mandator / an empty field, and
 *     an account that became a group / inactive / unknown after it was saved
 *     — each refused at the point of use with a German message naming the
 *     key and the mandator;
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
 *   - (M) source guards: exactly ONE class names module-financial (the soft
 *     account check moved to module-mandator with E2), the
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
use Z77\Module\Debtor\Invoicing\QrBill;
use Z77\Module\Debtor\Entities\PaymentSnapshot;
use Z77\Module\Debtor\Invoicing\QrReference;
use Z77\Module\Debtor\Services\Creditor;
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
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Mandator\Services\MandatorAccounts;
use Z77\Module\Mandator\Services\MandatorService;
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
// seeds. debtor needs contact (the party), mandator (the account settings
// since E2) and, for the SOFT account check, financial — which a project
// may leave out, and section H proves that too.
$packages = [
    'Vat'       => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-vat')),
    'Contact'   => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-contact')),
    'Mandator'  => str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-mandator')),
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
// The kernel's shared namespace: the PDF partials `pdf/table` / `pdf/addressWindow` the invoice layout draws with (P3D).
$kernelShared = str_replace('\\', '/', realpath(__DIR__ . '/../packages/kernel/shared'));
$namespaces  .= "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$kernelShared}']],\n";
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n{$namespaces}]];");
$writeModules = function (bool $withFinancial) use ($write): void {
    $modules = $withFinancial ? "'vat' => [], 'contact' => [], 'mandator' => [], 'financial' => [], 'debtor' => []" : "'vat' => [], 'contact' => [], 'mandator' => [], 'debtor' => []";
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
check('A0 the module config announces exactly the EIGHT Doctrine entities (profile, the document and its lines and taxes, the payment and its allocations, the bank message and its transactions) — the three master-data types are file-based',
    $config?->get('doctrineEntities') === [DebtorProfile::class, Invoice::class, InvoiceLine::class, InvoiceTax::class, \Z77\Module\Debtor\Entities\Payment::class, \Z77\Module\Debtor\Entities\PaymentAllocation::class, \Z77\Module\Debtor\Entities\BankMessage::class, \Z77\Module\Debtor\Entities\BankTransaction::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Debtor\\Migrations', ($dirs['Z77\\Module\\Debtor\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
$executed = $db->fetchFirstColumn('SELECT version FROM schema_migration');
check('A4 … both debtor migrations ran in ONE run across the modules (timestamp order; the newest is the mandator\'s)',
    str_contains($out, 'Migrating up to Z77\\Module\\')
    && in_array('Z77\\Module\\Debtor\\Migrations\\Version20260922173918', $executed, true) && in_array('Z77\\Module\\Debtor\\Migrations\\Version20260923043935', $executed, true)
    && in_array('Z77\\Module\\Debtor\\Migrations\\Version20261006100000', $executed, true));
check('A5 debtor_profile, invoice, invoice_line and invoice_tax exist next to contact\'s, mandator\'s and financial\'s tables',
    array_diff(['debtor_profile', 'invoice', 'invoice_line', 'invoice_tax', 'mandator'], $tables()) === []);
$rangeOf = fn(string $name) => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$name]);
check('A5b the ranges invoice and credit-note exist at 0, customer at 999 (the first customer number is 1000, owner 2026-10-06) — created by the migrations ahead of the first draw (DOCTRINE-NR-003), nothing consumed',
    (string) $rangeOf('invoice') === '0' && (string) $rangeOf('credit-note') === '0' && (string) $rangeOf('customer') === '999');
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
check('A9 the unique contact index, the unique customer-number index and the payment-terms index carry our names',
    in_array('uniq_debtor_profile_contact', $indexes, true) && in_array('uniq_debtor_profile_number', $indexes, true) && in_array('idx_debtor_profile_terms', $indexes, true));
[$code, $out] = $run(['command' => 'migrate']);
check('A10 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Debtor\\Migrations']);
check('A11 diff after migrate reports NO change — mapping and migration agree (embedded address and payment part, money and decimal columns included)' . (str_contains($out, 'No changes detected') ? '' : ' — ' . trim($out)), $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 6);
check('A12 all six migrations are expand-only: no DROP outside down()', array_reduce(glob($package . '/res/migrations/Version*.php'), function ($ok, $f) {
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
// The mandator (E2): the account settings debtor posts with live on it — created ONCE here
// with the KMU start values, the way the backend screen's first save does.
$mandator = MandatorAccounts::prefilled();
$mandator->mapFromArray(['name' => 'Harness AG', 'country' => 'CH', 'zip' => '8000', 'city' => 'Zürich']);
(new MandatorService($em4))->save($mandator);
$em4 = $wireDi();
$em4 = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;

$bank = new PaymentTarget(['code' => 'bank', 'label' => 'Bank', 'iban' => 'CH93 0076 2011 6238 5295 7', 'account_number' => '1020']);
$masterData->saveTarget($bank);
check('D15 a target saves; the IBAN is stored normalized', $bank->getId() !== null && $bank->getIban() === 'CH9300762011623852957');
check('D16 … and the collection file now exists', is_file($base . '/data/framework/debtor/payment_targets.json'));
$qr = new PaymentTarget(['code' => 'qr', 'label' => 'QR-Konto', 'qr_iban' => $lower, 'account_number' => '1020']);
$masterData->saveTarget($qr);
check('D17 a QR-IBAN target says so on the entity (the second IBAN field, P3 part 3)', $qr->hasQrIban() && !$bank->hasQrIban() && $qr->getIban() === '' && $qr->getQrIban() === $lower);

$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'bad', 'label' => 'x', 'iban' => 'CH9300762011623852975', 'account_number' => '1020'])));
check('D18 a wrong check digit is refused with the check-digit message', $v?->hasFieldError('iban') === true && str_contains($v->getFieldError('iban'), 'Prüfziffern'));
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'de', 'label' => 'x', 'iban' => 'DE89370400440532013000', 'account_number' => '1020'])));
check('D19 a valid NON-Swiss IBAN is refused — a QR-bill names a CH / LI account', $v?->hasFieldError('iban') === true && str_contains($v->getFieldError('iban'), 'liechtensteinische'));
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'dup', 'label' => 'x', 'iban' => 'CH9300762011623852957', 'account_number' => '1020'])));
check('D20 the same IBAN twice is refused — a payment would be ambiguous', $v?->hasFieldError('iban') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'letters', 'label' => 'x', 'iban' => 'CH9300762011623852957', 'account_number' => '10A0'])));
check('D21 a non-numeric ledger account is refused', $v?->hasFieldError('account_number') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'group', 'label' => 'x', 'qr_iban' => $upper, 'account_number' => '100'])));
check('D22 a GROUP account (100) is refused — soft check against module-financial', $v?->hasFieldError('account_number') === true);
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'ghost', 'label' => 'x', 'qr_iban' => $upper, 'account_number' => '9999999'])));
check('D23 an unknown account is refused', $v?->hasFieldError('account_number') === true);

$kasse = $em4->getRepository(Account::class)->findOneBy(['number' => '1000']);
(new AccountService($em4))->setActive($kasse, false);
$em4 = $wireDi();
$masterData = new DebtorMasterData($em4);
$badTarget  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;
$v = $badTarget(fn() => $masterData->saveTarget(new PaymentTarget(['code' => 'inactive', 'label' => 'x', 'qr_iban' => $upper, 'account_number' => '1000'])));
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
$unregisteredTarget = new PaymentTarget(['code' => 'unreg', 'label' => 'Ohne Buchhaltung', 'qr_iban' => $upper, 'account_number' => '9999999']);
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
check('F2 a profile saves for a contact — and draws customer number 1000 (the first) from the range `customer` in the same unit of work',
    $profile->getId() !== null && $count('debtor_profile') === 1 && $profile->getCustomerNumber() === 1000 && (string) $rangeOf('customer') === '1000');
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
// The raw rows carry numbers far above the range so no later draw collides with them.
$raced = $db->executeStatement('INSERT INTO debtor_profile (customer_number, payment_terms_code, dunning_block, active, contact_id) VALUES (999001, ?, 0, 1, ?)', ['net-30', $anna->getId()]);
check('F10 a profile for another contact is fine (the unique index is per contact, not global)', $raced === 1 && $count('debtor_profile') === 2);
$e = caught(fn() => $db->executeStatement('INSERT INTO debtor_profile (customer_number, payment_terms_code, dunning_block, active, contact_id) VALUES (999002, ?, 0, 1, ?)', ['net-30', $anna->getId()]),
    \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
check('F11 the unique index decides under a race — a second row for one contact is impossible in the schema', $e !== null && str_contains($e->getMessage(), 'uniq_debtor_profile_contact') && $count('debtor_profile') === 2);
$third = new Contact(['kind' => 'person', 'first_name' => 'Nora', 'last_name' => 'Dritte', 'language' => 'de']);
$contacts->save($third);
$e = caught(fn() => $db->executeStatement('INSERT INTO debtor_profile (customer_number, payment_terms_code, dunning_block, active, contact_id) VALUES (999001, ?, 0, 1, ?)', ['net-30', $third->getId()]),
    \Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
check('F11b … and so is a second row with one customer number (uniq_debtor_profile_number)', $e !== null && str_contains($e->getMessage(), 'uniq_debtor_profile_number') && $count('debtor_profile') === 2);
$twice = new DebtorProfile(['payment_terms_code' => 'net-30', 'customer_number' => 77]);
check('F11c the customer number is server-controlled: no setter, a body key `customer_number` is ignored by mapFromArray(), assignCustomerNumber() takes one number once and refuses 0',
    !method_exists(DebtorProfile::class, 'setCustomerNumber') && $twice->getCustomerNumber() === 0
    && throws(fn() => $twice->assignCustomerNumber(0), \InvalidArgumentException::class)
    && (function () use ($twice): bool { $twice->assignCustomerNumber(5); return $twice->getCustomerNumber() === 5; })()
    && throws(fn() => $twice->assignCustomerNumber(6), \LogicException::class) && $twice->getCustomerNumber() === 5);

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

echo "H. DebtorAccounts — the accessor reads the MANDATOR record (E2) and refuses at the point of use\n";
$em23     = $wireDi();
$settings = new DebtorAccounts($em23);
$configured = array_combine(DebtorAccounts::KEYS, array_map(fn($k) => $settings->number($k), DebtorAccounts::KEYS));
check('H1 the KMU start values are what the mandator carries — rounding has its OWN account (owner, 2026-09-22)', $configured === [
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
check('H4 postableNumber() answers for every key while the chart is sound', array_reduce(DebtorAccounts::KEYS, fn($ok, $k) => $ok && $settings->postableNumber($k) !== '', true));
check('H5 status() reports no error for any key', array_reduce($settings->status(), fn($ok, $row) => $ok && $row['error'] === null, true));
check('H6 an unknown key is a programming error, not a configuration one', throws(fn() => $settings->number('nope'), \InvalidArgumentException::class));
check('H6b the package config carries NO debtorAccounts any more — the record is the one place (Rule 2)',
    DI::getModuleManager()->getModuleConfig('debtor')?->has(DebtorAccounts::LEGACY_CONFIG_KEY) === false
    && !str_contains(file_get_contents($package . '/src/App/Config/debtorConfig.inc.php'), "'debtorAccounts' =>"));

// A project override that STILL carries the pre-E2 key (BOOT-CONFIG-001: a full copy made before the move).
// FileFinder memoizes the resolved path, so the cache goes with the file.
$writeOverride = function (?string $php) use ($write, $wireDi, $base, $rm): UnifiedEntityManager {
    $file = $base . '/override/module/debtor/src/App/Config/debtorConfig.inc.php';
    if ($php === null) { @unlink($file); } else { $write('override/module/debtor/src/App/Config/debtorConfig.inc.php', $php); }
    $rm($base . '/var/cache');

    return $wireDi();
};
$emOverride = $writeOverride("<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class], 'debtorAccounts' => ['receivable' => '1100']];");
$overrideActive = DI::getModuleManager()->getModuleConfig('debtor')?->has('debtorAccounts') === true;
if ($overrideActive) {
    $e = caught(fn() => (new DebtorAccounts($emOverride))->number('receivable'), \UnexpectedValueException::class);
    check('H7 a leftover `debtorAccounts` in a project override is REFUSED loudly with a GERMAN sentence naming the move — never read as a second source',
        $e !== null && str_contains($e->getMessage(), 'debtorAccounts') && str_contains($e->getMessage(), 'Mandant'));
    check('H8 … status() carries that refusal on every row and notice() hands it to the screens as ONE band instead of a 500', array_reduce((new DebtorAccounts($emOverride))->status(), fn($ok, $row) => $ok && $row['error'] !== null && $row['number'] === '', true)
        && str_contains((string) (new DebtorAccounts($emOverride))->notice(), 'debtorAccounts'));
} else {
    check('H7 a project override replaces the package config (BOOT-CONFIG-001) — skipped, the override did not take effect', false);
}
$em24 = $writeOverride(null);
check('H9 without the override the mandator answers again, and there is no band', (new DebtorAccounts($em24))->number('loss') === '3805' && (new DebtorAccounts($em24))->number('rounding') === '3809' && (new DebtorAccounts($em24))->notice() === null);

// An EMPTY field on the mandator — refused at the point of use, naming the key and where it is set.
$mandatorRow = $em24->getRepository(Mandator::class)->theOne();
(new MandatorService($em24))->update($mandatorRow, ['account_loss' => '']);
$em24 = $wireDi();
$e = caught(fn() => (new DebtorAccounts($em24))->number('loss'), AccountNotConfiguredException::class);
check('H10 an account left EMPTY on the mandator is refused AT THE POINT OF USE, in German, naming the key, its label and the mandator',
    $e instanceof AccountNotConfiguredException && $e->key === 'loss' && str_contains($e->getMessage(), 'Debitorenverlust') && str_contains($e->getMessage(), 'Mandant'));
check('H10b … status() reports it while the other keys still answer', (new DebtorAccounts($em24))->status()['loss']['error'] !== null
    && (new DebtorAccounts($em24))->status()['receivable']['error'] === null && (new DebtorAccounts($em24))->number('receivable') === '1100');
(new MandatorService($em24))->update($em24->getRepository(Mandator::class)->theOne(), ['account_loss' => '3805']);

// Accounts that became a GROUP / unknown / inactive AFTER they were saved: the validator would refuse
// them on a save, so they are written past it in SQL — the chart moved under the record, and the
// reader must still refuse at the point of use (E2: never a silent substitute).
$db->executeStatement("UPDATE mandator SET account_receivable = '100', account_discount = '9999999', account_loss = '1000'");
$emH = $wireDi();
(new AccountService($emH))->setActive($emH->getRepository(Account::class)->findOneBy(['number' => '1000']), false);
$emH      = $wireDi();
$settings = new DebtorAccounts($emH);
$e = caught(fn() => $settings->postableNumber('receivable'), AccountNotConfiguredException::class);
check('H11 a GROUP account is refused at the point of use, in German, naming the key and the mandator',
    $e instanceof AccountNotConfiguredException && str_contains($e->getMessage(), 'Gruppe') && str_contains($e->getMessage(), 'Debitoren-Sammelkonto') && str_contains($e->getMessage(), 'Mandant'));
check('H12 an unknown and an inactive account are refused the same way',
    throws(fn() => $settings->postableNumber('discount'), AccountNotConfiguredException::class)
    && throws(fn() => $settings->postableNumber('loss'), AccountNotConfiguredException::class));
check('H13 the two sound keys still answer', $settings->postableNumber('rounding') === '3809' && $settings->postableNumber('dunningFee') === '6950');
$status = $settings->status();
check('H14 status() marks exactly the three broken keys and keeps their stored numbers visible',
    $status['receivable']['error'] !== null && $status['discount']['error'] !== null && $status['loss']['error'] !== null
    && $status['rounding']['error'] === null && $status['dunningFee']['error'] === null
    && $status['receivable']['number'] === '100');
(new AccountService($emH))->setActive($emH->getRepository(Account::class)->findOneBy(['number' => '1000']), true);
$db->executeStatement("UPDATE mandator SET account_receivable = '1100', account_discount = '3800', account_loss = '3805'");
$em24 = $wireDi();
check('H15 restored: the KMU values answer again', (new DebtorAccounts($em24))->number('loss') === '3805' && (new DebtorAccounts($em24))->postableNumber('receivable') === '1100');

// No mandator at all — the record removed in SQL (nothing in the framework deletes it).
$mandatorBackup = $db->fetchAssociative('SELECT * FROM mandator');
$db->executeStatement('DELETE FROM mandator');
$emNoMandator = $wireDi();
$e = caught(fn() => (new DebtorAccounts($emNoMandator))->number('receivable'), AccountNotConfiguredException::class);
check('H15b WITHOUT a mandator every key is refused at the point of use, naming the missing mandator — never a fatal',
    $e instanceof AccountNotConfiguredException && str_contains($e->getMessage(), 'kein Mandant') && $e->key === 'receivable'
    && array_reduce((new DebtorAccounts($emNoMandator))->status(), fn($ok, $row) => $ok && $row['error'] !== null && $row['number'] === '', true));
$db->insert('mandator', $mandatorBackup);
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
check('I6 every master-data screen has its list template, its edit template and its header slot; the document screens (P3 part 3) add six, the payment form and the delete confirmation (P4 part 1) two, the bank import list and detail (P4 part 2) two', count($templates) === 22);
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
check('I16 exactly ONE class names module-financial — the port adapter (ADR-040 decision 5); the soft account check lives in module-mandator since E2',
    $namingFinancial === ['LedgerAccountingGateway.php'] && !is_file($package . '/src/Services/LedgerAccountCheck.php'));


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
    && throws(fn() => $finalEntity->reissue($blankSnapshot, [], [], \Z77\Module\Debtor\Entities\PaymentSnapshot::none(), 'x', new \DateTimeImmutable()), \LogicException::class));
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
$fullConfig = fn(string $gateway) => "<?php return ['viewArea' => false, 'doctrineEntities' => [\\Z77\\Module\\Debtor\\Entities\\DebtorProfile::class, \\Z77\\Module\\Debtor\\Entities\\Invoice::class, \\Z77\\Module\\Debtor\\Entities\\InvoiceLine::class, \\Z77\\Module\\Debtor\\Entities\\InvoiceTax::class], {$gateway}];";
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
check('M3b nothing in stock (review 2026-09-23): no toArray on the snapshot, no hasTax/total on the DTOs, no state()/getExchangeRate() on Invoice — exchange_rate stays a COLUMN (lines() and findByNumber came back with their callers in P3 part 3)',
    !method_exists(AddressSnapshot::class, 'toArray')
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
check('M9 the customer-number migration numbers EXISTING profiles in id order before the unique index, seeds the range `customer` with the create() statement, raises it with GREATEST and drops it only at 0', (function () use ($package): bool {
    $s = file_get_contents($package . '/res/migrations/Version20261006100000.php');
    return strpos($s, 'ROW_NUMBER() OVER (ORDER BY id)') < strpos($s, 'CREATE UNIQUE INDEX uniq_debtor_profile_number')
        && str_contains($s, "VALUES ('customer', 0) ON DUPLICATE KEY UPDATE last_number = last_number")
        && str_contains($s, 'GREATEST(last_number, (SELECT COALESCE(MAX(customer_number), " . (self::FIRST_NUMBER - 1) . ") FROM debtor_profile))')
        && str_contains($s, 'FIRST_NUMBER = 1000')
        && str_contains($s, "WHERE name = 'customer' AND last_number = 0");
})());


// ── N. the year close asks debtor (P5 part 1) ───────────────────────────

echo "N. The fiscal-year close asks debtor through the open-work registry (scope period-close): a document in `invoicing` dated in the year BLOCKS\n";
$wireDi();
$ycAsk = fn(string $from, string $to) => \Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks::fromModules(DI::getModuleManager())
    ->ask('period-close', ['fiscalYear' => '2026', 'from' => day($from), 'to' => day($to)]);
$inInvoicing = (int) $db->fetchOne("SELECT COUNT(*) FROM invoice WHERE state = 'invoicing' AND invoice_date BETWEEN '2026-01-01' AND '2026-12-31'");
$open2026    = $ycAsk('2026-01-01', '2026-12-31');
check('N1 debtorConfig registers InvoicingInProgressCheck under `period-close`; for 2026 every document still in invoicing is a BLOCKING finding (named, dated, referenced invoice:{id}) — no warnings',
    (DI::getModuleManager()->getModuleConfig('debtor')?->get('openWorkChecks') ?? []) === ['period-close' => [\Z77\Module\Debtor\Close\InvoicingInProgressCheck::class, \Z77\Module\Debtor\Close\UnbookedTransactionsCheck::class]]
    && $inInvoicing > 0 && $inInvoicing <= \Z77\Module\Debtor\Close\InvoicingInProgressCheck::LIST_LIMIT
    && $open2026->isBlocked() && count($open2026->blocking()) === $inInvoicing && $open2026->warnings() === []
    && str_contains($open2026->blocking()[0]->message, 'in Fakturierung') && str_starts_with($open2026->blocking()[0]->reference, 'invoice:')
    && preg_match('/^(Rechnung|Gutschrift) \d+ vom \d\d\.\d\d\.2026 /', $open2026->blocking()[0]->message) === 1);
$inv1Day = (string) $db->fetchOne('SELECT invoice_date FROM invoice WHERE id = ?', [$inv1->getId()]);
check('N2 a FINAL document does not count (inv1, final since L1: its day yields only the documents still in invoicing); a range without open documents is «nothing open»',
    $stateOf($inv1->getId()) === 'final'
    && count($ycAsk($inv1Day, $inv1Day)->blocking()) === (int) $db->fetchOne("SELECT COUNT(*) FROM invoice WHERE state = 'invoicing' AND invoice_date = ?", [$inv1Day])
    && !in_array('invoice:' . $inv1->getId(), array_map(fn($f) => $f->reference, $ycAsk($inv1Day, $inv1Day)->blocking()), true)
    && $ycAsk('2025-01-01', '2025-12-31')->isEmpty());
check('N3 the check refuses parameters that are not the scope\'s (from / to as DateTimeImmutable)', throws(fn() => iterator_to_array((new \Z77\Module\Debtor\Close\InvoicingInProgressCheck())->check('period-close', ['from' => '2026-01-01'])), \InvalidArgumentException::class));
$yearId2026 = (int) $db->fetchOne("SELECT id FROM fiscal_year WHERE code = '2026'");
$refusedClose = caught(fn() => (new \Z77\Module\Financial\Services\FiscalYearCloseService($wireDi(), 'tester'))->close($yearId2026), \Z77\Module\Financial\Services\FiscalYearCloseRefusedException::class);
check('N4 financial\'s FiscalYearCloseService (the registry of the registered modules) refuses to close 2026 — blocked by debtor\'s findings; no period closed, no protocol row',
    $refusedClose?->reason === 'blocked' && count($refusedClose->openWork?->blocking() ?? []) >= $inInvoicing
    && $db->fetchFirstColumn("SELECT DISTINCT p.state FROM fiscal_period p JOIN fiscal_year y ON y.id = p.fiscal_year_id WHERE y.code = '2026'") === ['open']
    && (int) $db->fetchOne('SELECT COUNT(*) FROM fiscal_year_close_log') === 0);
$manyBefore = (int) $db->fetchOne("SELECT COUNT(*) FROM invoice WHERE state = 'invoicing' AND invoice_date BETWEEN '2026-11-20' AND '2026-11-30'");
for ($i = 0; $i < 12; $i++) {
    $service($wireDi())->invoice($oneLine($mid, '2026-11-2' . min($i, 8), '1.000', '10.00'));
}
$many = $ycAsk('2026-11-20', '2026-11-30');
check('N5 many open documents: the first ' . \Z77\Module\Debtor\Close\InvoicingInProgressCheck::LIST_LIMIT . ' by date are named one by one, the rest counted in one more finding',
    count($many->blocking()) === \Z77\Module\Debtor\Close\InvoicingInProgressCheck::LIST_LIMIT + 1 && str_contains(array_reverse($many->blocking())[0]->message, 'und ' . ($manyBefore + 12 - \Z77\Module\Debtor\Close\InvoicingInProgressCheck::LIST_LIMIT) . ' weitere'));


// ═════════════════════════════════════════════════════════════════════════
// P3 part 3 — the payment part (QR-bill data), the document screens
// ═════════════════════════════════════════════════════════════════════════
//
// State inherited: payment targets `bank` (plain IBAN CH93…), `qr` (QR-IBAN,
// IID 30000) and `unreg` (QR-IBAN, IID 31999), all active; the mandator
// «Harness AG», 8000 Zürich, CH, no street; Müller (active, profile), the
// fiscal year 2026 open, financial registered, the ledger gateway.

echo "P3C. The QR reference, the character set, the payment target's two IBAN fields and creditor block\n";

/** The QR-bill data structure checked INDEPENDENTLY of QrBill (SIX Implementation Guidelines v2.x): order, fixed values, lengths. */
$specErrors = static function (string $payload): array {
    $errors = [];
    if (str_ends_with($payload, "\n") || str_contains($payload, "\r")) { $errors[] = 'separator'; }
    $e = explode("\n", $payload);
    if (count($e) !== 31) { return ['count ' . count($e)]; }
    if ([$e[0], $e[1], $e[2]] !== ['SPC', '0200', '1']) { $errors[] = 'header'; }
    if (!preg_match('/^(CH|LI)\d{19}$/', $e[3])) { $errors[] = 'iban'; }
    $address = static function (array $f, bool $optional) use (&$errors): void {
        if ($optional && implode('', $f) === '') { return; }
        if ($f[0] !== 'S') { $errors[] = 'adrtp'; }
        foreach ([1 => 70, 2 => 70, 3 => 16, 4 => 16, 5 => 35] as $i => $max) { if (mb_strlen($f[$i]) > $max) { $errors[] = "len {$i}"; } }
        if ($f[1] === '' || $f[4] === '' || $f[5] === '' || !preg_match('/^[A-Z]{2}$/', $f[6])) { $errors[] = 'address required'; }
    };
    $address(array_slice($e, 4, 7), false);
    if (implode('', array_slice($e, 11, 7)) !== '') { $errors[] = 'ultimate creditor not empty'; }
    if (!preg_match('/^\d{1,9}\.\d{2}$/', $e[18]) || strlen($e[18]) > 12) { $errors[] = 'amount'; }
    if (!in_array($e[19], ['CHF', 'EUR'], true)) { $errors[] = 'currency'; }
    $address(array_slice($e, 20, 7), true);
    if ($e[27] === 'QRR') {
        if (!preg_match('/^\d{27}$/', $e[28])) { $errors[] = 'qrr shape'; }
        $carry = 0; foreach (str_split(substr($e[28], 0, 26)) as $d) { $carry = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5][($carry + (int) $d) % 10]; }
        if ((10 - $carry) % 10 !== (int) substr($e[28], -1)) { $errors[] = 'qrr check digit'; }
        if (!in_array((int) substr($e[3], 4, 5), range(30000, 31999), true)) { $errors[] = 'qrr without qr-iban'; }
    } elseif ($e[27] === 'NON') {
        if ($e[28] !== '') { $errors[] = 'non with reference'; }
        if (in_array((int) substr($e[3], 4, 5), range(30000, 31999), true)) { $errors[] = 'qr-iban with non'; }
    } else { $errors[] = 'reference type'; }
    if (mb_strlen($e[29]) > 140) { $errors[] = 'message'; }
    if ($e[30] !== 'EPD') { $errors[] = 'trailer'; }
    if (mb_strlen($payload) > 997) { $errors[] = 'payload length'; }
    return $errors;
};

check('P3C1 the QR reference check digit is modulo 10 recursive — the SIX example 21 00000 00003 13947 14300 0901|7',
    QrReference::checkDigit('21000000000313947143000901') === 7 && QrReference::isValid('210000000003139471430009017')
    && !QrReference::isValid('210000000003139471430009018') && !QrReference::isValid('21000000000313947143000901') && !QrReference::isValid(str_repeat('0', 27)));
check('P3C2 forDocument(): the wdv-630 layout — ten zeros (the bank\'s place), the customer number in 6, the document number in 10, the check digit; printed in blocks of five from the right',
    QrReference::forDocument(1001, 12) === '0000000000' . '001001' . '0000000012' . QrReference::checkDigit('00000000000010010000000012') && strlen(QrReference::forDocument(1001, 12)) === 27
    && QrReference::isValid(QrReference::forDocument(999999, 9999999999)) && QrReference::format('210000000003139471430009017') === '21 00000 00003 13947 14300 09017'
    && QrReference::format(QrReference::forDocument(1001, 12)) === '00 00000 00000 10010 00000 0012' . QrReference::checkDigit('00000000000010010000000012')
    && throws(fn() => QrReference::forDocument(0, 12), \InvalidArgumentException::class) && throws(fn() => QrReference::forDocument(1, 0), \InvalidArgumentException::class)
    && throws(fn() => QrReference::forDocument(1000000, 12), \InvalidArgumentException::class) && throws(fn() => QrReference::forDocument(1, 10000000000), \InvalidArgumentException::class));
check('P3C3 the character set (v2.3 extended Latin): umlauts, ß, Ș and € pass; a line break, a tab and CJK do not',
    QrBill::isAllowedText('Müller & Söhne AG, Straße 1') && QrBill::isAllowedText('Ștefan €') && !QrBill::isAllowedText("a\nb") && !QrBill::isAllowedText("a\tb") && !QrBill::isAllowedText('日本'));

$emP = $wireDi();
$mdP = new DebtorMasterData($emP);
$tv  = fn(callable $fn): ?PaymentTargetValidator => ($e = caught($fn, InvalidMasterDataException::class)) instanceof InvalidMasterDataException ? $e->validator : null;
$v1 = $tv(fn() => $mdP->saveTarget(new PaymentTarget(['code' => 'swap1', 'label' => 'x', 'iban' => $withIid('30500'), 'qr_iban' => '', 'account_number' => '1020'])));   // both keys, as a form posts them
$v2 = $tv(fn() => $mdP->saveTarget(new PaymentTarget(['code' => 'swap2', 'label' => 'x', 'qr_iban' => $withIid('00500'), 'account_number' => '1020'])));
$v3 = $tv(fn() => $mdP->saveTarget(new PaymentTarget(['code' => 'none', 'label' => 'x', 'account_number' => '1020'])));
$v4 = $tv(fn() => $mdP->saveTarget(new PaymentTarget(['code' => 'dupq', 'label' => 'x', 'qr_iban' => $lower, 'account_number' => '1020'])));
check('P3C4 two IBAN fields (owner 2026-09-23): a QR-IBAN in the IBAN field and a plain IBAN in the QR-IBAN field are refused by KIND; neither → «mindestens eine»; a QR-IBAN already on another target → refused',
    str_contains((string) $v1?->getFieldError('iban'), 'QR-IBAN') && str_contains((string) $v2?->getFieldError('qr_iban'), 'keine QR-IBAN')
    && str_contains((string) $v3?->getFieldError('iban'), 'Mindestens eine') && str_contains((string) $v4?->getFieldError('qr_iban'), '«qr»'));
$v5 = $tv(fn() => $mdP->saveTarget(new PaymentTarget(['code' => 'long', 'label' => 'x', 'qr_iban' => $withIid('30600'), 'account_number' => '1020', 'holder_name' => str_repeat('A', 71), 'holder_city' => "Zü\nrich", 'holder_country' => 'CHE'])));
check('P3C5 the creditor block keeps the QR lengths and characters: a name of 71, a city with a line break and a three-letter country are refused',
    $v5?->hasFieldError('holder_name') === true && $v5->hasFieldError('holder_city') && $v5->hasFieldError('holder_country'));
$both = new PaymentTarget(['code' => 'both', 'label' => 'Beide', 'iban' => $withIid('00762'), 'qr_iban' => $withIid('30001'), 'account_number' => '1020',
    'holder_name' => 'Peter u/o Regina Ruepp', 'holder_country' => 'li']);
$mdP->saveTarget($both);
$mandatorP = Creditor::mandator($emP);
$cQr   = Creditor::of($emP->getRepository(PaymentTarget::class)->findByCode('qr'), $mandatorP);
$cBoth = Creditor::of($both, $mandatorP);
check('P3C6 the creditor block falls back FIELD BY FIELD to the mandator: `qr` (no holder fields) is the mandator; `both` keeps its own name and country (LI), zip and city from the mandator',
    $cQr->name === 'Harness AG' && $cQr->zip === '8000' && $cQr->city === 'Zürich' && $cQr->country === 'CH' && in_array('name', $cQr->fromMandator, true)
    && $cBoth->name === 'Peter u/o Regina Ruepp' && $cBoth->country === 'LI' && $cBoth->city === 'Zürich' && !in_array('name', $cBoth->fromMandator, true) && in_array('city', $cBoth->fromMandator, true));
check('P3C7 without a readable mandator the block has only the target\'s own fields — no fatal', Creditor::of($both, null)->city === '' && Creditor::of($both, null)->name === 'Peter u/o Regina Ruepp');

echo "P3C. The payment part on the document: QRR with a QR-IBAN, NON with a plain IBAN, none on a credit note — the QR payload from the snapshot\n";
$emP = $wireDi();
// `bank` was switched off in G (a row that became invalid) — the documents here need it active.
(new DebtorMasterData($emP))->setTargetActive($emP->getRepository(PaymentTarget::class)->findByCode('bank'), true);
$emP = $wireDi();
$withTarget = fn(string $target, string $date = '2026-06-10') => InvoiceDraft::invoice($mid, day($date), day($date), 'CHF', [
    LineDraft::service('Beratung', '2.000', 'h', chf('150.00'), 'UN', '3400'),
], paymentTargetCode: $target);
$qrInv = $service($emP)->invoice($withTarget('qr'));
$qrRow = $invoiceRow($qrInv->getId());
check('P3C8 target with a QR-IBAN → the columns: pay_target_code qr, the QR-IBAN, QRR, the reference from the NUMBER, the message «Rechnung n», the creditor as resolved',
    $qrRow['pay_target_code'] === 'qr' && $qrRow['pay_account'] === $lower && $qrRow['pay_reference_type'] === 'QRR'
    && $qrRow['pay_reference'] === QrReference::forDocument($emP->getRepository(DebtorProfile::class)->findByContact($mid)->getCustomerNumber(), $qrInv->getNumber())
    && substr($qrRow['pay_reference'], 10, 6) === str_pad((string) $emP->getRepository(DebtorProfile::class)->findByContact($mid)->getCustomerNumber(), 6, '0', STR_PAD_LEFT)
    && $qrRow['pay_message'] === 'Rechnung ' . $qrInv->getNumber()
    && $qrRow['pay_creditor_name'] === 'Harness AG' && $qrRow['pay_creditor_zip'] === '8000' && $qrRow['pay_creditor_country'] === 'CH');
$qrBill  = QrBill::of($readInvoice($qrInv->getId()));
$payload = $qrBill->isPrintable() ? $qrBill->payload() : '';
$el      = explode("\n", $payload);
check('P3C9 the QR payload follows the guidelines (independent check: 31 elements, SPC/0200/1, S addresses, empty ultimate creditor, amount, CHF, QRR + valid check digit, EPD, ≤ 997)' . ($payload === '' ? ' — ' . implode(' ', $qrBill->problems()) : ($specErrors($payload) === [] ? '' : ' — ' . implode(', ', $specErrors($payload)))),
    $payload !== '' && $specErrors($payload) === [] && $el[3] === $lower && $el[5] === 'Harness AG' && $el[18] === $qrInv->getGrossTotal()->toDecimal() && $el[19] === 'CHF'
    && $el[21] === $qrInv->getAddress()->getName() && $el[22] === $qrInv->getAddress()->getStreet() && $el[24] === $qrInv->getAddress()->getZip() && $el[27] === 'QRR' && $el[28] === $qrRow['pay_reference']);
check('P3C10 … and it encodes as a QR code (the kernel facade, level M)', str_contains(\Z77\Shared\Qr\QrCode::svg($payload), '<svg'));
$nonInv = $service($wireDi())->invoice($withTarget('bank'));
$nonRow = $invoiceRow($nonInv->getId());
$nonEl  = explode("\n", QrBill::of($readInvoice($nonInv->getId()))->payload());
check('P3C11 target with only a plain IBAN → NON: the IBAN, NO reference, the document named in the message (the payment can still be assigned)',
    $nonRow['pay_reference_type'] === 'NON' && $nonRow['pay_reference'] === '' && $nonRow['pay_account'] === 'CH9300762011623852957'
    && $nonEl[27] === 'NON' && $nonEl[28] === '' && $nonEl[29] === 'Rechnung ' . $nonInv->getNumber() && $specErrors(implode("\n", $nonEl)) === []);
$bothInv = $service($wireDi())->invoice($withTarget('both'));
check('P3C12 a target with BOTH numbers: an invoice has a reference → the QR-IBAN + QRR (never the QR-IBAN with NON); the holder\'s own name and country on the bill',
    $invoiceRow($bothInv->getId())['pay_reference_type'] === 'QRR' && $invoiceRow($bothInv->getId())['pay_account'] === $withIid('30001')
    && explode("\n", QrBill::of($readInvoice($bothInv->getId()))->payload())[5] === 'Peter u/o Regina Ruepp' && explode("\n", QrBill::of($readInvoice($bothInv->getId()))->payload())[10] === 'LI');
$plainInv = $service($wireDi())->invoice($withTarget(''));
$plainBill = QrBill::of($readInvoice($plainInv->getId()));
check('P3C13 no target → no payment part (every pay_* column empty); the bill says why instead of throwing', $invoiceRow($plainInv->getId())['pay_reference_type'] === '' && !$plainBill->isPrintable()
    && str_contains($plainBill->problems()[0], 'Zahlungsziel') && throws(fn() => $plainBill->payload(), \LogicException::class));
check('P3C14 refusals: an unknown target (target-unknown); a credit-note draft naming a target (no-payment-part)',
    $refusal(fn() => $service($wireDi())->invoice($withTarget('ghost-target'))) === InvoiceRefusedException::TARGET_UNKNOWN
    && $refusal(fn() => $service($wireDi())->invoice(new InvoiceDraft(InvoiceKind::CreditNote, $mid, day('2026-06-10'), null, null, 'CHF', PriceMode::Net, null, null, [LineDraft::lumpSum('x', chf('1.00'), 'UN', '3400')], creditNoteOfId: $inv1->getId(), paymentTargetCode: 'qr'))) === InvoiceRefusedException::NO_PAYMENT_PART);

// Snapshot only: change the target, the mandator and the contact's address AFTER issue.
$payloadBefore = $payload;
$emS = $wireDi();
$qrTarget = $emS->getRepository(PaymentTarget::class)->findByCode('qr');
$qrTarget->setHolderName('Neuer Inhaber GmbH');
(new DebtorMasterData($emS))->saveTarget($qrTarget);
$db->executeStatement("UPDATE mandator SET name = 'Umbenannt AG', city = 'Bern'");
$db->executeStatement("UPDATE address a JOIN contact_address ca ON ca.address_id = a.id SET a.name = 'Müller Neu AG', a.street = 'Neugasse' WHERE ca.contact_id = ?", [$mid]);
$payloadAfter = QrBill::of($readInvoice($qrInv->getId()))->payload();
check('P3C15 SNAPSHOT ONLY: after the target\'s holder, the mandator and the contact\'s address changed, the issued document renders the SAME payload (creditor, debtor, reference)',
    $payloadAfter === $payloadBefore && str_contains($payloadAfter, 'Harness AG') && str_contains($payloadAfter, $qrInv->getAddress()->getName()) && !str_contains($payloadAfter, 'Neuer Inhaber'));
$reissued = $service($wireDi())->reinvoice($qrInv->getId(), (int) $invoiceRow($qrInv->getId())['version'], $withTarget('qr'));
$reEl = explode("\n", QrBill::of($readInvoice($qrInv->getId()))->payload());
check('P3C16 a RE-ISSUE takes the payment part as it reads NOW (the snapshot moves with it): the new holder, the renamed contact — the SAME number, so the SAME reference',
    $reEl[5] === 'Neuer Inhaber GmbH' && $reEl[21] === 'Müller Neu AG' && $reEl[28] === $qrRow['pay_reference'] && $reissued->getNumber() === $qrInv->getNumber());
$emS = $wireDi();
(new DebtorMasterData($emS))->setTargetActive($emS->getRepository(PaymentTarget::class)->findByCode('bank'), false);
check('P3C17 the reference rule: a DEACTIVATED target is refused on a new document (target-inactive) — a re-issue of a document that carries it keeps it',
    $refusal(fn() => $service($wireDi())->invoice($withTarget('bank'))) === InvoiceRefusedException::TARGET_INACTIVE
    && $service($wireDi())->reinvoice($nonInv->getId(), (int) $invoiceRow($nonInv->getId())['version'], $withTarget('bank'))->getPayment()->getTargetCode() === 'bank');
$emS = $wireDi();
(new DebtorMasterData($emS))->setTargetActive($emS->getRepository(PaymentTarget::class)->findByCode('bank'), true);
// A creditor field that does not fit the specification: the mandator's city (70 in the letterhead) longer than 35.
$db->executeStatement("UPDATE mandator SET city = ?", [str_repeat('Ort', 12)]);
$longInv  = $service($wireDi())->invoice($withTarget('qr'));
$longBill = QrBill::of($readInvoice($longInv->getId()));
$db->executeStatement("UPDATE mandator SET name = 'Harness AG', city = 'Zürich'");
check('P3C18 «degrade quietly»: a creditor city of 36 (from the mandator) is NOT cut — the document is issued, its bill reports the problem and prints no payment part',
    $longInv->getId() !== null && $invoiceRow($longInv->getId())['pay_creditor_city'] === str_repeat('Ort', 12) && !$longBill->isPrintable()
    && str_contains(implode(' ', $longBill->problems()), 'Ort hat mehr als 35'));
check('P3C19 the printed address block: salutation, title + names, the address row, street + number, zip + city; the country only when foreign',
    (new AddressSnapshot('Herr', 'Dr.', 'Peter', 'Muster', 'c/o Firma', 'Weg', '5', '3000', 'Bern', 'CH'))->lines() === ['Herr', 'Dr. Peter Muster', 'c/o Firma', 'Weg 5', '3000 Bern']
    && (new AddressSnapshot('', '', '', 'Muster AG', '', '', '', '9490', 'Vaduz', 'LI'))->lines() === ['Muster AG', '9490 Vaduz', 'LI']);

echo "P3C. The credit note: no payment part, its bill says so\n";
$finalQr = $service($wireDi())->finalize([$at($qrInv->getId())])[0];
$cn      = $service($wireDi())->invoice(InvoiceDraft::creditNote($qrInv->getId(), $mid, day('2026-06-20'), 'CHF', [LineDraft::lumpSum('Gutschrift', chf('50.00'), 'UN', '3400')]));
$cnBill  = QrBill::of($readInvoice($cn->getId()));
check('P3C20 a credit note carries NO payment part (every pay_* empty) and its bill is «not printable: Gutschrift»',
    $finalQr->isFinal() && $invoiceRow($cn->getId())['pay_reference_type'] === '' && $invoiceRow($cn->getId())['pay_account'] === ''
    && !$cnBill->isPrintable() && str_contains($cnBill->problems()[0], 'Gutschrift'));

echo "P3C. The document screens (trait + templates through host doubles)\n";
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
$pkgRoot = dirname($package);
$renderer = new class($package . '/res/view/templates/', $pkgRoot) {
    public function __construct(private string $dir, private string $root) {}
    public function partial(string $path, array $context = [], ?string $ns = null): string
    {
        $dir = match ($ns) {
            'Z77\\Module\\Mandator' => $this->root . '/module-mandator/res/view/templates/',
            'Z77\\Module\\Vat'      => $this->root . '/module-vat/res/view/templates/',
            'Z77\\Shared'           => $this->root . '/kernel/shared/res/view/templates/',
            default                 => $this->dir,
        };
        return (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })->call($this, $dir . $path . '.tpl.php', $context);
    }
};
/** A fresh wiring with the request and CSRF doubles (DI::set() never replaces — once per wiring). */
$useRequest = function (array $get, ?array $post = null) use ($wireDi): UnifiedEntityManager {
    $em = $wireDi();
    $_GET = $get; $_POST = $post ?? [];
    $GLOBALS['z77TestIsPost'] = $post !== null;
    DI::getInstance()->set('Request', fn() => new class {
        public function getGetParameter(string $p): mixed { return $_GET[$p] ?? null; }
        public function isPost(): bool { return $GLOBALS['z77TestIsPost']; }
        public function getPostParameters(): array { return $_POST; }
        public function getUploadedFile(string $field): ?\Z77\Shared\ValueObjects\UploadedFile { return $GLOBALS['z77TestUpload'][$field] ?? null; }
        public function getMode(): \Z77\Core\Http\RequestMode { return !empty($GLOBALS['z77TestFetch']) ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
    }, true);
    DI::getInstance()->set('CsrfService', fn() => new class {
        public function generateEntityToken(string $context, int $id): string { return "tok-{$context}-{$id}"; }
        public function validateEntityToken(string $token, string $context, int $id): bool { return $token === "tok-{$context}-{$id}"; }
    }, true);
    return $em;
};
$invoiceHost = function () {
    return new class {
        use \Z77\Module\Debtor\Ui\InvoiceControllerTrait { listAction as public; detailAction as public; pdfAction as public; paymentAction as public; confirmPaymentDeleteAction as public; paymentDeleteAction as public; addAction as public; editAction as public; creditNoteAction as public; confirmFinalizeAction as public; finalizeAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public object $help;
        public ?string $redirectedTo = null;
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
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        public array $bytes = [];
        protected function bytes(string $content, string $filename, string $mimeType, bool $inline = true): \Z77\Core\Http\Response\BytesResponse { $this->bytes = ['content' => $content, 'filename' => $filename, 'mime' => $mimeType, 'inline' => $inline]; return new \Z77\Core\Http\Response\BytesResponse($content, $filename, $mimeType, $inline); }
        // The session actor is not wired in the harness — name it, as a CLI caller must.
        private function invoicingService(): InvoicingService { return new InvoicingService($this->em(), 'sachbearbeiter'); }
        private function paymentService(): \Z77\Module\Debtor\Services\PaymentService { return new \Z77\Module\Debtor\Services\PaymentService($this->em(), 'sachbearbeiter'); }
    };
};
$renderMain = fn($host) => implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['main'] ?? []));
/** The list's body: the layout config pins it (InvoiceLayout), the trait adds only the toolbar. */
$renderList = fn($host) => $renderer->partial('Backend/InvoiceController/listAction', $host->context);
$renderSlot = fn($host, string $slot) => implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections[$slot] ?? []));

$layoutI = \Z77\Module\Debtor\Ui\InvoiceLayout::config();
check('P3C21 the layout pins the body to the fragment\'s listAction; the host is mounted under finance; the seed puts «Rechnungen» into the area «Aufträge» (ADR-050)',
    ($layoutI['levelElements']['body']['main'][0]['path'] ?? '') === 'Backend/InvoiceController' && ($layoutI['levelElements']['body']['main'][0]['nameSpace'] ?? '') === 'Z77\\Module\\Debtor'
    && is_file($pkgRoot . '/module-backend/src/Ui/Controllers/Finance/InvoiceController.php') && is_file($pkgRoot . '/module-backend/src/Ui/Config/Finance/invoiceControllerConfig.inc.php')
    && (function () use ($package): bool {
        foreach (json_decode(file_get_contents($package . '/data/framework/routing/navigation.d/module-debtor.json'), true) as $row) {
            if (($row['key'] ?? '') === 'rechnungen') { return $row['parent_key'] === 'auftraege' && $row['controller'] === 'invoice' && $row['group'] === 'finance' && $row['action'] === 'list'; }
        }
        return false;
    })());

// The list per view, searchable and paged.
$useRequest([]);
$host = $invoiceHost();
$host->listAction();
$listHtml = $renderList($host) . $renderSlot($host, 'hc2');
$invoicingIds = array_map(fn($d) => $d->getId(), $host->context['documents']);
check('P3C22 list, default view «In Fakturierung»: only invoices in invoicing, newest first, each row with its {id}:{version} checkbox; the toolbar with the view tabs (counts), «Rechnung erstellen», «Definitiv stellen …»',
    $host->context['filter']->view === 'invoicing' && $invoicingIds !== [] && array_filter($host->context['documents'], fn($d) => $d->isFinal() || $d->isCreditNote()) === []
    && str_contains($listHtml, 'data-fetch-region="invoice-list"') && str_contains($listHtml, 'value="' . $nonInv->getId() . ':' . $invoiceRow($nonInv->getId())['version'] . '"')
    && str_contains($listHtml, 'form="invoice-finalize"') && str_contains($listHtml, 'Rechnung erstellen') && str_contains($listHtml, 'be-viewtabs')
    && $host->context['counts']['invoicing'] === $host->context['paging']->total);
$numbers = array_map(fn($d) => $d->getNumber(), $host->context['documents']);
$sorted  = $numbers; rsort($sorted);
check('P3C22b … sorted by number, newest first', $numbers === $sorted);
$useRequest(['view' => 'final']);
$host = $invoiceHost();
$host->listAction();
check('P3C23 view «Definitiv»: only final invoices, no selection checkbox', $host->context['documents'] !== [] && array_filter($host->context['documents'], fn($d) => !$d->isFinal() || $d->isCreditNote()) === []
    && !str_contains($renderList($host), 'name="doc[]"') && $host->layoutManager->sections === ['hc2' => ['Backend/InvoiceController/toolbar']]);
$useRequest(['view' => 'credit']);
$host = $invoiceHost();
$host->listAction();
check('P3C24 view «Gutschriften»: credit notes only (any state), each naming its invoice', $host->context['documents'] !== [] && array_filter($host->context['documents'], fn($d) => !$d->isCreditNote()) === []
    && str_contains($renderList($host), 'zu Rechnung'));
$useRequest(['f_nr' => (string) $nonInv->getNumber()]);
$host = $invoiceHost();
$host->listAction();
$useRequest(['f_name' => 'Müller Neu', 'f_date' => '06.2026']);
$hostName = $invoiceHost();
$hostName->listAction();
$useRequest(['f_nr' => 'abc', 'f_amount' => '1\'234.5']);
$hostBad = $invoiceHost();
$hostBad->listAction();
check('P3C25 the column search runs in the database: number exact; name + month; an unreadable number is marked invalid and ignored, an amount with an apostrophe is read',
    array_map(fn($d) => $d->getId(), $host->context['documents']) === [$nonInv->getId()]
    && $hostName->context['documents'] !== [] && array_filter($hostName->context['documents'], fn($d) => $d->getAddress()->getName() !== 'Müller Neu AG' || $d->getInvoiceDate()->format('m.Y') !== '06.2026') === []
    && $hostBad->context['filter']->isInvalid('f_nr') && !$hostBad->context['filter']->isInvalid('f_amount') && $hostBad->context['filter']->search()->amount === '1234.50' && $hostBad->context['filter']->search()->number === null);
check('P3C26 paging: the shared kernel Paging (moved from module-financial, Rule 8) and the shared pager partial', $host->context['paging'] instanceof \Z77\Shared\Paging\Paging
    && !class_exists('Z77\\Module\\Financial\\Reports\\Paging') && is_file($pkgRoot . '/kernel/shared/res/view/templates/partials/pager.tpl.php'));

// Detail: from the snapshot, the ledger reference linked as a window.
$useRequest(['id' => (string) $qrInv->getId()]);
$host = $invoiceHost();
$host->detailAction();
$detail = $renderMain($host);
$ledgerRef = $invoiceRow($qrInv->getId())['ledger_entry_ref'];
check('P3C27 detail of a final invoice: the address block from the snapshot, the lines, the QR data (QR-IBAN, the reference in blocks of five), the ledger reference as a journal WINDOW (?ref=), the credit note, «Gutschrift erstellen …»',
    $ledgerRef !== null && str_contains($detail, 'data-window-open="/backend/finance/journal/detail?ref=' . rawurlencode($ledgerRef) . '"')
    && str_contains($detail, 'Müller Neu AG') && str_contains($detail, QrReference::format($qrRow['pay_reference'])) && str_contains($detail, 'data-qr-bill="printable"')
    && str_contains($detail, 'Gutschrift ' . $cn->getNumber()) && str_contains($detail, '/credit-note?of=' . $qrInv->getId()) && !str_contains($detail, '/edit?id='));
$useRequest(['id' => (string) $plainInv->getId()]);
$host = $invoiceHost();
$host->detailAction();
$plainDetail = $renderMain($host);
check('P3C28 detail of a document without payment part: «Kein Zahlteil» with the reason; «Neu fakturieren …» while invoicing', str_contains($plainDetail, 'data-qr-bill="missing"') && str_contains($plainDetail, 'Kein Zahlteil') && str_contains($plainDetail, '/edit?id=' . $plainInv->getId()));

// The journal detail answers ?ref= (financial).
$journalTraitSource = file_get_contents($pkgRoot . '/module-financial/src/Ui/JournalControllerTrait.php');
check('P3C29 financial\'s journal detail opens by ?ref={year}/{number} (findByRef) — the link a posting module stores', str_contains($journalTraitSource, "getGetParameter('ref')") && str_contains($journalTraitSource, 'findByRef('));

// The editor: new invoice → invoice().
$useRequest(['contact' => (string) $mid]);
$host = $invoiceHost();
$host->addAction();
$formHtml = $renderMain($host);
check('P3C30 GET add: the editor with the action bar first (ADR-049), the debtor preselected, the first active target preselected, empty rows; pickers: ACTIVE tax codes only (the shared module-vat list) and the account datalist (module-mandator); help attached (ADR-048)',
    $host->layoutManager->sections['main'] === ['Backend/InvoiceController/form'] && strpos($formHtml, 'z77-form-actions') < strpos($formHtml, 'name="invoice_date"')
    && str_contains($formHtml, 'value="' . $mid . '" selected') && str_contains($formHtml, 'value="bank" selected')
    && count($host->context['form']->rows()) === \Z77\Module\Debtor\Ui\InvoiceForm::ROWS_NEW
    && array_filter($host->context['taxCodes'], fn($c) => !$c->isActive()) === [] && str_contains($formHtml, '<datalist id="invoice-accounts">') && str_contains($formHtml, 'list="invoice-accounts"')
    && $host->help->has() && !preg_match('/<script|\son[a-z]+\s*=/i', $formHtml));
$post = [
    'contact' => (string) $mid, 'invoice_date' => '2026-07-01', 'service_from' => '2026-06-30', 'service_to' => '', 'price_mode' => 'net', 'terms' => '', 'target' => 'qr', 'op' => 'save',
    'rows' => [
        ['type' => 'service', 'text' => 'Paket Web', 'quantity' => '1', 'unit' => 'Stk', 'price' => '1\'000.00', 'discount' => '10', 'tax_code' => 'UN', 'account' => '3400'],
        ['type' => 'lump-sum', 'child' => '1', 'text' => 'davon Hosting', 'price' => '0.00', 'tax_code' => 'UN', 'account' => '3400'],
        ['type' => 'text', 'text' => 'Danke.'],
        ['type' => 'service', 'text' => '', 'quantity' => '', 'price' => ''],
    ],
];
$before = (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'invoice'");
$useRequest([], $post);
$host = $invoiceHost();
$host->addAction();
$newId  = (int) $db->fetchOne('SELECT MAX(id) FROM invoice');
$newDoc = $readInvoice($newId);
check('P3C31 POST add → invoice(): a new document in invoicing (number drawn once), the lines with the discount (10 % → 1000), the child beneath its parent, the empty row ignored, the target qr → QRR; redirect to its detail',
    (int) $db->fetchOne("SELECT last_number FROM number_range WHERE name = 'invoice'") === $before + 1 && $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $newId
    && $newDoc->getLines()[0]->getDiscountPercent() === 1000 && $newDoc->getLines()[0]->getAmount()->toDecimal() === '900.00' && $newDoc->getLines()[1]->getParentLine() === $newDoc->getLines()[0]
    && count(array_filter($newDoc->getLines(), fn($l) => $l->type() !== LineType::Rounding)) === 3 && $newDoc->getPayment()->getReferenceType() === 'QRR' && !$newDoc->isFinal()
    && $host->messageService->flashes[0][0] === 'success');
$useRequest([], ['op' => 'more'] + $post);
$host = $invoiceHost();
$host->addAction();
check('P3C32 «Weitere Zeilen» is a submit: the same form back with more rows, nothing written', count($host->context['form']->rows()) === 4 + \Z77\Module\Debtor\Ui\InvoiceForm::MORE_ROWS && (int) $db->fetchOne('SELECT MAX(id) FROM invoice') === $newId);
$bad = $post;
$bad['rows'][0]['quantity'] = '1.2345';
$bad['rows'][0]['tax_code'] = '';
$bad['rows'][2] = ['type' => 'text', 'text' => 'x', 'price' => '5.00'];
$useRequest([], $bad);
$host = $invoiceHost();
$host->addAction();
$badHtml = $renderMain($host);
check('P3C33 unreadable fields come back as ROW errors (quantity, tax code, a text row with a price) with «N Fehler» in the action bar — nothing written',
    $host->context['form']->rowError(0, 'quantity') !== '' && $host->context['form']->rowError(0, 'tax_code') !== '' && $host->context['form']->rowError(2, 'price') !== ''
    && str_contains($badHtml, 'z77-form-actions__errors') && str_contains($badHtml, 'for="invoice-row-0-quantity"') && (int) $db->fetchOne('SELECT MAX(id) FROM invoice') === $newId);
$refusedPost = $post;
$refusedPost['rows'][0]['account'] = '100';   // a group — the service's soft account check refuses
$useRequest([], $refusedPost);
$host = $invoiceHost();
$host->addAction();
check('P3C34 a refusal of the SERVICE comes back as the German general error on the form (account-not-postable), nothing written',
    str_contains(implode(' ', $host->context['form']->generalErrors()), 'Konto 100') && (int) $db->fetchOne('SELECT MAX(id) FROM invoice') === $newId);

// Edit → reinvoice() with the hidden version.
$useRequest(['id' => (string) $newId]);
$host = $invoiceHost();
$host->editAction();
$editHtml = $renderMain($host);
$v0 = (int) $invoiceRow($newId)['version'];
check('P3C35 GET edit: the form filled from the snapshot (the parent/child rows, 10 %, the target), the hidden VERSION and the entity token; the party fixed',
    str_contains($editHtml, 'name="version" value="' . $v0 . '"') && str_contains($editHtml, 'value="tok-invoice-' . $newId . '"')
    && $host->context['form']->rows()[1]['child'] === '1' && $host->context['form']->rows()[0]['discount'] === '10' && $host->context['form']->header('target') === 'qr'
    && str_contains($editHtml, 'type="hidden" name="contact"') && !str_contains($editHtml, '<select id="invoice-contact"'));
$editPost = ['entity_csrf' => "tok-invoice-{$newId}", 'version' => (string) $v0] + $post;
$editPost['rows'][0]['price'] = '1200.00';
$useRequest(['id' => (string) $newId], $editPost);
$host = $invoiceHost();
$host->editAction();
check('P3C36 POST edit → reinvoice(): the same number, the new price, the version bumped, redirect to the detail',
    $readInvoice($newId)->getLines()[0]->getAmount()->toDecimal() === '1080.00' && (int) $invoiceRow($newId)['version'] === $v0 + 1 && $readInvoice($newId)->getNumber() === $newDoc->getNumber()
    && $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $newId);
$useRequest(['id' => (string) $newId], $editPost);   // the SAME (now stale) version again
$host = $invoiceHost();
$host->editAction();
check('P3C37 a stale version (the form of before the re-issue) is refused: flash, back to the detail, nothing changed', $host->messageService->flashes[0][0] === 'error'
    && str_contains($host->messageService->flashes[0][1], 'inzwischen geändert') && (int) $invoiceRow($newId)['version'] === $v0 + 1);

// Finalize batch with versions.
$second = $service($wireDi())->invoice($withTarget('qr', '2026-07-02'));
$pairA  = $newId . ':' . $invoiceRow($newId)['version'];
$pairB  = $second->getId() . ':' . $invoiceRow($second->getId())['version'];
$useRequest(['doc' => [$pairA, $pairB]]);
$host = $invoiceHost();
$host->confirmFinalizeAction();
$confirm = $renderMain($host);
check('P3C38 confirm-finalize lists the selection with its versions and one button that posts the same pairs', count($host->context['documents']) === 2 && $host->context['stale'] === []
    && str_contains($confirm, 'value="' . $pairA . '"') && str_contains($confirm, 'value="' . $pairB . '"') && str_contains($confirm, 'action="/backend/finance/invoice/finalize"'));
// B is re-issued between the look and the click: the whole batch is refused, nothing posted.
$service($wireDi())->reinvoice($second->getId(), (int) $invoiceRow($second->getId())['version'], $withTarget('qr', '2026-07-03'));
$journalBefore = $journalCount();
$useRequest([], ['doc' => [$pairA, $pairB]]);
$host = $invoiceHost();
$host->finalizeAction();
check('P3C39 finalize with a STALE version (B re-issued since): refused whole — A and B stay in invoicing, no journal entry, the flash says why',
    $stateOf($newId) === 'invoicing' && $stateOf($second->getId()) === 'invoicing' && $journalCount() === $journalBefore
    && $host->messageService->flashes[0][0] === 'error' && str_contains($host->messageService->flashes[0][1], 'nichts wurde verbucht'));
$useRequest(['doc' => [$pairA, $pairB]]);
$host = $invoiceHost();
$host->confirmFinalizeAction();
check('P3C40 … and the confirmation, asked again with the old selection, names B as stale and leaves it out', $host->context['stale'] === [$second->getId()] && count($host->context['documents']) === 1);
$pairB2 = $second->getId() . ':' . $invoiceRow($second->getId())['version'];
$useRequest([], ['doc' => [$pairA, $pairB2, 'garbage', '1:x']]);
$host = $invoiceHost();
$host->finalizeAction();
check('P3C41 finalize with the CURRENT versions: both final and posted (two journal entries) in one unit of work, to the «Definitiv» view; junk values dropped',
    $stateOf($newId) === 'final' && $stateOf($second->getId()) === 'final' && $journalCount() === $journalBefore + 2
    && $host->redirectedTo === '/backend/finance/invoice/list?view=final' && $host->messageService->flashes[0][0] === 'success');
$useRequest(['id' => (string) $newId]);
$host = $invoiceHost();
$host->editAction();
check('P3C42 a final document is never edited: edit refuses with the credit-note sentence', $host->messageService->flashes[0][0] === 'error' && str_contains($host->messageService->flashes[0][1], 'Gutschrift')
    && $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $newId);

// The credit-note form: prefilled from the invoice.
$useRequest(['of' => (string) $newId]);
$host = $invoiceHost();
$host->creditNoteAction();
$cnForm = $host->context['form'];
$cnHtml = $renderMain($host);
check('P3C43 GET credit-note: service dates PREFILLED from the invoice, the lines prefilled (rounding left out), no payment target field, the party fixed',
    $cnForm->header('service_from') === '2026-06-30' && $cnForm->header('service_to') === '' && $cnForm->rows()[0]['text'] === 'Paket Web' && $cnForm->rows()[0]['price'] === '1200.00'
    && !str_contains($cnHtml, 'name="target"') && str_contains($cnHtml, 'Gutschrift zu Rechnung ' . $newDoc->getNumber()) && str_contains($cnHtml, 'type="hidden" name="contact"'));
$cnPost = ['contact' => '999999', 'invoice_date' => '2026-07-10', 'service_from' => '2026-06-30', 'service_to' => '', 'price_mode' => 'net', 'terms' => '', 'target' => 'qr',
    'rows' => [['type' => 'lump-sum', 'text' => 'Gutschrift Paket', 'price' => '100.00', 'tax_code' => 'UN', 'account' => '3400']]];
$useRequest(['of' => (string) $newId], $cnPost);
$host = $invoiceHost();
$host->creditNoteAction();
$cnId = (int) $db->fetchOne('SELECT MAX(id) FROM invoice');
$cnDoc = $readInvoice($cnId);
check('P3C44 POST credit-note with the dates unchanged → invoice(credit note): of THIS invoice, the party from the invoice (a posted contact id is ignored), the service date the invoice\'s, no payment part',
    $cnDoc->isCreditNote() && $cnDoc->getCreditNoteOf()?->getId() === $newId && $cnDoc->getContact()->getId() === $mid
    && $cnDoc->getServiceFrom()->format('Y-m-d') === '2026-06-30' && !$cnDoc->getPayment()->hasPaymentPart() && $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $cnId);
$partial = $cnPost;
$partial['service_from'] = '2026-07-01';
$partial['service_to']   = '2026-07-31';
$useRequest(['of' => (string) $newId], $partial);
$host = $invoiceHost();
$host->creditNoteAction();
$cn2 = $readInvoice((int) $db->fetchOne('SELECT MAX(id) FROM invoice'));
check('P3C45 … a PARTIAL period typed in: the credit note carries its own dates (same rate — accepted)', $cn2->isCreditNote() && $cn2->getId() !== $cnId
    && $cn2->getServiceFrom()->format('Y-m-d') === '2026-07-01' && $cn2->getServiceTo()?->format('Y-m-d') === '2026-07-31');
$useRequest(['of' => (string) $plainInv->getId()]);
$host = $invoiceHost();
$host->creditNoteAction();
check('P3C46 a credit note against an invoice still in invoicing is refused on the screen (it is re-invoiced, not credited)', $host->messageService->flashes[0][0] === 'error' && str_contains($host->messageService->flashes[0][1], 'neu fakturiert'));

// Payment target screen: the effective creditor block.
$useRequest([]);
$ptHost = new class {
    use PaymentTargetControllerTrait { listAction as public; }
    public array $context = [];
    public object $layoutManager;
    public function __construct() { $this->layoutManager = new class { public array $sections = []; public function addPartials(string $n, string $p, string $ns, string $s = 'main'): void { $this->sections[$s][] = $p . '/' . $n; } }; }
    protected function em() { return DI::getUnifiedEntityManager(); }
    protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
};
$ptHost->listAction();
$ptHtml = $renderer->partial('Backend/PaymentTargetController/listAction', $ptHost->context);
check('P3C47 the payment-target list shows the EFFECTIVE creditor per target — the holder\'s own name, the mandator\'s where empty, marked — and both IBAN kinds',
    str_contains($ptHtml, 'Empfänger: Peter u/o Regina Ruepp') && str_contains($ptHtml, 'teils vom Mandanten') && str_contains($ptHtml, 'Empfänger: Neuer Inhaber GmbH')
    && str_contains($ptHtml, 'QR-IBAN') && str_contains($ptHtml, 'CH93 0076 2011 6238 5295 7'));

// ── P3D. the PDF (owner 2026-10-06: FPDF through the kernel facade, the layouts as partials) ──

echo "P3D. The document as PDF: the layout pdf/invoice, the payment part at the foot, rendered on request\n";
$emD    = $wireDi();
$qrDoc  = $readInvoice($qrInv->getId());
$pdfDoc = \Z77\Module\Debtor\Pdf\InvoicePdf::of($qrDoc, $emD)->withoutCompression();
$pdfBin = $pdfDoc->output();
$custNo = $emD->getRepository(DebtorProfile::class)->findByContact($mid)->getCustomerNumber();
check('P3D1 a QR invoice renders: a PDF, the title is the document name, the letterhead (mandator) and the customer number are on it',
    str_starts_with($pdfBin, '%PDF-') && str_contains($pdfBin, '(' . $qrDoc->documentName() . ')') && str_contains($pdfBin, 'Harness AG') && str_contains($pdfBin, '(Kundennummer)') && str_contains($pdfBin, '(' . $custNo . ')'));
check('P3D2 … the payment part is there: receipt and payment-part titles, the account and the reference as printed, the amount grouped with a space, the Swiss cross fills',
    str_contains($pdfBin, '(Empfangsschein)') && str_contains($pdfBin, '(Zahlteil)') && str_contains($pdfBin, '(' . $pdfDoc->enc(QrBill::of($qrDoc)->formattedAccount()) . ')')
    && str_contains($pdfBin, '(' . QrBill::of($qrDoc)->formattedReference() . ')') && str_contains($pdfBin, '(Vor der Einzahlung abzutrennen)') && substr_count($pdfBin, ' re f') > 100);
check('P3D3 … no footer on the page with the payment part (the 105 mm zone stays clean), one page', str_contains($pdfBin, '/Count 1') && !str_contains($pdfBin, 'Seite 1 von'));
$withoutStamp = fn(string $pdf): string => preg_replace('~/CreationDate \([^)]*\)~', '', $pdf);
check('P3D4 the same document yields the same bytes up to the creation timestamp of the metadata (rendered from the snapshot, deterministic)',
    $withoutStamp(\Z77\Module\Debtor\Pdf\InvoicePdf::of($readInvoice($qrInv->getId()), $wireDi())->withoutCompression()->output()) === $withoutStamp($pdfBin));
check('P3D5 the file name is the document name in kebab-case lower', \Z77\Module\Debtor\Pdf\InvoicePdf::fileName($qrDoc) === 'rechnung-' . $qrDoc->getNumber() . '.pdf');

$cnDoc = $readInvoice($cn->getId());
$cnBin = \Z77\Module\Debtor\Pdf\InvoicePdf::of($cnDoc, $wireDi())->withoutCompression()->output();
check('P3D6 a credit note prints without a payment part and names the invoice it corrects', str_contains($cnBin, '(Gutschrift zu)') && !str_contains($cnBin, '(Zahlteil)') && str_contains($cnBin, '(' . $cnDoc->documentName() . ')'));

// A long invoice: the lines flow over pages, the running header on page 2, the payment part on the LAST page.
$emL   = $wireDi();
$longLines = [];
for ($i = 1; $i <= 45; $i++) {
    $longLines[] = LineDraft::service("Position {$i} — eine Leistung mit einem Text, der in der ersten Spalte umbricht, damit die Tabelle Platz braucht", '1.000', 'Stk', chf('10.00'), 'UN', '3400');
}
$longInv = $service($emL)->invoice(InvoiceDraft::invoice($mid, day('2026-06-12'), day('2026-06-12'), 'CHF', $longLines, paymentTargetCode: 'qr'));
$longDoc = $readInvoice($longInv->getId());
$longPdf = \Z77\Module\Debtor\Pdf\InvoicePdf::of($longDoc, $wireDi())->withoutCompression();
$longBin = $longPdf->output();
check('P3D7 45 lines: more than one page, the running header «Seite 2 von N» on the later pages, the table header repeated, the payment part once',
    $longPdf->pageNo() >= 2 && str_contains($longBin, 'Seite 2 von ' . $longPdf->pageNo()) && substr_count($longBin, '(Bezeichnung)') === $longPdf->pageNo() && substr_count($longBin, '(Zahlteil)') === 1);
// For the eye: Z77_PDF_SAMPLE_DIR=<dir> keeps the three documents as files (the harness database is gone at exit).
if (($sampleDir = getenv('Z77_PDF_SAMPLE_DIR')) !== false && is_dir($sampleDir)) {
    file_put_contents($sampleDir . '/rechnung-qr.pdf', \Z77\Module\Debtor\Pdf\InvoicePdf::of($qrDoc, $wireDi())->output());
    file_put_contents($sampleDir . '/rechnung-lang.pdf', \Z77\Module\Debtor\Pdf\InvoicePdf::of($longDoc, $wireDi())->output());
    file_put_contents($sampleDir . '/gutschrift.pdf', \Z77\Module\Debtor\Pdf\InvoicePdf::of($cnDoc, $wireDi())->output());
    echo "       samples written to {$sampleDir}\n";
}

// The action through the host: inline PDF, the file name, a missing id redirects.
$useRequest(['id' => $qrInv->getId()]);
$host = $invoiceHost();
$host->pdfAction();
check('P3D8 pdfAction(): application/pdf inline, named rechnung-N.pdf, the bytes of the document', $host->bytes['mime'] === 'application/pdf' && $host->bytes['inline'] === true
    && $host->bytes['filename'] === 'rechnung-' . $qrInv->getNumber() . '.pdf' && str_starts_with($host->bytes['content'], '%PDF-'));
$useRequest(['id' => 999999]);
$host = $invoiceHost();
$host->pdfAction();
check('P3D9 … an unknown id → flash + redirect to the list', $host->redirectedTo !== null && $host->messageService->flashes !== []);
$useRequest(['id' => $qrInv->getId()]);
$host = $invoiceHost();
$host->detailAction();
check('P3D10 the detail offers «PDF» (a new tab) next to the other actions', str_contains($renderMain($host), '/pdf?id=' . $qrInv->getId()) && str_contains($renderMain($host), 'target="_blank"'));

// ── Q. P4 part 1: payments, discount and loss (plan §6.3, owner go 2026-10-06) ──

echo "Q. PaymentService::record(): a settlement on a FINAL invoice — allocations posted at once, the open amount derived\n";
use Z77\Module\Debtor\Payments\PaymentDraft;
use Z77\Module\Debtor\Services\PaymentService;
use Z77\Module\Debtor\Services\PaymentRefusedException;
$pay       = fn(UnifiedEntityManager $em) => new PaymentService($em, 'kassier');
$payRefusal = fn(callable $fn): ?string => caught($fn, PaymentRefusedException::class)?->reason;
$draftOn   = fn(int $invoiceId, string $date, string $payment, string $discount = '0.00', string $loss = '0.00', string $target = 'qr', ?string $note = null)
    => new PaymentDraft($invoiceId, day($date), chf($payment), chf($discount), chf($loss), $target, $note);
$mandatorAccounts = $db->fetchAssociative('SELECT account_receivable, account_discount, account_loss FROM mandator');
$paymentCount     = fn() => $count('payment');
$allocationCount  = fn() => $count('payment_allocation');
$journalBefore    = $journalCount();

// inv1: final, gross 162.15, a final credit note of 54.05 → open 108.10 (section L).
$emQ  = $wireDi();
$openBefore = $service($emQ)->openAmount($readInvoice($inv1->getId()));
$p1   = $pay($emQ)->record($draftOn($inv1->getId(), '2026-04-02', '100.00', '8.10', '0.00', 'qr', 'Bank-Eingang 2.4.'));
$a1   = $p1->getAllocations();
check('Q1 a payment of 100.00 plus 8.10 Skonto on the invoice open at 108.10: one payment row, two allocations (payment, discount), both posted, the open amount is 0.00',
    $openBefore->toDecimal() === '108.10' && $p1->getId() !== null && $paymentCount() === 1 && $allocationCount() === 2
    && count($a1) === 2 && $a1[0]->kind()->value === 'payment' && $a1[1]->kind()->value === 'discount'
    && $a1[0]->getLedgerEntryRef() !== null && $a1[1]->getLedgerEntryRef() !== null && $a1[0]->getLedgerEntryRef() !== $a1[1]->getLedgerEntryRef()
    && $service($wireDi())->openAmount($readInvoice($inv1->getId()))->isZero() && $journalCount() === $journalBefore + 2
    && $p1->getPaymentTargetCode() === 'qr' && $p1->getNote() === 'Bank-Eingang 2.4.' && $p1->getCreatedBy() === 'kassier' && $p1->getSourceType() === 'manual');
$pl = $entryLines((string) $a1[0]->getLedgerEntryRef());
check('Q2 the payment posting: the target\'s bank account 1020 DEBIT 100.00 | receivable CREDIT 100.00, no tax data, text «Zahlung Rechnung n · name», source payment/{id}',
    count($pl) === 2 && $pl[0]['account_number'] === '1020' && $pl[0]['debit'] === '100.00' && $pl[0]['tax_code'] === null
    && $pl[1]['account_number'] === $mandatorAccounts['account_receivable'] && $pl[1]['credit'] === '100.00'
    && (function () use ($db, $a1, $p1, $inv1, $readInvoice): bool {
        $e = $db->fetchAssociative('SELECT e.text, e.source_type, e.source_ref, e.idempotency_key FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE CONCAT(y.code, \'/\', e.number) = ?', [$a1[0]->getLedgerEntryRef()]);
        return str_starts_with($e['text'], 'Zahlung ' . $readInvoice($inv1->getId())->documentName() . ' · ') && $e['source_type'] === 'payment' && $e['source_ref'] === (string) $p1->getId() && $e['idempotency_key'] === 'allocation:' . $a1[0]->getId();
    })());
$dl = $entryLines((string) $a1[1]->getLedgerEntryRef());
$discountLine = array_values(array_filter($dl, fn($r) => $r['account_number'] === $mandatorAccounts['account_discount']));
$vatLine      = array_values(array_filter($dl, fn($r) => $r['account_number'] === '2200'));
$recvLine     = array_values(array_filter($dl, fn($r) => $r['account_number'] === $mandatorAccounts['account_receivable']));
check('Q3 the Skonto posting (one code UN 8.1 %): discount account DEBIT 7.49 with UN 810, base −7.49, tax −0.61 | 2200 DEBIT 0.61 | receivable CREDIT 8.10 — turnover and VAT reduced proportionally, Σ debit = Σ credit',
    count($dl) === 3 && count($discountLine) === 1 && $discountLine[0]['debit'] === '7.49' && $discountLine[0]['tax_code'] === 'UN' && (int) $discountLine[0]['tax_rate'] === 810
    && $discountLine[0]['tax_base'] === '-7.49' && $discountLine[0]['tax_amount'] === '-0.61'
    && count($vatLine) === 1 && $vatLine[0]['debit'] === '0.61' && count($recvLine) === 1 && $recvLine[0]['credit'] === '8.10');
check('Q4 the detail reads the allocations back with their payments, oldest first', (function () use ($pay, $wireDi, $readInvoice, $inv1): bool {
    $rows = $pay($em = $wireDi())->allocationsOf($readInvoice($inv1->getId()));
    return count($rows) === 2 && $rows[0]->getPayment()->getNote() === 'Bank-Eingang 2.4.' && $rows[0]->getAmount()->toDecimal() === '100.00' && $rows[1]->getAmount()->toDecimal() === '8.10';
})());

echo "Q. … refusals — nothing written, nothing posted\n";
$before = [$paymentCount(), $allocationCount(), $journalCount()];
$inv2Open = $service($wireDi())->openAmount($readInvoice($inv2->getId()));
check('Q5 an invoice already settled takes nothing more (over-allocation); more than the open amount is refused too',
    $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv1->getId(), '2026-04-03', '0.01'))) === PaymentRefusedException::OVER_ALLOCATION
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', $inv2Open->add(chf('0.01'))->toDecimal()))) === PaymentRefusedException::OVER_ALLOCATION);
$stillInvoicing = $service($wireDi())->invoice($oneLine($mid, '2026-07-01', '1.000', '10.00'));
check('Q6 a credit note is not paid (credit-note); a document in invoicing is not settleable (not-final); an unknown document (not-found)',
    $payRefusal(fn() => $pay($wireDi())->record($draftOn($cn->getId(), '2026-07-01', '1.00'))) === PaymentRefusedException::CREDIT_NOTE
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn($stillInvoicing->getId(), '2026-07-01', '1.00'))) === PaymentRefusedException::NOT_FINAL
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn(999999, '2026-07-01', '1.00'))) === PaymentRefusedException::NOT_FOUND);
check('Q7 all three at 0.00 (nothing); a negative amount (amount); a payment without a target (target-required); an unknown, an inactive target, a target without a postable account (target-…); a value date before the invoice date (date)',
    $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '0.00'))) === PaymentRefusedException::NOTHING
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '-1.00'))) === PaymentRefusedException::AMOUNT
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '1.00', '0.00', '0.00', ''))) === PaymentRefusedException::TARGET_REQUIRED
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '1.00', '0.00', '0.00', 'nope'))) === PaymentRefusedException::TARGET_UNKNOWN
    && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2020-01-01', '1.00'))) === PaymentRefusedException::DATE
    && (function () use ($wireDi, $pay, $draftOn, $inv2, $payRefusal, $base, $withIid): bool {
        $em = $wireDi();
        $md = new DebtorMasterData($em);
        // Its own QR-IBAN — an IBAN is unique across the targets.
        $sleeping = new PaymentTarget(['code' => 'sleeping', 'label' => 'Inaktiv', 'qr_iban' => $withIid('31000'), 'account_number' => '1020']);
        $md->saveTarget($sleeping);
        $md->setTargetActive($sleeping, false);
        // Rows the validator would refuse (no account, an account the chart lacks) — written raw, as an old file or a hand edit could leave them.
        $file = $base . '/data/framework/debtor/payment_targets.json';
        $rows = json_decode(file_get_contents($file), true);
        $rows[] = ['id' => 901, 'code' => 'noacct', 'label' => 'Ohne Konto', 'iban' => 'CH9300762011623852957', 'qr_iban' => '', 'account_number' => '', 'active' => true];
        $rows[] = ['id' => 902, 'code' => 'badacct', 'label' => 'Falsches Konto', 'iban' => 'CH9300762011623852957', 'qr_iban' => '', 'account_number' => '9999', 'active' => true];
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '1.00', '0.00', '0.00', 'noacct'))) === PaymentRefusedException::TARGET_ACCOUNT
            && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '1.00', '0.00', '0.00', 'badacct'))) === PaymentRefusedException::TARGET_ACCOUNT
            && $payRefusal(fn() => $pay($wireDi())->record($draftOn($inv2->getId(), '2026-04-03', '1.00', '0.00', '0.00', 'sleeping'))) === PaymentRefusedException::TARGET_INACTIVE;
    })());
check('Q8 … and none of them wrote a row or posted an entry', [$paymentCount(), $allocationCount(), $journalCount()] === $before);

echo "Q. … a write-off, and the ledger's refusal rolls the settlement back WHOLE\n";
$emQ2 = $wireDi();
$p2   = $pay($emQ2)->record(new PaymentDraft($inv2->getId(), day('2026-05-01'), chf('0.00'), chf('0.00'), $inv2Open, '', 'uneinbringlich'));
$ll   = $entryLines((string) $p2->getAllocations()[0]->getLedgerEntryRef());
$sumD = array_sum(array_map(fn($r) => (int) str_replace('.', '', $r['debit']), $ll));
$sumC = array_sum(array_map(fn($r) => (int) str_replace('.', '', $r['credit']), $ll));
check('Q9 a pure write-off (loss = the open amount, no money, no target): one allocation of kind loss on the loss account with the tax split, Σ debit = Σ credit = the amount, the invoice is settled',
    $p2->getAmount()->isZero() && $p2->getPaymentTargetCode() === '' && count($p2->getAllocations()) === 1 && $p2->getAllocations()[0]->kind()->value === 'loss'
    && $sumD === $sumC && $sumD === (int) str_replace('.', '', $inv2Open->toDecimal())
    && count(array_filter($ll, fn($r) => $r['account_number'] === $mandatorAccounts['account_loss'])) >= 1
    && count(array_filter($ll, fn($r) => $r['account_number'] === $mandatorAccounts['account_receivable'] && $r['credit'] === $inv2Open->toDecimal())) === 1
    && $service($wireDi())->openAmount($readInvoice($inv2->getId()))->isZero());
$emQ3 = $wireDi();
$inv3 = $service($emQ3)->invoice($oneLine($mid, '2026-06-01', '1.000', '50.00'));
$service($emQ3)->finalize([$at($inv3->getId())]);
$before = [$paymentCount(), $allocationCount(), $journalCount()];
$ledgerRefusal = caught(fn() => $pay($wireDi())->record($draftOn($inv3->getId(), '2027-01-05', '20.00')), \Z77\Module\Debtor\Accounting\AccountingRefusedException::class);
check('Q10 a payment dated in a year the ledger has not opened (2027): the ledger refuses inside the unit of work — the payment and its allocation are rolled back, no entry, the open amount unchanged',
    $ledgerRefusal !== null && [$paymentCount(), $allocationCount(), $journalCount()] === $before && $service($wireDi())->openAmount($readInvoice($inv3->getId()))->toDecimal() === '54.05');
check('Q11 a partial payment leaves the rest open; a second one settles it', (function () use ($pay, $wireDi, $draftOn, $inv3, $service, $readInvoice): bool {
    $pay($wireDi())->record($draftOn($inv3->getId(), '2026-06-10', '20.00'));
    $afterFirst = $service($wireDi())->openAmount($readInvoice($inv3->getId()))->toDecimal();
    $pay($wireDi())->record($draftOn($inv3->getId(), '2026-06-20', '34.05'));
    return $afterFirst === '34.05' && $service($wireDi())->openAmount($readInvoice($inv3->getId()))->isZero();
})());
check('Q12 the entities are immutable: no setter on Payment / PaymentAllocation, an allocation of 0.00 is refused, a negative payment amount is refused, a second markPosted() throws',
    array_filter(get_class_methods(\Z77\Module\Debtor\Entities\Payment::class), fn($m) => str_starts_with($m, 'set')) === []
    && array_filter(get_class_methods(\Z77\Module\Debtor\Entities\PaymentAllocation::class), fn($m) => str_starts_with($m, 'set')) === []
    && throws(fn() => (new \Z77\Module\Debtor\Entities\Payment(day('2026-01-01'), chf('1.00'), 'qr', '1020', null, 'x', new \DateTimeImmutable()))->allocate($readInvoice($inv3->getId()), \Z77\Module\Debtor\Entities\AllocationKind::Payment, chf('0.00')), \InvalidArgumentException::class)
    && throws(fn() => new \Z77\Module\Debtor\Entities\Payment(day('2026-01-01'), chf('-1.00'), 'qr', '1020', null, 'x', new \DateTimeImmutable()), \InvalidArgumentException::class)
    && (function () use ($readInvoice, $inv3): bool { $a = (new \Z77\Module\Debtor\Entities\Payment(day('2026-01-01'), chf('1.00'), 'qr', '1020', null, 'x', new \DateTimeImmutable()))->allocate($readInvoice($inv3->getId()), \Z77\Module\Debtor\Entities\AllocationKind::Payment, chf('1.00')); $a->markPosted('2026/9'); return throws(fn() => $a->markPosted(null), \LogicException::class); })());

echo "Q. … the screen: «Zahlung erfassen» as a page form, the detail shows the settlements\n";
$emQ4 = $wireDi();
$inv4 = $service($emQ4)->invoice($oneLine($mid, '2026-06-02', '2.000', '50.00'));
$service($emQ4)->finalize([$at($inv4->getId())]);
$useRequest(['id' => $inv4->getId()]);
$host = $invoiceHost();
$host->paymentAction();
$formHtml = $renderMain($host);
check('Q13 GET: the form with the value date (today), the three amounts, the ACCOUNT with the postable accounts as datalist (the document\'s target account proposed), «Rest als Verlust», the open amount in the title',
    ($host->layoutManager->sections['main'] ?? []) === ['Backend/InvoiceController/payment'] && str_contains($formHtml, 'name="payment"') && str_contains($formHtml, 'name="discount"') && str_contains($formHtml, 'name="loss"')
    && str_contains($formHtml, 'name="account"') && str_contains($formHtml, 'list="payment-accounts"') && str_contains($formHtml, 'name="rest_loss"') && !str_contains($formHtml, 'name="target"')
    && str_contains($formHtml, 'offen ' . '108.10') && str_contains($formHtml, 'value="' . date('Y-m-d') . '"'));
$useRequest(['id' => $inv4->getId()], ['date' => '2026-06-15', 'payment' => 'abc', 'discount' => '', 'loss' => '', 'account' => '1020', 'note' => '']);
$host = $invoiceHost();
$host->paymentAction();
check('Q14 POST with an unreadable amount: the form comes back with the field error, nothing recorded', $host->redirectedTo === null && str_contains($renderMain($host), 'höchstens zwei Dezimalen') && $paymentCount() === 4);
$useRequest(['id' => $inv4->getId()], ['date' => '2026-06-15', 'payment' => '100.00', 'discount' => '8.10', 'loss' => '', 'account' => '1020', 'note' => 'Eingang']);
$host = $invoiceHost();
$host->paymentAction();
check('Q15 POST with 100.00 + 8.10 Skonto: recorded and posted, flash, redirect to the detail', $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $inv4->getId() && ($host->messageService->flashes[0][0] ?? '') === 'success'
    && str_contains($host->messageService->flashes[0][1], '108.10') && $paymentCount() === 5 && $service($wireDi())->openAmount($readInvoice($inv4->getId()))->isZero());
$useRequest(['id' => $inv4->getId()]);
$host = $invoiceHost();
$host->detailAction();
$detailHtml = $renderMain($host);
check('Q16 the detail: the «Zahlungen» row with both allocations and their journal references, «Offen» shows «bezahlt», no «Zahlung erfassen» button any more',
    str_contains($detailHtml, 'Zahlung 100.00') && str_contains($detailHtml, 'Konto 1020') && str_contains($detailHtml, 'Skonto 8.10') && str_contains($detailHtml, 'Buchung 2026/') && str_contains($detailHtml, '>bezahlt<')
    && str_contains($detailHtml, '&amp;payment=') && !str_contains($detailHtml, '/payment?id=' . $inv4->getId() . '"'));
$useRequest(['id' => $stillInvoicing->getId()], ['date' => '2026-06-15', 'payment' => '1.00', 'discount' => '', 'loss' => '', 'account' => '1020', 'note' => '']);
$host = $invoiceHost();
$host->paymentAction();
check('Q17 a document in invoicing: no form, back to the detail with the refusal; the detail of an OPEN final invoice offers the button', $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $stillInvoicing->getId() && ($host->messageService->flashes[0][0] ?? '') === 'error'
    && (function () use ($useRequest, $invoiceHost, $renderMain, $service, $wireDi, $oneLine, $mid, $at): bool {
        $em = $wireDi(); $inv = $service($em)->invoice($oneLine($mid, '2026-06-03', '1.000', '10.00')); $service($em)->finalize([$at($inv->getId())]);
        $useRequest(['id' => $inv->getId()]); $h = $invoiceHost(); $h->detailAction();
        return str_contains($renderMain($h), '/payment?id=' . $inv->getId());
    })());

echo "Q. … correcting and removing a settlement while the year is open (owner 2026-10-06): the postings change with it, logged in the journal\n";
$changeRows = fn(int $entryId) => $db->fetchAllAssociative('SELECT action, changed_by FROM journal_entry_change WHERE entry_id = ? ORDER BY id', [$entryId]);
$entryIdOf  = fn(string $ref) => (int) $db->fetchOne('SELECT e.id FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id WHERE CONCAT(y.code, \'/\', e.number) = ?', [$ref]);
$emU   = $wireDi();
$p4    = $pay($emU)->allocationsOf($readInvoice($inv4->getId()))[0]->getPayment();
$p4Id  = (int) $p4->getId();
$refsBefore = array_map(fn($a) => $a->getLedgerEntryRef(), $p4->getAllocations());
$journalBefore = $journalCount();
$updated = $pay($wireDi())->update($p4Id, 1, new PaymentDraft($inv4->getId(), day('2026-06-16'), chf('90.00'), chf('18.10'), chf('0.00'), '', 'per Kasse', '1000'));
$refsAfter = array_map(fn($a) => $a->getLedgerEntryRef(), $updated->getAllocations());
$pl = $entryLines((string) $refsAfter[0]);
$dl = $entryLines((string) $refsAfter[1]);
check('Q18 update() with the same kinds: the header revised (date, account 1000 = Kasse, note), version 2, the SAME journal numbers amended in place — 1000 DEBIT 90.00 | receivable CREDIT 90.00; the Skonto entry now 18.10 —, one change row update per entry, no new entry',
    $updated->getVersion() === 2 && $updated->getDate()->format('Y-m-d') === '2026-06-16' && $updated->getAccountNumber() === '1000' && $updated->getNote() === 'per Kasse' && $updated->getChangedBy() === 'kassier'
    && $refsAfter === $refsBefore && $journalCount() === $journalBefore
    && $pl[0]['account_number'] === '1000' && $pl[0]['debit'] === '90.00' && $pl[1]['credit'] === '90.00'
    && count(array_filter($dl, fn($r) => $r['account_number'] === $mandatorAccounts['account_receivable'] && $r['credit'] === '18.10')) === 1
    && array_column($changeRows($entryIdOf((string) $refsAfter[0])), 'action') === ['update'] && array_column($changeRows($entryIdOf((string) $refsAfter[1])), 'action') === ['update']
    && $service($wireDi())->openAmount($readInvoice($inv4->getId()))->isZero());
$discountEntryId = $entryIdOf((string) $refsAfter[1]);
$updated2 = $pay($wireDi())->update($p4Id, 2, new PaymentDraft($inv4->getId(), day('2026-06-16'), chf('90.00'), chf('0.00'), chf('18.10'), '', 'per Kasse', '1000'));
$kinds2   = array_map(fn($a) => $a->kind()->value, $updated2->getAllocations());
check('Q19 update() that drops the Skonto and adds a Verlust: the Skonto allocation withdrawn and its entry RETRACTED (gone, change row delete, number a gap), a loss allocation posted anew (a new number), the payment entry untouched',
    $kinds2 === ['payment', 'loss'] && $updated2->getVersion() === 3 && $db->fetchOne('SELECT COUNT(*) FROM journal_entry WHERE id = ?', [$discountEntryId]) == 0
    && array_column($changeRows($discountEntryId), 'action') === ['update', 'delete'] && $updated2->getAllocations()[0]->getLedgerEntryRef() === $refsAfter[0]
    && $updated2->getAllocations()[1]->getLedgerEntryRef() !== null && $updated2->getAllocations()[1]->getLedgerEntryRef() !== $refsAfter[1]
    && count(array_filter($entryLines((string) $updated2->getAllocations()[1]->getLedgerEntryRef()), fn($r) => $r['account_number'] === $mandatorAccounts['account_loss'])) >= 1
    && $service($wireDi())->openAmount($readInvoice($inv4->getId()))->isZero());
check('Q20 refusals of update(): a stale version (conflict), more than invoice + own allocations allow (over-allocation), an unknown payment (not-found), a draft for another invoice (not-found) — nothing changed',
    $payRefusal(fn() => $pay($wireDi())->update($p4Id, 1, new PaymentDraft($inv4->getId(), day('2026-06-16'), chf('90.00'), chf('0.00'), chf('18.10'), '', null, '1000'))) === PaymentRefusedException::CONFLICT
    && $payRefusal(fn() => $pay($wireDi())->update($p4Id, 3, new PaymentDraft($inv4->getId(), day('2026-06-16'), chf('200.00'), chf('0.00'), chf('0.00'), '', null, '1000'))) === PaymentRefusedException::OVER_ALLOCATION
    && $payRefusal(fn() => $pay($wireDi())->update(999999, 1, new PaymentDraft($inv4->getId(), day('2026-06-16'), chf('1.00'), chf('0.00'), chf('0.00'), '', null, '1000'))) === PaymentRefusedException::NOT_FOUND
    && $payRefusal(fn() => $pay($wireDi())->update($p4Id, 3, new PaymentDraft($inv3->getId(), day('2026-06-16'), chf('1.00'), chf('0.00'), chf('0.00'), '', null, '1000'))) === PaymentRefusedException::NOT_FOUND
    && $pay($wireDi())->find($p4Id)->getVersion() === 3);
$paymentEntryId = $entryIdOf((string) $refsAfter[0]);
$lossEntryId    = $entryIdOf((string) $updated2->getAllocations()[1]->getLedgerEntryRef());
$before = [$paymentCount(), $allocationCount(), $journalCount()];
$pay($wireDi())->delete($p4Id, 3);
check('Q21 delete(): both entries retracted (gone, change rows delete), the payment and its allocations gone, the invoice open again at 108.10; a stale delete → conflict, a second delete → not-found',
    [$paymentCount(), $allocationCount(), $journalCount()] === [$before[0] - 1, $before[1] - 2, $before[2] - 2]
    && $db->fetchOne('SELECT COUNT(*) FROM journal_entry WHERE id IN (?, ?)', [$paymentEntryId, $lossEntryId]) == 0
    && array_slice($changeRows($paymentEntryId), -1)[0]['action'] === 'delete' && array_slice($changeRows($lossEntryId), -1)[0]['action'] === 'delete'
    && $service($wireDi())->openAmount($readInvoice($inv4->getId()))->toDecimal() === '108.10'
    && $payRefusal(fn() => $pay($wireDi())->delete($p4Id, 3)) === PaymentRefusedException::NOT_FOUND);
// The screen: «Rest als Verlust», the account chosen freely, edit and delete through the pages.
$useRequest(['id' => $inv4->getId()], ['date' => '2026-06-20', 'payment' => '100.00', 'discount' => '', 'loss' => '', 'rest_loss' => '1', 'account' => '1020', 'note' => 'Rest ausgebucht']);
$host = $invoiceHost();
$host->paymentAction();
$restPayment = $pay($wireDi())->allocationsOf($readInvoice($inv4->getId()))[0]->getPayment();
check('Q22 «Rest als Verlust ausbuchen»: 100.00 paid, the remaining 8.10 goes to the loss account by itself — recorded, posted, the invoice settled',
    $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $inv4->getId() && array_map(fn($a) => $a->kind()->value . ':' . $a->getAmount()->toDecimal(), $restPayment->getAllocations()) === ['payment:100.00', 'loss:8.10']
    && $service($wireDi())->openAmount($readInvoice($inv4->getId()))->isZero());
$useRequest(['id' => $inv4->getId(), 'payment' => $restPayment->getId()]);
$host = $invoiceHost();
$host->paymentAction();
$editHtml = $renderMain($host);
check('Q23 GET the edit form (?payment=): prefilled with the payment\'s values, the entity token and the version, «Löschen …» offered',
    str_contains($editHtml, 'value="100.00"') && str_contains($editHtml, 'value="8.10"') && str_contains($editHtml, 'value="1020"') && str_contains($editHtml, 'value="Rest ausgebucht"')
    && str_contains($editHtml, 'name="version" value="' . $restPayment->getVersion() . '"') && str_contains($editHtml, 'tok-payment-' . $restPayment->getId()) && str_contains($editHtml, 'confirm-payment-delete?id='));
$useRequest(['id' => $inv4->getId(), 'payment' => $restPayment->getId()], ['entity_csrf' => 'tok-payment-' . $restPayment->getId(), 'version' => $restPayment->getVersion(), 'date' => '2026-06-20', 'payment' => '58.10', 'discount' => '', 'loss' => '50.00', 'account' => '1000', 'note' => 'Kasse']);
$host = $invoiceHost();
$host->paymentAction();
$edited = $pay($wireDi())->find((int) $restPayment->getId());
check('Q24 POST the edit: amended — 58.10 per Kasse (1000) and 50.00 loss, the same journal numbers, flash, redirect; a POST with a wrong entity token is sent back with the conflict flash',
    $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $inv4->getId() && ($host->messageService->flashes[0][0] ?? '') === 'success' && str_contains($host->messageService->flashes[0][1], 'geändert')
    && $edited->getAccountNumber() === '1000' && array_map(fn($a) => $a->getAmount()->toDecimal(), $edited->getAllocations()) === ['58.10', '50.00']
    && array_map(fn($a) => $a->getLedgerEntryRef(), $edited->getAllocations()) === array_map(fn($a) => $a->getLedgerEntryRef(), $restPayment->getAllocations())
    && (function () use ($useRequest, $invoiceHost, $inv4, $edited): bool {
        $useRequest(['id' => $inv4->getId(), 'payment' => $edited->getId()], ['entity_csrf' => 'wrong', 'version' => $edited->getVersion(), 'date' => '2026-06-20', 'payment' => '1.00', 'discount' => '', 'loss' => '', 'account' => '1000', 'note' => '']);
        $h = $invoiceHost(); $h->paymentAction();
        return $h->redirectedTo !== null && ($h->messageService->flashes[0][0] ?? '') === 'error';
    })());
$useRequest(['id' => $inv4->getId(), 'payment' => $edited->getId()]);
$host = $invoiceHost();
$host->confirmPaymentDeleteAction();
$confirmHtml = $renderMain($host);
$useRequest(['id' => $inv4->getId(), 'payment' => $edited->getId()], ['entity_csrf' => 'tok-payment-' . $edited->getId(), 'version' => $edited->getVersion()]);
$host = $invoiceHost();
$host->paymentDeleteAction();
check('Q25 the confirmation page names the postings that go; the POST deletes — flash, redirect, the invoice open again at 108.10, the payment gone',
    str_contains($confirmHtml, 'Zahlung löschen') && str_contains($confirmHtml, 'wird gelöscht') && str_contains($confirmHtml, 'name="version"')
    && $host->redirectedTo === '/backend/finance/invoice/detail?id=' . $inv4->getId() && ($host->messageService->flashes[0][0] ?? '') === 'success'
    && $pay($wireDi())->find((int) $edited->getId()) === null && $service($wireDi())->openAmount($readInvoice($inv4->getId()))->toDecimal() === '108.10');

// ── R. P4 part 2: the CAMT.054 import (plan §6.4) ──────────────────────

echo "R. CamtReader: a camt.054 file becomes data — header, IBAN, every entry flagged\n";
use Z77\Module\Debtor\Payments\CamtReader;
use Z77\Module\Debtor\Services\BankImportService;
use Z77\Module\Debtor\Services\BankImportRefusedException;
use Z77\Module\Debtor\Entities\BankMessage;
use Z77\Module\Debtor\Entities\BankTransaction;
/** A camt.054.001.08 file as a Swiss bank sends it, reduced to what the reader reads. */
$camtXml = function (string $msgId, array $txs, string $iban = '') use ($lower): string {
    $iban = $iban !== '' ? $iban : $lower;
    $entries = '';
    foreach ($txs as $i => $t) {
        $amount = $t['amount'];
        $ccy    = $t['ccy'] ?? 'CHF';
        $rmt    = '';
        if (!empty($t['ref'])) {
            $rmt = '<RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>' . ($t['type'] ?? 'QRR') . '</Prtry></CdOrPrtry></Tp><Ref>' . $t['ref'] . '</Ref></CdtrRefInf></Strd></RmtInf>';
        } elseif (!empty($t['ustrd'])) {
            $rmt = '<RmtInf><Ustrd>' . htmlspecialchars($t['ustrd'], ENT_XML1) . '</Ustrd></RmtInf>';
        }
        $entries .= '<Ntry><Amt Ccy="' . $ccy . '">' . $amount . '</Amt><CdtDbtInd>' . (($t['debit'] ?? false) ? 'DBIT' : 'CRDT') . '</CdtDbtInd>'
            . (!empty($t['reversal']) ? '<RvslInd>true</RvslInd>' : '')
            . '<Sts>BOOK</Sts><BookgDt><Dt>' . $t['date'] . '</Dt></BookgDt><ValDt><Dt>' . $t['date'] . '</Dt></ValDt><AcctSvcrRef>' . ($t['txid'] ?? ('TX' . ($i + 1))) . '</AcctSvcrRef>'
            . '<NtryDtls><TxDtls><Refs><AcctSvcrRef>' . ($t['txid'] ?? ('TX' . ($i + 1))) . '</AcctSvcrRef><EndToEndId>NOTPROVIDED</EndToEndId></Refs><Amt Ccy="' . $ccy . '">' . $amount . '</Amt>'
            . '<RltdPties><Dbtr><Nm>' . htmlspecialchars($t['name'] ?? 'Muster GmbH', ENT_XML1) . '</Nm><PstlAdr><TwnNm>' . htmlspecialchars($t['city'] ?? 'Bern', ENT_XML1) . '</TwnNm></PstlAdr></Dbtr></RltdPties>'
            . $rmt . '</TxDtls></NtryDtls></Ntry>';
    }
    return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.08"><BkToCstmrDbtCdtNtfctn><GrpHdr><MsgId>' . $msgId . '</MsgId><CreDtTm>2026-07-02T06:15:00</CreDtTm></GrpHdr>'
        . '<Ntfctn><Id>' . $msgId . '-N</Id><CreDtTm>2026-07-02T06:15:00</CreDtTm><Acct><Id><IBAN>' . $iban . '</IBAN></Id></Acct>' . $entries . '</Ntfctn></BkToCstmrDbtCdtNtfctn></Document>';
};
$emR  = $wireDi();
$invA = $service($emR)->invoice($oneLine($mid, '2026-06-05', '1.000', '100.00'));
$invB = $service($emR)->invoice($oneLine($mid, '2026-06-06', '1.000', '50.00'));
$invC = $service($emR)->invoice($oneLine($mid, '2026-06-07', '1.000', '100.00'));
$service($emR)->finalize([$at($invA->getId()), $at($invB->getId()), $at($invC->getId())]);
$qrrOf = fn(int $customer, int $number) => QrReference::forDocument($customer, $number);
$fileR = CamtReader::read($camtXml('MSG-R1', [
    ['amount' => '108.10', 'date' => '2026-07-01', 'ref' => $qrrOf(1000, $invA->getNumber()), 'txid' => 'A1'],
    ['amount' => '54.05', 'date' => '2026-07-01', 'ustrd' => 'Zahlung Rechnung ' . $invB->getNumber() . ' Danke', 'txid' => 'B1'],
    ['amount' => '20.00', 'date' => '2026-07-01', 'ref' => $qrrOf(1000, 999999), 'txid' => 'C1'],
    ['amount' => '20.00', 'date' => '2026-07-01', 'ref' => substr($qrrOf(1000, $invA->getNumber()), 0, 26) . (((int) substr($qrrOf(1000, $invA->getNumber()), -1) + 1) % 10), 'txid' => 'D1'],
    ['amount' => '20.00', 'date' => '2026-07-01', 'ref' => $qrrOf(999999, $invA->getNumber()), 'txid' => 'E1'],
    ['amount' => '12.00', 'date' => '2026-07-01', 'debit' => true, 'txid' => 'F1', 'ustrd' => 'Gebühren'],
    ['amount' => '7.00', 'date' => '2026-07-01', 'ustrd' => 'ohne Angabe', 'txid' => 'G1'],
]));
check('R1 the reader: message id, creation date, the IBAN normalized, seven entries in order with tx ref, amount, currency, the reference type (QRR / NON), the reference, the message, the debtor; the debit flagged',
    $fileR->messageId === 'MSG-R1' && $fileR->createdOn->format('Y-m-d') === '2026-07-02' && $fileR->iban === $lower && count($fileR->entries) === 7
    && $fileR->entries[0]->txRef === 'A1' && $fileR->entries[0]->amount === '108.10' && $fileR->entries[0]->currency === 'CHF' && $fileR->entries[0]->referenceType === 'QRR' && $fileR->entries[0]->reference === $qrrOf(1000, $invA->getNumber()) && $fileR->entries[0]->isCredit
    && $fileR->entries[1]->referenceType === 'NON' && str_contains($fileR->entries[1]->remittance, 'Rechnung ' . $invB->getNumber()) && $fileR->entries[1]->debtorName === 'Muster GmbH' && $fileR->entries[1]->debtorCity === 'Bern'
    && !$fileR->entries[5]->isCredit && $fileR->entries[0]->valueDate->format('Y-m-d') === '2026-07-01');
check('R2 the reader refuses what is not XML (not-xml) and XML that is no camt.054 (not-camt054)',
    caught(fn() => CamtReader::read('nope'), BankImportRefusedException::class)?->reason === BankImportRefusedException::NOT_XML
    && caught(fn() => CamtReader::read('<?xml version="1.0"?><Document><Other/></Document>'), BankImportRefusedException::class)?->reason === BankImportRefusedException::NOT_CAMT054);

echo "R. … import: stored once, every credit matched or set aside with the reason\n";
$bank   = fn(UnifiedEntityManager $em) => new BankImportService($em, 'bankimport');
$msgR1  = $bank($wireDi())->import($camtXml('MSG-R1', [
    ['amount' => '108.10', 'date' => '2026-07-01', 'ref' => $qrrOf(1000, $invA->getNumber()), 'txid' => 'A1'],
    ['amount' => '54.05', 'date' => '2026-07-01', 'ustrd' => 'Zahlung Rechnung ' . $invB->getNumber() . ' Danke', 'txid' => 'B1'],
    ['amount' => '20.00', 'date' => '2026-07-01', 'ref' => $qrrOf(1000, 999999), 'txid' => 'C1'],
    ['amount' => '20.00', 'date' => '2026-07-01', 'ref' => substr($qrrOf(1000, $invA->getNumber()), 0, 26) . (((int) substr($qrrOf(1000, $invA->getNumber()), -1) + 1) % 10), 'txid' => 'D1'],
    ['amount' => '20.00', 'date' => '2026-07-01', 'ref' => $qrrOf(999999, $invA->getNumber()), 'txid' => 'E1'],
    ['amount' => '12.00', 'date' => '2026-07-01', 'debit' => true, 'txid' => 'F1', 'ustrd' => 'Gebühren'],
    ['amount' => '7.00', 'date' => '2026-07-01', 'ustrd' => 'ohne Angabe', 'txid' => 'G1'],
]), 'camt054-r1.xml');
$txR1   = $msgR1->getTransactions();
$stateR = fn(int $i) => $txR1[$i]->state()->value;
check('R3 the message row: id, IBAN, the payment target the IBAN resolved to (qr), file and importer; seven transactions stored with their positions',
    $msgR1->getId() !== null && $msgR1->getMessageId() === 'MSG-R1' && $msgR1->getIban() === $lower && $msgR1->getPaymentTargetCode() === 'qr' && $msgR1->getFileName() === 'camt054-r1.xml' && $msgR1->getImportedBy() === 'bankimport'
    && $count('bank_message') === 1 && $count('bank_transaction') === 7 && array_map(fn($t) => $t->getPosition(), $txR1) === [1, 2, 3, 4, 5, 6, 7]);
check('R4 matching: a valid QRR (customer 1000 + the document number) → matched to that invoice; a NON naming «Rechnung n» → matched with a note; an unknown document, a wrong check digit, another customer → unmatched with the reason; a debit → ignored; no reference and no number → unmatched',
    $stateR(0) === 'matched' && $txR1[0]->getInvoice()?->getId() === $invA->getId() && $txR1[0]->getNote() === null
    && $stateR(1) === 'matched' && $txR1[1]->getInvoice()?->getId() === $invB->getId() && str_contains((string) $txR1[1]->getNote(), 'Mitteilung')
    && $stateR(2) === 'unmatched' && str_contains((string) $txR1[2]->getNote(), 'nicht gibt')
    && $stateR(3) === 'unmatched' && str_contains((string) $txR1[3]->getNote(), 'ungültig')
    && $stateR(4) === 'unmatched' && str_contains((string) $txR1[4]->getNote(), 'Kundennummer 999999')
    && $stateR(5) === 'ignored' && str_contains((string) $txR1[5]->getNote(), 'Belastung')
    && $stateR(6) === 'unmatched' && $msgR1->countPerState() === ['unmatched' => 4, 'matched' => 2, 'booked' => 0, 'ignored' => 1]);
$before = [$count('bank_message'), $count('bank_transaction')];
// An IBAN of no target at all — the raw rows of Q7 carry CH93…, so a fresh QR-IBAN with an IID nobody uses.
check('R5 the same message again → duplicate-message (naming the earlier import); an IBAN that is no active payment target → target-unknown; nothing written',
    caught(fn() => $bank($wireDi())->import($camtXml('MSG-R1', [['amount' => '1.00', 'date' => '2026-07-01', 'ustrd' => 'x']]), 'again.xml'), BankImportRefusedException::class)?->reason === BankImportRefusedException::DUPLICATE_MESSAGE
    && caught(fn() => $bank($wireDi())->import($camtXml('MSG-R2', [['amount' => '1.00', 'date' => '2026-07-01', 'ustrd' => 'x']], $withIid('30500')), 'other.xml'), BankImportRefusedException::class)?->reason === BankImportRefusedException::TARGET_UNKNOWN
    && [$count('bank_message'), $count('bank_transaction')] === $before);

echo "R. … book: a payment per matched transaction, in one unit of work; two credits on one invoice; the overpayment remainder; already settled\n";
$journalBefore = $journalCount();
$bookedR1 = $bank($wireDi())->book((int) $msgR1->getId());
$txB      = $bookedR1->getTransactions();
$payRow   = fn(int $id) => $db->fetchAssociative('SELECT * FROM payment WHERE id = ?', [$id]);
check('R6 book(): the two matched transactions are booked — a payment each (source camt, ref camt:{msg}:{tx}, the value date, the target\'s account 1020), two journal entries, both invoices settled; the unmatched and ignored ones untouched',
    $txB[0]->state()->value === 'booked' && $txB[1]->state()->value === 'booked' && $txB[0]->getPayment() !== null && $txB[1]->getPayment() !== null
    && $payRow((int) $txB[0]->getPayment()->getId())['source_type'] === 'camt' && $payRow((int) $txB[0]->getPayment()->getId())['source_ref'] === 'camt:MSG-R1:A1'
    && $payRow((int) $txB[0]->getPayment()->getId())['payment_date'] === '2026-07-01' && $payRow((int) $txB[0]->getPayment()->getId())['account_number'] === '1020' && $payRow((int) $txB[0]->getPayment()->getId())['payment_target_code'] === 'qr'
    && $journalCount() === $journalBefore + 2 && $service($wireDi())->openAmount($readInvoice($invA->getId()))->isZero() && $service($wireDi())->openAmount($readInvoice($invB->getId()))->isZero()
    && $txB[0]->getRemainder()->isZero() && $txB[2]->state()->value === 'unmatched' && $txB[5]->state()->value === 'ignored'
    && $bookedR1->countPerState() === ['unmatched' => 4, 'matched' => 0, 'booked' => 2, 'ignored' => 1]);
$msgR3 = $bank($wireDi())->import($camtXml('MSG-R3', [
    ['amount' => '50.00', 'date' => '2026-07-03', 'ref' => $qrrOf(1000, $invC->getNumber()), 'txid' => 'C-first'],
    ['amount' => '70.00', 'date' => '2026-07-03', 'ref' => $qrrOf(1000, $invC->getNumber()), 'txid' => 'C-second'],
    ['amount' => '30.00', 'date' => '2026-07-03', 'ref' => $qrrOf(1000, $invA->getNumber()), 'txid' => 'A-again'],
]), 'camt054-r3.xml');
$bookedR3 = $bank($wireDi())->book((int) $msgR3->getId());
$txC      = $bookedR3->getTransactions();
check('R7 two credits for ONE invoice in one file (108.10 open): the first books 50.00, the second sees 58.10 open and books that — 11.90 stay as its REMAINDER (overpayment, noted); the invoice is settled, not overpaid',
    $txC[0]->state()->value === 'booked' && $txC[0]->getPayment()?->getAmount()->toDecimal() === '50.00' && $txC[0]->getRemainder()->isZero()
    && $txC[1]->state()->value === 'booked' && $txC[1]->getPayment()?->getAmount()->toDecimal() === '58.10' && $txC[1]->getRemainder()->toDecimal() === '11.90' && str_contains((string) $txC[1]->getNote(), 'Überzahlung')
    && $service($wireDi())->openAmount($readInvoice($invC->getId()))->isZero());
check('R8 a credit for an invoice already settled books NOTHING: the transaction goes back to unmatched with the reason, nothing posted for it',
    $txC[2]->state()->value === 'unmatched' && $txC[2]->getPayment() === null && str_contains((string) $txC[2]->getNote(), 'bereits beglichen')
    && $bookedR3->countPerState()['booked'] === 2);
check('R9 book() with nothing matched → nothing-to-book; an unknown message → not-found',
    caught(fn() => $bank($wireDi())->book((int) $msgR3->getId()), BankImportRefusedException::class)?->reason === BankImportRefusedException::NOTHING_TO_BOOK
    && caught(fn() => $bank($wireDi())->book(999999), BankImportRefusedException::class)?->reason === BankImportRefusedException::NOT_FOUND);

echo "R. … assign and ignore: the office steers what the file could not say\n";
$invD = $service($wireDi())->invoice($oneLine($mid, '2026-06-08', '1.000', '10.00'));
$service($wireDi())->finalize([$at($invD->getId())]);
$txG  = $txB[6];   // 7.00 «ohne Angabe», unmatched
$assigned = $bank($wireDi())->assign((int) $txG->getId(), $invD->getNumber());
check('R10 assign(): an unmatched credit named to a final invoice by document number → matched («Manuell zugeordnet»); an unknown number → invoice-unknown; a document in invoicing → invoice-not-final; a booked transaction → state',
    $assigned->state()->value === 'matched' && $assigned->getInvoice()?->getId() === $invD->getId() && $assigned->getNote() === 'Manuell zugeordnet.'
    && caught(fn() => $bank($wireDi())->assign((int) $txB[2]->getId(), 999999), BankImportRefusedException::class)?->reason === BankImportRefusedException::INVOICE_UNKNOWN
    && caught(fn() => $bank($wireDi())->assign((int) $txB[2]->getId(), $stillInvoicing->getNumber()), BankImportRefusedException::class)?->reason === BankImportRefusedException::INVOICE_NOT_FINAL
    && caught(fn() => $bank($wireDi())->assign((int) $txB[0]->getId(), $invD->getNumber()), BankImportRefusedException::class)?->reason === BankImportRefusedException::STATE);
$ignored = $bank($wireDi())->ignore((int) $txB[2]->getId(), true);
$back    = $bank($wireDi())->ignore((int) $txB[2]->getId(), false, 'doch prüfen');
check('R11 ignore() sets a credit aside and takes it back; the booked assignment then books 7.00 on the 10.80 invoice (3.80 stays open)',
    $ignored->state()->value === 'ignored' && $back->state()->value === 'unmatched' && $back->getNote() === 'doch prüfen'
    && $bank($wireDi())->book((int) $msgR1->getId())->countPerState()['booked'] === 3 && $service($wireDi())->openAmount($readInvoice($invD->getId()))->toDecimal() === '3.80');

echo "R. … the close check: an unbooked credit dated in the year BLOCKS\n";
$unbooked2026 = (int) $db->fetchOne("SELECT COUNT(*) FROM bank_transaction WHERE state IN ('unmatched', 'matched') AND value_date BETWEEN '2026-01-01' AND '2026-12-31'");
$ask2026      = $ycAsk('2026-01-01', '2026-12-31');
$bankFindings = array_values(array_filter($ask2026->blocking(), fn($f) => str_starts_with($f->reference, 'bank-transaction:')));
check('R12 UnbookedTransactionsCheck: one BLOCKING finding per unbooked transaction of 2026 (bank-transaction:{id}, the message named), none for 2025; a booked or ignored one does not count',
    $unbooked2026 > 0 && count($bankFindings) === $unbooked2026 && str_contains($bankFindings[0]->message, 'MSG-R') && str_contains($bankFindings[0]->message, 'nicht verbucht')
    && array_filter($ycAsk('2025-01-01', '2025-12-31')->blocking(), fn($f) => str_starts_with($f->reference, 'bank-transaction:')) === []);

echo "R. … the screens: list with the upload, the message detail, the actions through the host\n";
$bankHost = function () {
    return new class {
        use \Z77\Module\Debtor\Ui\BankImportControllerTrait { listAction as public; uploadAction as public; detailAction as public; bookAction as public; assignAction as public; ignoreAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public ?string $redirectedTo = null;
        public function __construct()
        {
            $this->layoutManager = new class {
                public array $sections = [];
                public function removeSection(string $s): void { unset($this->sections[$s]); }
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
        private function bankImportService(): BankImportService { return new BankImportService($this->em(), 'bankimport'); }
        private function bankInvoicingService(): InvoicingService { return new InvoicingService($this->em(), 'bankimport'); }
    };
};
$useRequest([]);
$host = $bankHost();
$host->listAction();
$listHtml = $renderer->partial('Backend/BankImportController/listAction', $host->context);
check('R13 the list: the upload form (file, csrf) and the imported messages with their counts, newest first',
    str_contains($listHtml, 'name="file"') && str_contains($listHtml, 'enctype="multipart/form-data"') && str_contains($listHtml, 'MSG-R3') && str_contains($listHtml, 'MSG-R1')
    && strpos($listHtml, 'MSG-R3') < strpos($listHtml, 'MSG-R1') && count($host->context['messages']) === 2);
$useRequest(['id' => $msgR1->getId()]);
$host = $bankHost();
$host->detailAction();
$detailHtml = implode('', array_map(fn($p) => $renderer->partial($p, $host->context), $host->layoutManager->sections['main'] ?? []));
check('R14 the detail: every transaction with its state badge, the matched invoice linked with its open amount, the remainder badge where there is one, the assign / ignore forms on the ones not booked, no «Verbuchen» without a matched one',
    substr_count($detailHtml, 'data-bank-transaction=') === 7 && str_contains($detailHtml, 'data-state="booked"') && str_contains($detailHtml, 'data-state="unmatched"')
    && str_contains($detailHtml, '/backend/finance/invoice/detail?id=' . $invA->getId()) && str_contains($detailHtml, 'name="number"') && str_contains($detailHtml, 'Ignorieren')
    && !str_contains($detailHtml, 'Verbuchen ('));
$tmpXml = $base . '/upload-r4.xml';
file_put_contents($tmpXml, $camtXml('MSG-R4', [['amount' => '3.80', 'date' => '2026-07-05', 'ref' => $qrrOf(1000, $invD->getNumber()), 'txid' => 'D-rest']]));
$GLOBALS['z77TestUpload'] = ['file' => new \Z77\Shared\ValueObjects\UploadedFile('camt054-r4.xml', $tmpXml, filesize($tmpXml), 'text/xml')];
$useRequest([], []);
$host = $bankHost();
$host->uploadAction();
$msgR4 = $bank($wireDi())->recent(1)[0];
check('R15 upload (POST with a file): imported, flash with the counts, redirect to the new message\'s detail', $msgR4->getMessageId() === 'MSG-R4' && $host->redirectedTo === '/backend/finance/bank-import/detail?id=' . $msgR4->getId()
    && ($host->messageService->flashes[0][0] ?? '') === 'success' && str_contains($host->messageService->flashes[0][1], '1 zugeordnet'));
$GLOBALS['z77TestUpload'] = [];
$useRequest([], []);
$host = $bankHost();
$host->uploadAction();
check('R16 upload without a file → error flash, back to the list', $host->redirectedTo === '/backend/finance/bank-import/list' && ($host->messageService->flashes[0][0] ?? '') === 'error');
$useRequest(['id' => $msgR4->getId()], []);
$host = $bankHost();
$host->bookAction();
check('R17 «Verbuchen» through the host: the 3.80 lands on the 3.80 open invoice — settled; flash, redirect to the detail',
    $host->redirectedTo === '/backend/finance/bank-import/detail?id=' . $msgR4->getId() && ($host->messageService->flashes[0][0] ?? '') === 'success'
    && $service($wireDi())->openAmount($readInvoice($invD->getId()))->isZero());
$useRequest(['id' => $msgR1->getId()], ['transaction' => $txB[4]->getId(), 'value' => '1']);
$host = $bankHost();
$host->ignoreAction();
$useRequest(['id' => $msgR1->getId()], ['transaction' => $txB[3]->getId(), 'number' => (string) $invB->getNumber()]);
$host2 = $bankHost();
$host2->assignAction();
check('R18 ignore and assign through the host: flashes and redirects, the states changed',
    ($host->messageService->flashes[0][0] ?? '') === 'success' && $db->fetchOne('SELECT state FROM bank_transaction WHERE id = ?', [$txB[4]->getId()]) === 'ignored'
    && ($host2->messageService->flashes[0][0] ?? '') === 'success' && $db->fetchOne('SELECT state FROM bank_transaction WHERE id = ?', [$txB[3]->getId()]) === 'matched');
check('R19 source guards: the trait never persists itself, every write goes through BankImportService; the templates carry no script; the host and its config exist; the seed puts «Zahlungseingänge» under «Aufträge»',
    !str_contains(file_get_contents($package . '/src/Ui/BankImportControllerTrait.php'), '->persist(')
    && array_reduce(glob($package . '/res/view/templates/Backend/BankImportController/*.tpl.php'), fn($ok, $f) => $ok && !preg_match('/<script|\son[a-z]+\s*=/i', file_get_contents($f)), true)
    && is_file($pkgRoot . '/module-backend/src/Ui/Controllers/Finance/BankImportController.php') && is_file($pkgRoot . '/module-backend/src/Ui/Config/Finance/bankImportControllerConfig.inc.php')
    && (function () use ($package): bool {
        foreach (json_decode(file_get_contents($package . '/data/framework/routing/navigation.d/module-debtor.json'), true) as $row) {
            if (($row['key'] ?? '') === 'zahlungseingaenge') { return $row['parent_key'] === 'auftraege' && $row['controller'] === 'bank-import' && $row['action'] === 'list'; }
        }
        return false;
    })());

echo "P3C. Source guards for part 3\n";
$p3Templates = glob($package . '/res/view/templates/Backend/InvoiceController/*.tpl.php');
check('P3C48 the document screens ship no JavaScript and no inline handler (Rule 7); no module-financial class in debtor but the adapter (the journal is linked by URL)',
    count($p3Templates) === 8 && array_reduce($p3Templates, fn($ok, $f) => $ok && !preg_match('/<script|\son[a-z]+\s*=/i', file_get_contents($f)), true)
    && !str_contains(file_get_contents($package . '/src/Ui/InvoiceControllerTrait.php'), 'Module\\Financial'));
check('P3C49 the module brings no PDF library of its own (owner 2026-10-06: ONE writer, vendored in the kernel behind the facade): no composer requirement, no \\FPDF / PdfWriter use in its sources or templates — the facade only',
    !preg_match('/tcpdf|fpdf|dompdf|mpdf|swiss-qr-bill/i', (string) file_get_contents($package . '/composer.json'))
    && array_reduce(array_merge(glob($package . '/src/*/*.php'), glob($package . '/res/view/templates/pdf/*.tpl.php')), fn($ok, $f) => $ok && !preg_match('/\\\\FPDF\\b|PdfWriter|new FPDF/', file_get_contents($f)), true)
    && str_contains(file_get_contents($package . '/src/Pdf/InvoicePdf.php'), 'PdfDocument::create('));
check('P3C50 the shared pickers exist once: module-vat\'s taxCodeSelect + TaxCodeRepository::selectable() (the journal forms read the same list), module-mandator\'s accountDatalist (the mandator screen uses it too)',
    is_file($pkgRoot . '/module-vat/res/view/templates/partials/taxCodeSelect.tpl.php') && method_exists(\Z77\Module\Vat\Repositories\TaxCodeRepository::class, 'selectable')
    && str_contains(file_get_contents($pkgRoot . '/module-financial/src/Ui/ManualEntryForm.php'), '->selectable(')
    && str_contains(file_get_contents($pkgRoot . '/module-mandator/res/view/templates/Backend/MandatorController/edit.tpl.php'), "partials/accountDatalist"));

echo "P3C. Review 2026-09-30: the legacy QR-IBAN row, the amount search, QrBill's refusals, the double submit, the journal window by ref\n";
// A payment target written BEFORE the second IBAN field: a QR-IBAN in `iban`, no `qr_iban` key at all.
$targetsFile = $base . '/data/framework/debtor/payment_targets.json';
$rows        = json_decode(file_get_contents($targetsFile), true);
$legacyIban  = $withIid('30700');
$rows[]      = ['id' => max(array_column($rows, 'id')) + 1, 'code' => 'legacy', 'label' => 'Altes QR-Konto', 'iban' => $legacyIban, 'account_number' => '1020', 'active' => true];
file_put_contents($targetsFile, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$emL    = $wireDi();
$legacy = $emL->getRepository(PaymentTarget::class)->findByCode('legacy');
$legInv = $service($emL)->invoice($withTarget('legacy', '2026-08-01'));
check('P3C51 a LEGACY row (QR-IBAN in `iban`, no `qr_iban` key) is read as a QR-IBAN target: the document snapshots QRR with it, not NON',
    $legacy->getQrIban() === $legacyIban && $legacy->getIban() === '' && $invoiceRow($legInv->getId())['pay_reference_type'] === 'QRR'
    && $invoiceRow($legInv->getId())['pay_account'] === $legacyIban && QrBill::of($readInvoice($legInv->getId()))->isPrintable());
(new DebtorMasterData($emL))->saveTarget($legacy);
$savedRow = array_values(array_filter(json_decode(file_get_contents($targetsFile), true), fn($r) => $r['code'] === 'legacy'))[0];
check('P3C52 … and it re-saves without a hand edit, now in the new shape (qr_iban set, iban empty); a FORM post (both keys) with a QR-IBAN in the IBAN field is still refused',
    $savedRow['qr_iban'] === $legacyIban && $savedRow['iban'] === ''
    && str_contains((string) $tv(fn() => (new DebtorMasterData($wireDi()))->saveTarget(new PaymentTarget(['code' => 'swap3', 'label' => 'x', 'iban' => $withIid('30800'), 'qr_iban' => '', 'account_number' => '1020'])))?->getFieldError('iban'), 'QR-IBAN'));

$huge = \Z77\Module\Debtor\Ui\InvoiceFilter::fromQuery(['f_amount' => '99999999999999999999', 'f_name' => str_repeat('x', 200)], 'CHF');
check('P3C53 the amount search refuses a number Money cannot hold (invalid, no 500); the name search is bounded to the 80 characters kept',
    $huge->isInvalid('f_amount') && $huge->search()->amount === null && mb_strlen((string) $huge->search()->name) === 80 && mb_strlen($huge->value('f_name')) === 80);

/** The document with a HAND-BUILT payment part — reflection, never persisted: QrBill's refusals one by one. */
$withPayment = function (Invoice $document, array $values): Invoice {
    $payment = clone $document->getPayment();
    foreach ($values as $property => $value) {
        (new \ReflectionProperty(PaymentSnapshot::class, $property))->setValue($payment, $value);
    }
    (new \ReflectionProperty(Invoice::class, 'payment'))->setValue($document, $payment);
    return $document;
};
$problemsOf = fn(array $values, ?int $id = null) => implode(' ', QrBill::of($withPayment($readInvoice($id ?? $qrInv->getId()), $values))->problems());
check('P3C54 QrBill refuses what the specification forbids: QRR with a plain IBAN; a QR-IBAN with NON; an invalid IBAN; a QRR with a wrong check digit; a message over 140',
    str_contains($problemsOf(['account' => 'CH9300762011623852957']), 'verlangt eine QR-IBAN')
    && str_contains($problemsOf(['referenceType' => 'NON', 'reference' => '']), 'nie ohne Referenz')
    && str_contains($problemsOf(['account' => 'CH9300762011623852975']), 'keine gültige')
    && str_contains($problemsOf(['reference' => substr($qrRow['pay_reference'], 0, 26) . ((int) substr($qrRow['pay_reference'], -1) + 1) % 10]), 'QR-Referenz ist ungültig')
    && str_contains($problemsOf(['message' => str_repeat('m', 141)]), 'Mitteilung'));
$maxBill = QrBill::of($withPayment($readInvoice($qrInv->getId()), [
    'message' => str_repeat('m', 140), 'creditorName' => str_repeat('N', 70), 'creditorStreet' => str_repeat('S', 70), 'creditorHouseNo' => str_repeat('1', 16),
    'creditorZip' => str_repeat('2', 16), 'creditorCity' => str_repeat('C', 35),
]));
check('P3C55 the 997 limit is a guard: a bill with every field at its maximum stays printable and under 997 (the per-field limits keep it there)',
    $maxBill->isPrintable() && mb_strlen($maxBill->payload()) <= QrBill::MAX_PAYLOAD && $specErrors($maxBill->payload()) === []);
check('P3C56 a FINAL document without a payment part is not told to «neu fakturieren» — the way out is a credit note and a new invoice',
    str_contains($problemsOf(['referenceType' => '', 'reference' => '', 'account' => '', 'targetCode' => '']), 'Gutschrift und neue Rechnung')
    && str_contains(implode(' ', QrBill::of($readInvoice($plainInv->getId()))->problems()), 'neu fakturieren'));

$journalBefore = $journalCount();
$useRequest([], ['doc' => [$pairA, $pairB2]]);   // the batch of P3C41 submitted a second time
$host = $invoiceHost();
$host->finalizeAction();
check('P3C57 a DOUBLE submit of a finalize batch: «bereits definitiv» (not «nichts wurde verbucht»), nothing posted twice, to the «Definitiv» view',
    $journalCount() === $journalBefore && $host->messageService->flashes[0][0] === 'error' && str_contains($host->messageService->flashes[0][1], 'Bereits definitiv')
    && $host->redirectedTo === '/backend/finance/invoice/list?view=final');

$journalHost = function () {
    return new class {
        use \Z77\Module\Financial\Ui\JournalControllerTrait { detailAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public ?string $redirectedTo = null;
        public function __construct()
        {
            $this->layoutManager = new class { public array $sections = []; public function removeSection(string $s): void { unset($this->sections[$s]); } public function addPartials(string $n, string $p, string $ns, string $s = 'main'): void { $this->sections[$s][] = $p . '/' . $n; } };
            $this->messageService = new class { public array $flashes = []; public function pushFlashAfterRedirect(string $t, string $m): void { $this->flashes[] = [$t, $m]; } };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    };
};
$financialDetail = function (array $get) use ($useRequest, $journalHost, $pkgRoot): array {
    $GLOBALS['z77TestFetch'] = true;
    $useRequest($get);
    $host = $journalHost();
    $host->detailAction();
    $GLOBALS['z77TestFetch'] = false;
    $html = $host->redirectedTo !== null ? '' : (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })
        ->call($host, $pkgRoot . '/module-financial/res/view/templates/Backend/JournalController/detail.tpl.php', $host->context);
    preg_match('/data-window="[^"]*" data-window-entity="[^"]*"/', $html, $m);
    return [$host, $m[0] ?? ''];
};
[$byRef, $refIdentity] = $financialDetail(['ref' => $ledgerRef]);
[$byId, $idIdentity]   = $financialDetail(['id' => (string) $byRef->context['entry']->getId()]);
[$bad]                 = $financialDetail(['ref' => '2026/0']);
check('P3C58 the journal window opened by ?ref= has the SAME identity as one opened by id (mask journal-entry-detail, entity journal-entry:<id>); a malformed ref is «not found»',
    $refIdentity !== '' && $refIdentity === $idIdentity && str_contains($refIdentity, 'journal-entry:' . $byRef->context['entry']->getId())
    && $byRef->context['window'] === true && $bad->redirectedTo === '/backend/finance/journal/list');

// ── result ───────────────────────────────────────────────────────────────

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
