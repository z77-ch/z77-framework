<?php

/**
 * module-contact harness (CLI) — a contact with n typed addresses (plan §4a,
 * the P1 exit criterion) against a REAL MariaDB (ADR-039 decision 16),
 * throwaway schema per run, created by the module's own MIGRATION through
 * the `z77-db` application (never `SchemaTool`): the harness proves the
 * migration as much as the module.
 *
 * What is load-bearing here:
 *
 *   - `migrate` on an empty database creates `contact`, `address` and
 *     `contact_address` in `utf8mb4_unicode_ci` / InnoDB (decisions 17, 18),
 *     a second `migrate` is a no-op and `diff` reports NO change afterwards —
 *     the mapping and the migration agree;
 *   - the address-type seed (four rows) passes its validator, resolves by
 *     code — deactivated still resolves — and an unknown code is refused;
 *   - a contact with three typed addresses is written in ONE `save()` and
 *     read back through a fresh `UnifiedEntityManager` with every field
 *     (umlauts included); two links may share one address row, and the row
 *     goes only with its last link;
 *   - validation: required fields by kind, ISO language and country, the
 *     zip format per country; a failed save writes nothing;
 *   - the reference rule (ADR-043 decision 19): a new link needs an ACTIVE
 *     type, an existing link keeps a deactivated one, no delete of a type or
 *     a contact anywhere — service, master data, backend traits;
 *   - the list search finds by company, name (either order) and e-mail;
 *   - the list limit comes from contactConfig `contactListLimit` (default
 *     200), a project override wins, anything but a positive int throws.
 *
 * Run: php tests/module-contact.php
 * Needs what tests/persistence-doctrine.php needs (vendor/ with Doctrine, a
 * reachable MariaDB, credentials in `%USERPROFILE%\.z77\mariadb.txt` or
 * Z77_TEST_DB_*). Nothing is written into the repository; the schema
 * `z77test_<random>` and the temp installation are removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-contact: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
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
use Z77\Core\Services\ModuleManager;
use Z77\Module\Contact\Entities\Address;
use Z77\Module\Contact\Entities\AddressType;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Entities\ContactKind;
use Z77\Module\Contact\Repositories\AddressTypeRepository;
use Z77\Module\Contact\Repositories\ContactAddressRepository;
use Z77\Module\Contact\Repositories\ContactRepository;
use Z77\Module\Contact\Services\AddressTypeCodeChangedException;
use Z77\Module\Contact\Services\AddressTypeMasterData;
use Z77\Module\Contact\Services\AddressTypes;
use Z77\Module\Contact\Services\ContactService;
use Z77\Module\Contact\Services\InvalidAddressException;
use Z77\Module\Contact\Services\InvalidContactException;
use Z77\Module\Contact\Ui\AddressTypeControllerTrait;
use Z77\Module\Contact\Ui\ContactControllerTrait;
use Z77\Module\Contact\Validators\AddressTypeValidator;
use Z77\Module\Contact\Validators\AddressValidator;
use Z77\Module\Contact\Validators\ContactValidator;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

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
    fwrite(STDERR, "module-contact: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-contact-' . getmypid();
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

// The REAL package is the module's source path: its config announces the
// entities, its res/migrations holds the migration, its data/ the seed.
$package = str_replace('\\', '/', realpath(__DIR__ . '/../packages/module-contact'));
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Module\\\\Contact\\\\' => ['sourcePaths' => ['{$package}']],\n"
    . "]];");
$write('config/vendor/moduleManager.inc.php', "<?php return ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'contact', 'modules' => ['contact' => []]];");
$write('config/client/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// Seed the way the installer does: `*.default.json` → `data/…` with the marker stripped.
@mkdir($base . '/data/framework/contact', 0777, true);
copy($package . '/data/framework/contact/address_types.default.json', $base . '/data/framework/contact/address_types.json');

/**
 * The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to
 * what the drivers and the CLI read. Calling it again is the «fresh
 * request»: a new UnifiedEntityManager, a new Doctrine EntityManager, an
 * empty Identity Map — what is read afterwards comes from the database.
 */
