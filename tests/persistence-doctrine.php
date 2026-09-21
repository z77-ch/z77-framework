<?php

/**
 * Doctrine driver harness (CLI) — z77/persistence-doctrine part 1 against a
 * REAL MariaDB (ADR-039 decision 16), throwaway schema per run.
 *
 * What is load-bearing here:
 *
 *   - LAZY: a request that touches only file entities never loads the Doctrine
 *     bootstrap, let alone Doctrine's EntityManager (ADR-001, decision 3);
 *   - ONE API: `UnifiedEntityManager::getRepository()` hands out the entity's
 *     convention repository (`…\Repositories\XRepository extends
 *     DoctrineRepository`) or the generic one, exactly like the File driver;
 *   - the driver differences of decision 9 are what the ADR says they are:
 *     `remove()` deletes at the next `flush()` (File: at once), the Identity
 *     Map returns the same object, `reorder()` is refused;
 *   - `Money` ↔ DECIMAL(15,2) travels as a decimal STRING — negatives, the
 *     largest value the column holds, umlauts next to it — and a float on
 *     either side is refused (ADR-042 decision 3);
 *   - utf8mb4 / utf8mb4_unicode_ci on the connection AND on every table the
 *     schema tooling creates, even when the database default says otherwise
 *     (decision 18) — the throwaway database is created with general_ci on
 *     purpose;
 *   - a driver named in the map but not installed fails with a message that
 *     names the missing package (decision 2); `doctrineEntities` naming a
 *     non-existent class fails loudly (decision 5);
 *   - File and Doctrine entities mix in one UnifiedEntityManager and one
 *     `flush()`.
 *
 * Run: php tests/persistence-doctrine.php
 * Needs: `composer install` in the monorepo root (vendor/ with Doctrine) and a
 * reachable MariaDB. Credentials come from `%USERPROFILE%\.z77\mariadb.txt`
 * (line `z77test=<password>`, maintainer runbook) or from the environment:
 * Z77_TEST_DB_HOST / Z77_TEST_DB_USER / Z77_TEST_DB_PASSWORD. Nothing is ever
 * written into the repository. The schema `z77test_<random>` is dropped at the
 * end, also on failure.
 */

namespace {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "persistence-doctrine: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
        exit(2);
    }
    require $autoload;
}

// ── fixtures: two Doctrine entities, one with a convention repository, a File
// entity for the mixed case, and an entity of a driver that is not installed ──

namespace Z77Test\Doctrine\Entities {

    use Doctrine\ORM\Mapping as ORM;
    use Z77\Persistence\Doctrine\Type\MoneyType;
    use Z77\Shared\Attributes\Entity;
    use Z77\Shared\Money\Money;
    use Z77\Shared\Traits\ArrayMappable;

    #[Entity('doctrine')]
    #[ORM\Entity, ORM\Table(name: 'probe_posting')]
    class Posting
    {
        #[ORM\Id, ORM\Column, ORM\GeneratedValue]
        private ?int $id = null;

        #[ORM\Column(length: 40)]
        private string $text;

        #[ORM\Column(type: MoneyType::NAME)]
        private Money $amount;

        public function __construct(string $text, Money $amount)
        {
            $this->text   = $text;
            $this->amount = $amount;
        }

        public function getId(): ?int      { return $this->id; }
        public function getText(): string  { return $this->text; }
        public function getAmount(): Money { return $this->amount; }
    }

    #[Entity('doctrine')]
    #[ORM\Entity, ORM\Table(name: 'probe_note')]
    class Note
    {
        #[ORM\Id, ORM\Column, ORM\GeneratedValue]
        private ?int $id = null;

        #[ORM\Column(length: 40)]
        private string $label;

        public function __construct(string $label)
        {
            $this->label = $label;
        }

        public function getId(): ?int     { return $this->id; }
        public function getLabel(): string { return $this->label; }
    }

    /** Mapped for Doctrine but NOT announced under `doctrineEntities`. */
    #[Entity('doctrine')]
    #[ORM\Entity, ORM\Table(name: 'probe_orphan')]
    class Orphan
    {
        #[ORM\Id, ORM\Column, ORM\GeneratedValue]
        private ?int $id = null;
    }