$wireDi = static function () use ($base): UnifiedEntityManager {
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return DI::getUnifiedEntityManager();
};
$wireDi();

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
$config = DI::getModuleManager()->getModuleConfig('contact');
check('A0 the module config announces exactly the three Doctrine entities', $config?->get('doctrineEntities') === [Contact::class, Address::class, ContactAddress::class]);
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Contact\\Migrations', ($dirs['Z77\\Module\\Contact\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
check('A4 … migrated up to the module migration (the package one runs first, by timestamp)', str_contains($out, 'Z77\\Module\\Contact\\Migrations\\Version20260921153209'));
check('A5 contact, address, contact_address exist (plus number_range and the metadata table)', $tables() === ['address', 'contact', 'contact_address', 'number_range', MigrationsApplication::STORAGE_TABLE]);
$allUnicode = true;
foreach (['contact', 'address', 'contact_address'] as $table) {
    $info = $tableInfo($table);
    $allUnicode = $allUnicode && ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB';
}
check('A6 every module table is utf8mb4_unicode_ci and InnoDB although the database default is general_ci', $allUnicode);
$columns = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL', [$dbName, 'contact']);
check('A7 … and so is every string column of contact', $columns !== [] && count(array_unique($columns)) === 1 && reset($columns) === 'utf8mb4_unicode_ci');
$fks = $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY 1', [$dbName, 'contact_address']);
check('A8 contact_address has foreign keys to address and contact — and none to the file-based type', $fks === ['address', 'contact']);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Contact\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 1);
[$code, $out] = $run(['command' => 'status']);
check('A11 status lists the module namespace and two executed migrations', $code === 0 && str_contains($out, 'Z77\\Module\\Contact\\Migrations') && preg_match('/\| Executed\s+\|\s+2\s+\|/', $out) === 1);


// ── B. address types: seed, lookup, reference rule (read side) ───────────

echo "B. Address types (file-based seed)\n";
$em    = $wireDi();
$types = $em->getRepository(AddressType::class);
check('B1 convention repository resolves', $types instanceof AddressTypeRepository);
check('B2 four seed rows: main, invoice, delivery, regional — in that order', array_map(fn(AddressType $t) => $t->getCode(), $types->allInOrder()) === ['main', 'invoice', 'delivery', 'regional']);
check('B3 every seed row is active and passes its validator', array_reduce($types->allInOrder(), fn($ok, AddressType $t) => $ok && $t->isActive() && (new AddressTypeValidator($t, $types))->isValid(), true));
check('B4 labels are German with umlauts intact (UTF-8, no BOM)', $types->findByCode('invoice')?->getLabel() === 'Rechnungsadresse');
$lookup = AddressTypes::from($em);
check('B5 labelOf() by code, case- and space-insensitive', $lookup->labelOf(' Invoice ') === 'Rechnungsadresse');
check('B6 labelOf() never throws — the raw code for a missing row', $lookup->labelOf('nope') === 'nope');
check('B7 active() offers all four, all() is keyed by code', count($lookup->active()) === 4 && array_keys($lookup->all()) === ['main', 'invoice', 'delivery', 'regional']);
check('B8 there is no resolve() that throws — it arrives with the snapshot consumer (P3)', !method_exists(AddressTypes::class, 'resolve'));
$v = new AddressTypeValidator(new AddressType(['code' => 'MAIN', 'label' => 'Doppelt']), $types);
check('B9 duplicate code refused (setter lower-cases)', !$v->isValid() && $v->hasFieldError('code'));
$v = new AddressTypeValidator(new AddressType(['code' => '1st', 'label' => 'x']), $types);
check('B10 code starting with a digit refused', !$v->isValid() && $v->hasFieldError('code'));
$v = new AddressTypeValidator(new AddressType(['code' => 'ok-code', 'label' => '']), $types);
check('B11 empty label refused', !$v->isValid() && $v->hasFieldError('label'));

// ── C. a contact with n typed addresses — one save, one flush ────────────

echo "C. Contact with n typed addresses (the P1 exit criterion)\n";
$service = new ContactService($em);
$muster  = new Contact(['kind' => 'organisation', 'company' => 'Müller & Söhne AG', 'first_name' => 'Ursula', 'last_name' => 'Müller', 'language' => 'DE', 'email' => 'Info@Mueller.ch', 'phone' => '+41 44 123 45 67']);
check('C0 setters normalize language (lower) — e-mail lower-casing is the body cleaner\'s job', $muster->getLanguage() === 'de');
$main = new ContactAddress($muster, 'main', new Address(['salutation' => 'Firma', 'name' => 'Müller & Söhne AG', 'street' => 'Bahnhofstrasse', 'house_no' => '1', 'zip' => '8001', 'city' => 'Zürich', 'country' => 'ch']));
check('C1 the constructor points back to the contact but does NOT attach', $main->getContact() === $muster && $muster->getAddresses() === []);
$muster->addAddress($main);
$muster->addAddress(new ContactAddress($muster, 'invoice', new Address(['name' => 'Müller & Söhne AG', 'address_row' => 'Buchhaltung', 'street' => 'Postfach', 'zip' => '8021', 'city' => 'Zürich'])));
$muster->addAddress(new ContactAddress($muster, 'delivery', new Address(['salutation' => 'Herr', 'first_name' => 'Peter', 'name' => 'Müller', 'street' => 'Industriestrasse', 'house_no' => '12a', 'zip' => '8600', 'city' => 'Dübendorf']), 'Lager Ost'));
check('C2 three links attached; country is upper-cased at the setter', count($muster->getAddresses()) === 3 && $main->getAddress()->getCountry() === 'CH');
check('C3 nothing written before save()', $count('contact') === 0 && $count('address') === 0 && $count('contact_address') === 0);
$service->save($muster);
check('C4 one save(): contact, three addresses, three links have ids', $muster->getId() !== null && $count('contact') === 1 && $count('address') === 3 && $count('contact_address') === 3
    && array_reduce($muster->getAddresses(), fn($ok, ContactAddress $l) => $ok && $l->getId() !== null && $l->getAddress()->getId() !== null, true));
$musterId = $muster->getId();

$em2   = $wireDi();   // fresh request: new EntityManager, empty Identity Map
$repo  = $em2->getRepository(Contact::class);
$links = $em2->getRepository(ContactAddress::class);
check('C5 convention repositories resolve on the Doctrine side', $repo instanceof ContactRepository && $links instanceof ContactAddressRepository);
$read = $repo->find($musterId);
check('C6 the contact reads back with every field, umlauts intact', $read !== null && $read->getCompany() === 'Müller & Söhne AG' && $read->isOrganisation() && $read->getKind() === ContactKind::Organisation->value && $read->getLastName() === 'Müller' && $read->getPhone() === '+41 44 123 45 67' && $read->isActive());
$readLinks = $links->findByContact($read);
check('C7 three typed addresses in creation order: main, invoice, delivery', array_map(fn(ContactAddress $l) => $l->getTypeCode(), $readLinks) === ['main', 'invoice', 'delivery']);
check('C8 the link title travels («Lager Ost» on the delivery address)', $readLinks[2]->getTitle() === 'Lager Ost' && $readLinks[0]->getTitle() === '');
check('C9 address fields round-trip (addressee, row, house number, zip, city)', $readLinks[1]->getAddress()->getAddressRow() === 'Buchhaltung' && $readLinks[2]->getAddress()->getHouseNo() === '12a' && $readLinks[2]->getAddress()->getFirstName() === 'Peter' && $readLinks[0]->getAddress()->getZip() === '8001' && $readLinks[0]->getAddress()->getCity() === 'Zürich');
check('C10 the inverse side sees the same three links', count($read->getAddresses()) === 3);
check('C11 every link type resolves through AddressTypes', array_map(fn(ContactAddress $l) => AddressTypes::from($em2)->labelOf($l->getTypeCode()), $readLinks) === ['Hauptadresse', 'Rechnungsadresse', 'Lieferadresse']);
check('C12 displayName: company with the contact person in brackets', $read->displayName() === 'Müller & Söhne AG (Müller Ursula)');
check('C13 Address::oneLine()', $readLinks[0]->getAddress()->oneLine() === 'Bahnhofstrasse 1, 8001 Zürich');
$addressRef = new \ReflectionClass(Address::class);
check('C13b the addresses are fetch-joined — no lazy proxy left uninitialised (the list\'s N+1)', array_reduce($readLinks, fn($ok, ContactAddress $l) => $ok && !$addressRef->isUninitializedLazyObject($l->getAddress()), true));

echo "C. … a person\n";
$anna = new Contact(['kind' => 'person', 'first_name' => 'Anna', 'last_name' => 'Ébauche', 'language' => 'fr', 'email' => 'anna@example.ch']);
$anna->addAddress(new ContactAddress($anna, 'main', new Address(['salutation' => 'Madame', 'first_name' => 'Anna', 'name' => 'Ébauche', 'street' => 'Rue du Lac', 'house_no' => '3', 'zip' => '1003', 'city' => 'Lausanne'])));
(new ContactService($em2))->save($anna);
$em3  = $wireDi();
$repo = $em3->getRepository(Contact::class);
check('C14 a person saves with one address', $anna->getId() !== null && count($em3->getRepository(ContactAddress::class)->findByContact($repo->find($anna->getId()))) === 1);
check('C15 displayName for a person: «Last First»', $repo->find($anna->getId())->displayName() === 'Ébauche Anna');
$noName = new Contact(['kind' => 'person', 'first_name' => 'Ohne', 'language' => 'de']);
$e      = caught(fn() => (new ContactService($em3))->save($noName), InvalidContactException::class);
check('C17 a person without a last name is refused by save()', $e !== null && $e->validator->hasFieldError('last_name') && $noName->getId() === null);
check('C18 … and nothing was written', $count('contact') === 2);