    #[Entity('file', 'probe/markers.json')]
    class Marker
    {
        use ArrayMappable;

        private ?int $id = null;
        private string $label = '';

        public function __construct(string $label = '')
        {
            $this->label = $label;
        }

        public function getId(): ?int             { return $this->id; }
        public function getLabel(): string        { return $this->label; }
        public function setLabel(string $l): void { $this->label = $l; }
    }

    #[Entity('ghost')]
    class Ghost
    {
    }
}

namespace Z77Test\Doctrine\Repositories {

    use Z77\Persistence\Doctrine\Repository\DoctrineRepository;
    use Z77\Shared\Money\Money;

    /** Convention repository with a report method on the driver's connection (ADR-039 decision 8). */
    class PostingRepository extends DoctrineRepository
    {
        public function total(): Money
        {
            $sum = $this->connection()->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM probe_posting');

            return Money::fromDecimal((string) $sum, 'CHF');
        }

        /** @return array{0: string, 1: string} character_set_connection, collation_connection */
        public function connectionCharset(): array
        {
            $row = $this->connection()->fetchNumeric('SELECT @@character_set_connection, @@collation_connection');

            return [(string) $row[0], (string) $row[1]];
        }
    }
}

namespace {

    use Doctrine\DBAL\Connection;
    use Doctrine\DBAL\DriverManager;
    use Doctrine\DBAL\Types\ConversionException;
    use Doctrine\DBAL\Types\Type;
    use Doctrine\ORM\Tools\SchemaTool;
    use Z77\Core\Config\Config;
    use Z77\Core\DI;
    use Z77\Core\Libraries\CacheManager;
    use Z77\Core\Libraries\ConfigManager;
    use Z77\Core\Libraries\FileFinder;
    use Z77\Core\Services\ModuleManager;
    use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
    use Z77\Persistence\Doctrine\EntityManagerFactory;
    use Z77\Persistence\Doctrine\Repository\DoctrineRepository;
    use Z77\Persistence\Doctrine\Type\MoneyType;
    use Z77\Persistence\Resolver\DataSourceResolver;
    use Z77\Persistence\Resolver\UnifiedEntityManager;
    use Z77\Shared\Money\Money;
    use Z77Test\Doctrine\Entities\Ghost;
    use Z77Test\Doctrine\Entities\Marker;
    use Z77Test\Doctrine\Entities\Note;
    use Z77Test\Doctrine\Entities\Orphan;
    use Z77Test\Doctrine\Entities\Posting;
    use Z77\Shared\Backup\BackupService;
    use Z77\Shared\Backup\BackupType;
    use Z77Test\Doctrine\Repositories\PostingRepository;

    $pass = 0;
    $fail = 0;

    function check(string $label, bool $ok): void
    {
        global $pass, $fail;
        if ($ok) { $pass++; echo "  ok   {$label}\n"; }
        else     { $fail++; echo "  FAIL {$label}\n"; }
    }

    /** Runs $fn and returns the exception's message when it throws $class, null otherwise. */
    function thrown(callable $fn, string $class): ?string
    {
        try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e->getMessage() : null; }
        return null;
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
        fwrite(STDERR, "persistence-doctrine: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
        exit(2);
    }

    // ── throwaway schema, deliberately NOT in our collation ──────────────────

    $dbName = 'z77test_' . bin2hex(random_bytes(4));
    $admin  = DriverManager::getConnection([
        'driver'   => 'pdo_mysql',
        'host'     => $credentials['host'],
        'user'     => $credentials['user'],
        'password' => $credentials['password'],
    ]);
    $admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    // ── throwaway installation: config split + data dir, like a real project ─

    $base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-doctrine-' . getmypid();
    define('ABS_BASE_PATH', $base);
    define('DEBUG', false);

    $write = function (string $rel, string $php) use ($base): void {
        $path = $base . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $php);
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