echo "C. … search (Doctrine-only SQL behind the unified API)\n";
$em4 = $wireDi();
$repo = $em4->getRepository(Contact::class);
$names = fn(array $rows) => array_map(fn(Contact $c) => $c->displayName(), $rows);
check('C20 empty query: everything, sorted by the shown name (É before M under unicode_ci)', $names($repo->search('', 50)) === ['Ébauche Anna', 'Müller & Söhne AG (Müller Ursula)'] && $repo->countMatching('') === 2);
check('C21 by company fragment, case-insensitive', $names($repo->search('söhne', 50)) === ['Müller & Söhne AG (Müller Ursula)']);
check('C22 by «First Last» and by «Last First»', $names($repo->search('Anna Ébauche', 50)) === ['Ébauche Anna'] && $names($repo->search('Ébauche Anna', 50)) === ['Ébauche Anna']);
check('C23 by e-mail', $names($repo->search('anna@example', 50)) === ['Ébauche Anna']);
check('C24 no match → empty, count 0', $repo->search('nobody', 50) === [] && $repo->countMatching('nobody') === 0);
check('C26 limit is honoured, count still says how many match', count($repo->search('', 1)) === 1 && $repo->countMatching('') === 2);
$grouped = $em4->getRepository(ContactAddress::class)->findForContacts($repo->search('', 50));
check('C27 findForContacts groups the links of several contacts by contact id (link order), one query, addresses loaded', array_keys($grouped) === [$musterId, $anna->getId()] && count($grouped[$musterId]) === 3 && count($grouped[$anna->getId()]) === 1
    && array_reduce(array_merge(...array_values($grouped)), fn($ok, ContactAddress $l) => $ok && !$addressRef->isUninitializedLazyObject($l->getAddress()), true));
check('C27b findForContacts([]) is [] without a query', $em4->getRepository(ContactAddress::class)->findForContacts([]) === []);

echo "C. … LIKE wildcards and the escape character are literal (ESCAPE '!')\n";
$svc4 = new ContactService($em4);
foreach (['A_B', 'A%B', 'A!B', 'AxB', 'A\\B'] as $company) {
    $svc4->save(new Contact(['kind' => 'organisation', 'company' => $company, 'language' => 'de']));
}
$found = fn(string $q) => array_map(fn(Contact $c) => $c->getCompany(), array_filter($repo->search($q, 50), fn(Contact $c) => str_starts_with($c->getCompany(), 'A')));
check('C28 «_» matches only A_B (not AxB)', array_values($found('_')) === ['A_B']);
check('C29 «%» matches only A%B', array_values($found('%')) === ['A%B']);
check('C30 «!» (the escape character itself) matches only A!B', array_values($found('!')) === ['A!B']);
check('C31 a backslash matches only A\\B', array_values($found('\\')) === ['A\\B']);
check('C32 «A_» and «!B» narrow as literals', array_values($found('A_')) === ['A_B'] && array_values($found('!B')) === ['A!B'] && $repo->countMatching('%') === 1);
foreach ($repo->search('A', 50) as $c) {
    if (preg_match('/^A.B$/', $c->getCompany())) { $db->executeStatement('DELETE FROM contact WHERE id = ?', [$c->getId()]); }
}

// ── D. validation ────────────────────────────────────────────────────────

echo "D. Validation\n";
$em5     = $wireDi();
$service = new ContactService($em5);
$refusedContact = function (array $row, string $field) use ($service): bool {
    $e = caught(fn() => $service->save(new Contact($row)), InvalidContactException::class);
    return $e !== null && $e->validator->hasFieldError($field);
};
check('D1 organisation without company → company', $refusedContact(['kind' => 'organisation', 'last_name' => 'X', 'language' => 'de'], 'company'));
check('D2 person without last name → last_name', $refusedContact(['kind' => 'person', 'company' => 'X', 'language' => 'de'], 'last_name'));
check('D3 person with last name but no company passes the validator', (new ContactValidator(new Contact(['kind' => 'person', 'last_name' => 'X', 'language' => 'de'])))->isValid());
check('D4 organisation with company but no name passes', (new ContactValidator(new Contact(['kind' => 'organisation', 'company' => 'X', 'language' => 'de'])))->isValid());
check('D5 unknown kind → kind', $refusedContact(['kind' => 'robot', 'last_name' => 'X', 'language' => 'de'], 'kind'));
check('D6 language «deu» → language; empty → language', $refusedContact(['kind' => 'person', 'last_name' => 'X', 'language' => 'deu'], 'language') && $refusedContact(['kind' => 'person', 'last_name' => 'X'], 'language'));
check('D7 undeliverable e-mail → email; empty e-mail is fine', $refusedContact(['kind' => 'person', 'last_name' => 'X', 'language' => 'de', 'email' => 'not-an-address'], 'email') && (new ContactValidator(new Contact(['kind' => 'person', 'last_name' => 'X', 'language' => 'de', 'email' => ''])))->isValid());
check('D8 nothing was written by the refused saves', $count('contact') === 2);
check('D9 the kind set is the model\'s two', array_map(fn(ContactKind $k) => $k->value, ContactKind::cases()) === ['person', 'organisation']);