    $writeDatabaseConfig = function (string $name) use ($write, $credentials): void {
        $write('config/client/database.inc.php', '<?php return ' . var_export([
            'host'     => $credentials['host'],
            'port'     => null,
            'name'     => $name,
            'user'     => $credentials['user'],
            'password' => $credentials['password'],
        ], true) . ';');
    };
    $write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'tpl'], 'namespaces' => []];");
    $write('config/client/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
    $writeDatabaseConfig($dbName);

    /** ModuleManager with injected module configs, no ConfigManager boot (as in tests/api-stateless-guard.php). */
    $moduleManagerWith = function (array $configsByModule): ModuleManager {
        $mm = new class extends ModuleManager {
            public array $fakeConfigs = [];
            public function __construct() {}
            public function getModuleKeys(): array { return array_keys($this->fakeConfigs); }
            /** No source paths registered for it: no extension files (those are tests/entity-announcement.php). */
            public function getNamespacePrefix(string $moduleKey): string { return 'Z77Test\\Unregistered\\'; }
            public function getModuleConfig(string $moduleKey): ?Config
            {
                return isset($this->fakeConfigs[$moduleKey]) ? new Config($this->fakeConfigs[$moduleKey]) : null;
            }
        };
        $mm->fakeConfigs = $configsByModule;
        return $mm;
    };

    /**
     * The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to
     * what the driver reads — including the release-local cache directory
     * (ADR-035): DEBUG is false here, so the driver compiles its metadata to
     * `var/cache/doctrine/` exactly as in production (ADR-039 decision 11).
     */
    $wireDi = function () use ($moduleManagerWith, $base): UnifiedEntityManager {
        DI::getInstance(true)
            ->set('CacheManager', CacheManager::class, true)
            ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
            ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
            ->set('ModuleManager', fn() => $moduleManagerWith([
                'probe' => ['doctrineEntities' => [Posting::class, Note::class]],
            ]), true)
            ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
            ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
        ;
        DI::getCacheManager()->setCacheDir($base . '/var/cache');
        return DI::getUnifiedEntityManager();
    };
    $uem = $wireDi();

    // ── A. lazy: file-only use boots nothing of Doctrine ─────────────────────

    echo "A. Lazy boot\n";
    $markers = $uem->getRepository(Marker::class);
    $marker  = new Marker('first');
    $uem->persist($marker);
    $uem->flush();
    check('A1 file entity written', $marker->getId() === 1 && $markers->find(1)?->getLabel() === 'first');
    check('A2 file-only use never loads the Doctrine bootstrap', !class_exists(DoctrineBootstrap::class, false));
    check('A3 … nor Doctrine\'s EntityManager', !class_exists(\Doctrine\ORM\EntityManager::class, false));

    // ── B. schema for the probe entities (test-only; production = migrations) ─

    $connection = [
        'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
        'user' => $credentials['user'], 'password' => $credentials['password'],
    ];
    $schemaEm = EntityManagerFactory::create($connection, [Posting::class, Note::class], 'CHF');
    (new SchemaTool($schemaEm))->createSchema($schemaEm->getMetadataFactory()->getAllMetadata());
    $db = $schemaEm->getConnection();   // a SECOND connection: what it sees is committed

    // ── C. one API: repositories through UnifiedEntityManager ────────────────

    echo "C. Repositories\n";
    $postings = $uem->getRepository(Posting::class);
    check('C1 convention repository for Posting', $postings instanceof PostingRepository);
    check('C2 … extends DoctrineRepository', $postings instanceof DoctrineRepository);
    check('C3 generic DoctrineRepository for Note', get_class($uem->getRepository(Note::class)) === DoctrineRepository::class);
    check('C4 repository cached per class', $uem->getRepository(Posting::class) === $postings);
    check('C5 the Doctrine bootstrap is loaded now, by the first Doctrine entity', class_exists(DoctrineBootstrap::class, false));

    // ── D. persist / flush / find, criteria ──────────────────────────────────

    echo "D. Round trip\n";
    $rent = new Posting('Miete', chf('-1250.00'));
    $uem->persist($rent);
    $uem->flush();
    check('D1 id assigned on flush', is_int($rent->getId()));
    check('D2 find returns the SAME object (Identity Map, ARCH-A002)', $postings->find($rent->getId()) === $rent);

    $fee = new Posting('Gebühr', chf('0.05'));
    $uem->persist($fee);
    $uem->flush();
    check('D3 findAll', count($postings->findAll()) === 2);
    check('D4 findBy text', ($postings->findBy(['text' => 'Miete'])[0] ?? null) === $rent);
    check('D5 findOneBy with a Money criteria (through the type)', $postings->findOneBy(['amount' => chf('0.05')]) === $fee);
    check('D6 umlaut round trip through utf8mb4', $postings->findOneBy(['text' => 'Gebühr']) === $fee);
    check('D7 miss is null', $postings->findOneBy(['text' => 'nope']) === null && $postings->find(99999) === null);

    // ── E. Money: DECIMAL(15,2) as a string, never through float ─────────────

    echo "E. Money type\n";
    $max = new Posting('max', chf('9999999999999.99'));
    $min = new Posting('min', chf('-9999999999999.99'));
    $nil = new Posting('nil', chf('0.00'));
    $neg = new Posting('neg', chf('-0.01'));
    foreach ([$max, $min, $nil, $neg] as $p) { $uem->persist($p); }
    $uem->flush();

    $schemaEm->clear();   // force real hydration on the second EntityManager
    foreach (['max' => '9999999999999.99', 'min' => '-9999999999999.99', 'nil' => '0.00', 'neg' => '-0.01', 'Miete' => '-1250.00'] as $text => $decimal) {
        $read = $schemaEm->getRepository(Posting::class)->findOneBy(['text' => $text]);
        check("E1 {$text} hydrates to {$decimal}", $read !== null && $read->getAmount() instanceof Money && $read->getAmount()->toDecimal() === $decimal);
    }
    $raw = $db->fetchOne('SELECT amount FROM probe_posting WHERE text = ?', ['min']);
    check('E2 the driver returns the DECIMAL as a string', is_string($raw) && $raw === '-9999999999999.99');
    $col = $db->fetchAssociative(
        'SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$dbName, 'probe_posting', 'amount']
    );
    check('E3 column is DECIMAL(15,2)', $col['DATA_TYPE'] === 'decimal' && (int) $col['NUMERIC_PRECISION'] === 15 && (int) $col['NUMERIC_SCALE'] === 2);
    check('E4 SUM() report on the driver\'s own connection is exact', $postings->total()->equals(chf('-1250.00')->add(chf('0.05'))->add(chf('-0.01'))));

    $type     = Type::getType(MoneyType::NAME);
    $platform = $db->getDatabasePlatform();
    check('E5 type: Money → decimal string', $type->convertToDatabaseValue(chf('-0.05'), $platform) === '-0.05');
    check('E6 type: decimal string → Money', $type->convertToPHPValue('12.50', $platform)->minor === 1250);
    check('E7 type: null both ways', $type->convertToPHPValue(null, $platform) === null && $type->convertToDatabaseValue(null, $platform) === null);
    check('E8 type: a float PHP value is refused', thrown(fn() => $type->convertToDatabaseValue(12.5, $platform), ConversionException::class) !== null);
    check('E9 type: a float from the database is refused', thrown(fn() => $type->convertToPHPValue(12.5, $platform), ConversionException::class) !== null);
    check('E10 type: an int is refused (no currency, no scale)', thrown(fn() => $type->convertToDatabaseValue(1250, $platform), ConversionException::class) !== null);
    $msg = thrown(fn() => $type->convertToDatabaseValue(Money::fromDecimal('100.00', 'EUR'), $platform), ConversionException::class);
    check('E11 type: a foreign currency is refused — it would come back as CHF', $msg !== null && str_contains($msg, 'EUR') && str_contains($msg, 'CHF'));
    // On its own EntityManager: a failed flush CLOSES Doctrine's EM (replacing
    // it after a rollback is ADR-039 decision 10, part 2), and the shared one
    // is still needed below.
    $eurEm = EntityManagerFactory::create($connection, [Posting::class, Note::class], 'CHF');
    $eurEm->persist(new Posting('eur', Money::fromDecimal('1.00', 'EUR')));
    check('E12 … also on flush', thrown(fn() => $eurEm->flush(), ConversionException::class) !== null);
    $eurEm->getConnection()->close();
    check('E12b … and nothing was written', (int) $db->fetchOne("SELECT COUNT(*) FROM probe_posting WHERE text = 'eur'") === 0);
    check('E13 type: beyond DECIMAL(15,2) refused before SQL (+)', thrown(fn() => $type->convertToDatabaseValue(Money::of(1_000_000_000_000_000, 'CHF'), $platform), ConversionException::class) !== null);
    check('E14 … and (−)', thrown(fn() => $type->convertToDatabaseValue(Money::of(-1_000_000_000_000_000, 'CHF'), $platform), ConversionException::class) !== null);
    check('E15 … while the column maximum itself passes', $type->convertToDatabaseValue(Money::of(999_999_999_999_999, 'CHF'), $platform) === '9999999999999.99');
    $sqlMode = (string) $db->fetchOne('SELECT @@sql_mode');
    check('I0 strict sql_mode pinned on the connection', str_contains($sqlMode, 'STRICT_TRANS_TABLES'));
    check('I0b … so an out-of-range DECIMAL is an SQL error, not a clamp',
        thrown(fn() => $db->executeStatement("INSERT INTO probe_posting (text, amount) VALUES ('raw', 10000000000000.00)"), \Doctrine\DBAL\Exception::class) !== null
        && (int) $db->fetchOne("SELECT COUNT(*) FROM probe_posting WHERE text = 'raw'") === 0);

    // ── F. remove() timing: Doctrine at flush, File at once ──────────────────

    echo "F. remove() timing\n";
    $count = fn() => (int) $db->fetchOne('SELECT COUNT(*) FROM probe_posting');
    $before = $count();
    $uem->remove($fee);
    check('F1 Doctrine: the row is still there after remove()', $count() === $before);
    $uem->flush();
    check('F2 … and gone after flush()', $count() === $before - 1);

    $uem->remove($marker);
    check('F3 File: gone at once, no flush', $markers->find(1) === null);

    // ── G. reorder() is File-only ────────────────────────────────────────────

    echo "G. reorder()\n";
    $msg = thrown(fn() => $uem->reorder([$rent]), \LogicException::class);
    check('G1 refused with a LogicException', $msg !== null);
    check('G2 … that says why', str_contains((string) $msg, 'File-only'));

    // ── H. mixed File + Doctrine in one manager, one flush ───────────────────

    echo "H. Mixed drivers\n";
    $note   = new Note('mixed');
    $marker = new Marker('mixed');
    $uem->persist($note);
    $uem->persist($marker);
    $uem->flush();
    check('H1 one flush writes both drivers', $note->getId() !== null && $marker->getId() !== null);
    check('H2 file row readable', $markers->findOneBy(['label' => 'mixed'])?->getId() === $marker->getId());
    check('H3 db row readable through the second connection', (int) $db->fetchOne('SELECT COUNT(*) FROM probe_note WHERE label = ?', ['mixed']) === 1);

    // ── I. charset and collation: the driver's, not the server's ─────────────

    echo "I. Charset / collation\n";
    [$charset, $collation] = $postings->connectionCharset();
    check('I1 connection charset utf8mb4', $charset === 'utf8mb4');
    check('I2 connection collation utf8mb4_unicode_ci', $collation === 'utf8mb4_unicode_ci');
    $dbDefault = $db->fetchOne('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$dbName]);
    check('I3 the database default is general_ci — so what follows is the driver\'s doing', $dbDefault === 'utf8mb4_general_ci');
    $tableColl = $db->fetchOne('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, 'probe_posting']);
    check('I4 table collation utf8mb4_unicode_ci', $tableColl === 'utf8mb4_unicode_ci');
    $colColl = $db->fetchOne('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$dbName, 'probe_posting', 'text']);
    check('I5 string column collation utf8mb4_unicode_ci', $colColl === 'utf8mb4_unicode_ci');

    // ── J. a driver named in the map but not installed ───────────────────────

    echo "J. Missing driver package\n";
    $ghost = new UnifiedEntityManager(new DataSourceResolver(['ghost' => 'Ghost']));
    $msg   = thrown(fn() => $ghost->getRepository(Ghost::class), \RuntimeException::class);
    check('J1 fails at the first entity of that driver', $msg !== null);
    check('J2 … naming the bootstrap class it looked for', str_contains((string) $msg, 'Z77\\Persistence\\Ghost\\Bootstrap'));
    check('J3 … and the package to require', str_contains((string) $msg, 'z77/persistence-doctrine'));

    // ── K. doctrineEntities collected like importEntities (extension files: tests/entity-announcement.php) ─

    echo "K. doctrineEntities\n";
    $mm = $moduleManagerWith([
        'a' => ['doctrineEntities' => [Posting::class, Note::class], 'importEntities' => [Marker::class]],
        'b' => ['doctrineEntities' => [Note::class]],
        'c' => [],
    ]);
    check('K1 union across modules, deduplicated, declaration order', $mm->getDoctrineEntities() === [Posting::class, Note::class]);
    check('K2 importEntities collected separately', $mm->getImportEntities() === [Marker::class]);
    $bad = $moduleManagerWith(['ledger' => ['doctrineEntities' => ['No\\Such\\Entity']]]);
    $msg = thrown(fn() => $bad->getDoctrineEntities(), \RuntimeException::class);
    check('K3 a non-existent class fails loudly', $msg !== null);
    check('K4 … naming key and module', str_contains((string) $msg, "doctrineEntities of module 'ledger'"));

    // ── K2. an entity that is mapped but not announced is refused ─────────────

    echo "K2. Unannounced entity\n";
    $msg = thrown(fn() => $uem->getRepository(Orphan::class), \RuntimeException::class);
    check('K5 getRepository() refused', $msg !== null && str_contains((string) $msg, 'doctrineEntities') && str_contains((string) $msg, Orphan::class));
    check('K6 persist() refused', thrown(fn() => $uem->persist(new Orphan()), \RuntimeException::class) !== null);
    check('K7 remove() refused', thrown(fn() => $uem->remove(new Orphan()), \RuntimeException::class) !== null);

    // ── M. backup: the connection comes from database.inc.php; a legacy block only blocks `db` ─

    echo "M. Backup reads the same connection config\n";
    $write('config/client/backup.inc.php', "<?php return ['dir' => 'backup', 'dump' => ['mysqldump' => 'mysqldump', 'user' => 'ro', 'password' => 'x']];");
    $svc = BackupService::fromProjectRoot($base);
    check('M1 database configured = name in database.inc.php', $svc->isDatabaseConfigured());
    $dumpConfig = (new ReflectionMethod($svc, 'dumpConfig'))->invoke($svc);
    check('M2 host and name from database.inc.php, backup user substituted', $dumpConfig['name'] === $dbName && $dumpConfig['user'] === 'ro' && $dumpConfig['password'] === 'x' && $dumpConfig['mysqldump'] === 'mysqldump');
    $write('config/client/backup.inc.php', "<?php return ['dir' => 'backup', 'database' => ['host' => 'old', 'name' => 'old']];");
    $svc = BackupService::fromProjectRoot($base);
    check('M3 legacy database block: constructing the service still works (data/full unaffected)', $svc instanceof BackupService && $svc->fullExcludes() !== []);
    $msg = thrown(fn() => (new ReflectionMethod($svc, 'dumpConfig'))->invoke($svc), \RuntimeException::class);
    check('M4 … but the db type refuses it, naming the move', $msg !== null && str_contains((string) $msg, 'config/client/database.inc.php'));

    // ── L. no database configured → a clear message, at the first Doctrine entity ─

    echo "L. No database configured\n";
    $writeDatabaseConfig('');
    $uem2 = $wireDi();   // fresh container: the config is re-read (never APCu-cached)
    check('L1 file entities still work', $uem2->getRepository(Marker::class)->findOneBy(['label' => 'mixed']) !== null);
    $msg = thrown(fn() => $uem2->getRepository(Note::class), \RuntimeException::class);
    check('L2 the first Doctrine entity fails', $msg !== null);
    check('L3 … pointing at config/client/database.inc.php', str_contains((string) $msg, 'config/client/database.inc.php'));

    $schemaEm->getConnection()->close();

    echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
    exit($fail === 0 ? 0 : 1);
}