$addressOk = fn(array $row) => (new AddressValidator(new Address($row)))->isValid();
$addressField = function (array $row): array {
    $v = new AddressValidator(new Address($row));
    $v->isValid();
    return array_keys($v->getFieldErrors());
};
$full = ['name' => 'Muster', 'street' => 'Weg', 'house_no' => '1', 'zip' => '8000', 'city' => 'Zürich', 'country' => 'CH'];
check('D10 a complete CH address passes', $addressOk($full));
check('D11 missing name / street / zip / city each refused', $addressField(['zip' => '8000', 'city' => 'Zürich']) === ['name', 'street'] && $addressField(['name' => 'X', 'street' => 'Y']) === ['zip', 'city']);
check('D12 CH zip must be four digits (80001, 800, 8O00 refused)', !$addressOk(['zip' => '80001'] + $full) && !$addressOk(['zip' => '800'] + $full) && !$addressOk(['zip' => '8O00'] + $full));
check('D13 DE 80331 and GB «SW1A 1AA» accepted, DE «12» refused', $addressOk(['zip' => '80331', 'country' => 'DE'] + $full) && $addressOk(['zip' => 'SW1A 1AA', 'country' => 'GB'] + $full) && !$addressOk(['zip' => '12', 'country' => 'DE'] + $full));
check('D14 country: lower-case input is normalized, three letters refused', (new Address(['country' => 'de']))->getCountry() === 'DE' && !$addressOk(['country' => 'CHE'] + $full) && !$addressOk(['country' => ''] + $full));
check('D15 city shorter than 2 refused', !$addressOk(['city' => 'Z'] + $full));

$org = $em5->getRepository(Contact::class)->find($musterId);
$bad = new ContactAddress($org, 'delivery', new Address(['name' => 'Ohne Strasse', 'zip' => '8000', 'city' => 'Zürich']));
$e   = caught(fn() => $service->addAddress($bad), InvalidAddressException::class);
check('D16 addAddress() with an invalid address is refused with the address validator\'s field error', $e !== null && $e->addressValidator->hasFieldError('street') && !$e->linkValidator->hasErrors());
check('D17 … nothing was written, and the link was NOT attached to the managed contact', $count('address') === 4 && $count('contact_address') === 4 && count($org->getAddresses()) === 3);
$bad = new ContactAddress($org, 'delivery', new Address($full), str_repeat('x', 81));
$e   = caught(fn() => $service->addAddress($bad), InvalidAddressException::class);
check('D18 a link title over 80 characters is the link validator\'s error', $e !== null && $e->linkValidator->hasFieldError('title'));
$fresh = new Contact(['kind' => 'person', 'last_name' => 'Neu', 'language' => 'de']);
$fresh->addAddress(new ContactAddress($fresh, 'main', new Address(['name' => 'Neu'])));   // incomplete address
$e = caught(fn() => $service->save($fresh), InvalidAddressException::class);
check('D19 a new contact with an invalid attached address: refused as a whole, contact not written', $e !== null && $fresh->getId() === null && $count('contact') === 2);
$existingLink = $em5->getRepository(ContactAddress::class)->findByContact($org)[0];
check('D20 the LogicExceptions: save(existing), update(new), addAddress(existing link), saveAddress(new link)',
    throws(fn() => $service->save($org), \LogicException::class)
    && throws(fn() => $service->update(new Contact(), []), \LogicException::class)
    && throws(fn() => $service->addAddress($existingLink), \LogicException::class)
    && throws(fn() => $service->saveAddress(new ContactAddress($org, 'main', new Address($full)), [], []), \LogicException::class));

echo "D. … validate BEFORE mutating a managed entity (ADR-039 decision 9 — the reviewer's probes)\n";
$em5b = $wireDi();
$svc  = new ContactService($em5b);
$org  = $em5b->getRepository(Contact::class)->find($musterId);
$e    = caught(fn() => $svc->update($org, ['company' => '']), InvalidContactException::class);
check('D21 update() with an empty company on an organisation is refused; the managed entity is untouched', $e !== null && $e->validator->hasFieldError('company') && $org->getCompany() === 'Müller & Söhne AG');
check('D22 … the exception carries the DRAFT with the submitted value, same id, own empty collection', $e->draft !== $org && $e->draft->getCompany() === '' && $e->draft->getId() === $musterId && $e->draft->getAddresses() === []);
$other = $em5b->getRepository(Contact::class)->find($anna->getId());
$svc->update($other, ['phone' => '+41 21 000 00 00']);   // an unrelated, valid write in the SAME EntityManager
check('D23 a later flush of something else writes nothing of the refused change (probe P2)', $db->fetchOne('SELECT company FROM contact WHERE id = ?', [$musterId]) === 'Müller & Söhne AG' && $db->fetchOne('SELECT phone FROM contact WHERE id = ?', [$anna->getId()]) === '+41 21 000 00 00');
$badLink = new ContactAddress($org, 'main', new Address(['name' => '', 'street' => '', 'zip' => 'x', 'city' => '']));
caught(fn() => $svc->addAddress($badLink), InvalidAddressException::class);
$svc->update($other, ['phone' => '+41 21 000 00 01']);
check('D24 a refused addAddress() leaves no link and no address behind after the next flush (probe P1)', $count('contact_address') === 4 && $count('address') === 4 && count($org->getAddresses()) === 3);
$link = $em5b->getRepository(ContactAddress::class)->findByContact($org)[0];
$e    = caught(fn() => $svc->saveAddress($link, ['type_code' => 'main', 'title' => 'Sitz'], ['zip' => '99', 'street' => 'Neue Strasse']), InvalidAddressException::class);
check('D25 saveAddress() with a bad zip is refused; link and address are untouched, the draft carries the input', $e !== null && $e->addressValidator->hasFieldError('zip') && $link->getTitle() === '' && $link->getAddress()->getStreet() === 'Bahnhofstrasse' && $link->getAddress()->getZip() === '8001'
    && $e->link !== $link && $e->link->getTitle() === 'Sitz' && $e->link->getAddress()->getZip() === '99' && $e->link->getAddress()->getStreet() === 'Neue Strasse');
$svc->update($other, ['phone' => '+41 21 000 00 02']);
check('D26 … and the next flush writes none of it (probe P3)', $db->fetchOne('SELECT street FROM address WHERE id = ?', [$link->getAddress()->getId()]) === 'Bahnhofstrasse' && $db->fetchOne('SELECT title FROM contact_address WHERE id = ?', [$link->getId()]) === '');
$svc->saveAddress($link, ['type_code' => 'main', 'title' => 'Sitz'], ['street' => 'Bahnhofstrasse', 'house_no' => '2']);
check('D27 a valid saveAddress() applies link and address values and flushes', $db->fetchOne('SELECT house_no FROM address WHERE id = ?', [$link->getAddress()->getId()]) === '2' && $db->fetchOne('SELECT title FROM contact_address WHERE id = ?', [$link->getId()]) === 'Sitz');
$svc->saveAddress($link, ['type_code' => 'main', 'title' => ''], ['house_no' => '1']);
$e = caught(fn() => $svc->update($org, ['kind' => 'person', 'last_name' => '']), InvalidContactException::class);
check('D28 a refused kind switch leaves the managed entity an organisation', $e !== null && $org->isOrganisation() && $db->fetchOne('SELECT kind FROM contact WHERE id = ?', [$musterId]) === 'organisation');

echo "D. … the unique constraint under a race becomes the validator\'s field error (no 500)\n";
$em5c = $wireDi();
$svc  = new ContactService($em5c);
$org  = $em5c->getRepository(Contact::class)->find($musterId);
$mainAddress = $em5c->getRepository(ContactAddress::class)->findByContact($org)[0]->getAddress();
$twice = new ContactAddress($org, 'main', $mainAddress);   // the same address row under the same type — only the index sees it
$e = caught(fn() => $svc->addAddress($twice), InvalidAddressException::class);
check('D29 linking one address twice under one type: the unique index is reported as a type_code field error', $e !== null && $e->linkValidator->hasFieldError('type_code') && str_contains($e->linkValidator->getFieldError('type_code'), 'bereits'));
check('D30 … the row count is unchanged and the EntityManager was replaced (DOCTRINE-TX-004) — a fresh read works', $count('contact_address') === 4 && $em5c->getRepository(Contact::class)->find($musterId)->getCompany() === 'Müller & Söhne AG');

// ── E. the reference rule: type by code, active for new, deactivate never delete ─

echo "E. Reference rule (ADR-043 decision 19)\n";
$em6     = $wireDi();
$service = new ContactService($em6);
$org     = $em6->getRepository(Contact::class)->find($musterId);
$unknown = new ContactAddress($org, 'warehouse', new Address($full));
$e       = caught(fn() => $service->addAddress($unknown), InvalidAddressException::class);
check('E1 an unknown type code is refused on the link (type_code)', $e !== null && $e->linkValidator->hasFieldError('type_code') && str_contains($e->linkValidator->getFieldError('type_code'), 'warehouse'));
$regional = new ContactAddress($org, 'Regional', new Address(['name' => 'Müller Bern', 'street' => 'Marktgasse', 'house_no' => '5', 'zip' => '3011', 'city' => 'Bern']), 'Filiale Bern');
$service->addAddress($regional);
check('E2 a link with an active type is added (code normalized to lower-case) and attached', $regional->getId() !== null && $regional->getTypeCode() === 'regional' && $count('contact_address') === 5 && in_array($regional, $org->getAddresses(), true));

$master = new AddressTypeMasterData($em6);
$types  = $em6->getRepository(AddressType::class);
$master->setActive($types->findByCode('regional'), false);
$em7    = $wireDi();
check('E3 deactivation is persisted', !$em7->getRepository(AddressType::class)->findByCode('regional')->isActive() && count(AddressTypes::from($em7)->active()) === 3);
check('E4 a deactivated type still resolves for the existing link', AddressTypes::from($em7)->labelOf('regional') === 'Regionaladresse');
$service7 = new ContactService($em7);
$org7     = $em7->getRepository(Contact::class)->find($musterId);
$again    = new ContactAddress($org7, 'regional', new Address($full));
$e        = caught(fn() => $service7->addAddress($again), InvalidAddressException::class);
check('E5 a NEW link with the deactivated type is refused', $e !== null && $e->linkValidator->hasFieldError('type_code') && str_contains($e->linkValidator->getFieldError('type_code'), 'inaktiv'));
$existing = null;
foreach ($em7->getRepository(ContactAddress::class)->findByContact($org7) as $l) {
    if ($l->getTypeCode() === 'regional') { $existing = $l; }
}
$service7->saveAddress($existing, ['type_code' => 'regional', 'title' => 'Filiale Bern (Bundesplatz)'], ['house_no' => '7']);
$em8 = $wireDi();
$reread = $em8->getRepository(ContactAddress::class)->find($existing->getId());
check('E6 the EXISTING link KEEPS its deactivated type and stays editable (link and address in one flush)', $reread->getTitle() === 'Filiale Bern (Bundesplatz)' && $reread->getAddress()->getHouseNo() === '7' && $reread->getTypeCode() === 'regional');
$delivery = null;
foreach ($em8->getRepository(ContactAddress::class)->findByContact($em8->getRepository(Contact::class)->find($musterId)) as $l) {
    if ($l->getTypeCode() === 'delivery') { $delivery = $l; }
}
$e = caught(fn() => (new ContactService($em8))->saveAddress($delivery, ['type_code' => 'regional', 'title' => ''], []), InvalidAddressException::class);
check('E6b SWITCHING an existing link TO the deactivated type is refused (owner 2026-09-21), the link untouched', $e !== null && $e->linkValidator->hasFieldError('type_code') && $delivery->getTypeCode() === 'delivery');
(new ContactService($em8))->saveAddress($delivery, ['type_code' => 'invoice', 'title' => 'Lager Ost'], []);
check('E6c switching to an ACTIVE type is fine', $db->fetchOne('SELECT type_code FROM contact_address WHERE id = ?', [$delivery->getId()]) === 'invoice');
(new ContactService($em8))->saveAddress($delivery, ['type_code' => 'delivery', 'title' => 'Lager Ost'], []);
$master8 = new AddressTypeMasterData($em8);
$types8  = $em8->getRepository(AddressType::class);
$t = $types8->findByCode('regional');
$t->setCode('region');
check('E7 renaming an existing type code is refused', throws(fn() => $master8->save($t), AddressTypeCodeChangedException::class) && $wireDi()->getRepository(AddressType::class)->findByCode('regional') !== null);
$t = $types8->findByCode('regional');
$t->setLabel('Regionaladresse (Filiale)');
$master8->save($t);
check('E8 changing the label saves', $wireDi()->getRepository(AddressType::class)->findByCode('regional')?->getLabel() === 'Regionaladresse (Filiale)');
$new = new AddressType(['code' => 'billing-copy', 'label' => 'Rechnungskopie']);
$master8->save($new);
check('E9 a new type saves and gets an id', $new->getId() !== null && $wireDi()->getRepository(AddressType::class)->findByCode('billing-copy') !== null);
check('E10 the write side has no delete for a type', !method_exists(AddressTypeMasterData::class, 'remove') && !method_exists(AddressTypeMasterData::class, 'delete') && !method_exists(AddressTypeMasterData::class, 'removeType'));
check('E11 the write side has no delete for a contact', !method_exists(ContactService::class, 'remove') && !method_exists(ContactService::class, 'delete') && !method_exists(ContactService::class, 'removeContact'));
$methodsOf = fn(string $trait) => array_map(fn(\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass($trait))->getMethods());
$contactActions = $methodsOf(ContactControllerTrait::class);
check('E12 the contact trait exposes no delete of the contact — only the address removal and the active switch', !in_array('removeAction', $contactActions, true) && !in_array('deleteAction', $contactActions, true) && !in_array('confirmDeleteAction', $contactActions, true)
    && in_array('removeAddressAction', $contactActions, true) && in_array('toggleActiveAction', $contactActions, true));
$typeActions = $methodsOf(AddressTypeControllerTrait::class);
check('E13 the address-type trait exposes no delete at all', array_filter($typeActions, fn($m) => stripos($m, 'remove') !== false || stripos($m, 'delete') !== false) === [] && in_array('toggleActiveAction', $typeActions, true));
$traitSource = file_get_contents(__DIR__ . '/../packages/module-contact/src/Ui/ContactControllerTrait.php');
check('E13b the trait never maps a body onto a managed entity (source guard: mapFromArray only on the NEW `$contact` / `$address` objects of add / add-address)', preg_match_all('/->mapFromArray\(/', $traitSource) === 3 && !str_contains($traitSource, '$link->mapFromArray') && !str_contains($traitSource, 'getAddress()->mapFromArray') && !str_contains($traitSource, '$shown->mapFromArray'));
$em9    = $wireDi();
$links9 = $em9->getRepository(ContactAddress::class);
$org9   = $em9->getRepository(Contact::class)->find($musterId);
$shared = $links9->findByContact($org9)[0]->getAddress();   // the main address row
$copy   = new ContactAddress($org9, 'billing-copy', $shared, 'gleiche Adresse');
(new ContactService($em9))->addAddress($copy);
check('E14 two links may share one address row (invoice copy = main address)', $copy->getId() !== null && $count('contact_address') === 6 && $count('address') === 5 && $links9->countByAddress($shared) === 2);
check('E15 countByTypeCode (what the type list shows)', $links9->countByTypeCode('main') === 2 && $links9->countByTypeCode('billing-copy') === 1 && $links9->countByTypeCode('nope') === 0);
(new ContactService($em9))->removeAddress($copy);
check('E16 removing one of the two links keeps the shared address row', $count('contact_address') === 5 && $count('address') === 5 && $links9->countByAddress($shared) === 1);
$em10    = $wireDi();
$links10 = $em10->getRepository(ContactAddress::class);
$org10   = $em10->getRepository(Contact::class)->find($musterId);
$lastRegional = null;
foreach ($links10->findByContact($org10) as $l) {
    if ($l->getTypeCode() === 'regional') { $lastRegional = $l; }
}
(new ContactService($em10))->removeAddress($lastRegional);
check('E17 removing the LAST link of an address removes the row too, the contact stays', $count('contact_address') === 4 && $count('address') === 4 && $count('contact') === 2);
check('E18 … and the contact\'s collection no longer holds it', count($wireDi()->getRepository(Contact::class)->find($musterId)->getAddresses()) === 3);

// ── F. deactivate a contact ──────────────────────────────────────────────

echo "F. Deactivate, never delete\n";
$em11 = $wireDi();
(new ContactService($em11))->setActive($em11->getRepository(Contact::class)->find($musterId), false);
$read = $wireDi()->getRepository(Contact::class)->find($musterId);
check('F1 deactivation is persisted, the addresses stay', !$read->isActive() && count($read->getAddresses()) === 3);
check('F2 the row is still found by search (history)', count($wireDi()->getRepository(Contact::class)->search('Müller', 10)) === 1);
$em12 = $wireDi();
$c    = $em12->getRepository(Contact::class)->find($musterId);
(new ContactService($em12))->update($c, ['email' => 'kontakt@mueller.ch']);
check('F3 editing an existing contact re-validates and saves without touching its links', $wireDi()->getRepository(Contact::class)->find($musterId)->getEmail() === 'kontakt@mueller.ch' && $count('contact_address') === 4);
check('F4 Contact::addAddress() refuses a link built for another contact', throws(function () use ($em12, $musterId, $anna) {
    $link = new ContactAddress($em12->getRepository(Contact::class)->find($musterId), 'main', new Address());
    $em12->getRepository(Contact::class)->find($anna->getId())->addAddress($link);
}, \LogicException::class));

// ── G. the list limit comes from the module config (owner, 2026-09-21) ───

echo "G. List limit (contactConfig contactListLimit)\n";
$wireDi();
check('G1 the package config carries contactListLimit = 200 (the constant)', DI::getModuleManager()->getModuleConfig('contact')?->get('contactListLimit') === 200 && ContactService::DEFAULT_LIST_LIMIT === 200);
check('G2 listLimit() reads the package default', ContactService::listLimit() === 200);
check('G3 the trait has no hard-coded limit left and asks listLimit()', !str_contains($traitSource, 'CONTACT_LIST_LIMIT') && str_contains($traitSource, 'ContactService::listLimit()'));
/** DI with a ModuleManager whose contact config is exactly $config (the project's override copy). */
$withContactConfig = static function (array $config): void {
    $mm = new class extends ModuleManager {
        public array $fake = [];
        public function __construct() {}
        public function getModuleConfig(string $moduleKey): ?\Z77\Core\Config\Config
        {
            return $moduleKey === 'contact' ? new \Z77\Core\Config\Config($this->fake) : null;
        }
    };
    $mm->fake = $config;
    DI::getInstance(true)->set('ModuleManager', $mm, true);
};
$withContactConfig(['contactListLimit' => 25]);
check('G4 a project override sets its own limit', ContactService::listLimit() === 25);
$withContactConfig([]);
check('G5 an override copy without the key falls back to the default', ContactService::listLimit() === 200);
foreach (['G6 0' => 0, 'G7 negative' => -5, 'G8 numeric string' => '50', 'G9 float' => 50.0, 'G10 null' => null] as $label => $bad) {
    $withContactConfig(['contactListLimit' => $bad]);
    $e = caught(fn() => ContactService::listLimit(), \UnexpectedValueException::class);
    check("{$label} is refused loudly (UnexpectedValueException naming the key)", $e !== null && str_contains($e->getMessage(), 'contactListLimit'));
}
$em13 = $wireDi();
check('G11 the repository honours the limit it is given', count($em13->getRepository(Contact::class)->search('', 1)) === 1 && $em13->getRepository(Contact::class)->countMatching('') === $count('contact'));

echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
exit($fail === 0 ? 0 : 1);
