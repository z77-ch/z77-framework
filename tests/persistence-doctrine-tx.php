<?php

/**
 * Transaction port, NumberRange and open-work registry harness (CLI) —
 * z77/persistence-doctrine part 2 against a REAL MariaDB (ADR-039 decision
 * 16), throwaway schema per run.
 *
 * What is load-bearing here (ADR-039 decisions 10 and 15):
 *
 *   - the port is obtained through `UnifiedEntityManager::getTransaction()`
 *     from an entity class; the File driver refuses it;
 *   - commit on return (including a persist without an explicit flush),
 *     rollback and rethrow on any exception, `isOpen()` true only inside;
 *   - nesting JOINS: nothing is visible to a second connection until the
 *     outermost returns; an inner exception rolls back the whole — also when
 *     the outer code catches it (`TransactionRolledBackException`), and also
 *     when what failed was a `flush()` whose exception was swallowed;
 *   - after a rollback the EntityManager is replaced: reads and writes go on,
 *     entities loaded before are detached;
 *   - `NumberRange`: sequential, a rolled-back unit of work does not consume
 *     a number, refused outside a transaction, ranges independent, and
 *     gapless under REAL concurrency — parallel PHP processes draw from one
 *     range, some of them rolling back, and the union is exactly 1..n;
 *   - the open-work registry: blocking vs warning, no checks = nothing open,
 *     a bad registration fails loudly naming scope and module.
 *
 * Run: php tests/persistence-doctrine-tx.php
 * Needs what tests/persistence-doctrine.php needs (vendor/, MariaDB,
 * credentials in `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*). The
 * script re-invokes ITSELF with `--worker` for the concurrency check.
 */

namespace {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "persistence-doctrine-tx: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
        exit(2);
    }
    require $autoload;
}

// ── fixtures ─────────────────────────────────────────────────────────────────

namespace Z77Test\Tx\Entities {

    use Doctrine\ORM\Mapping as ORM;
    use Z77\Shared\Attributes\Entity;
    use Z77\Shared\Traits\ArrayMappable;

    #[Entity('doctrine')]
    #[ORM\Entity, ORM\Table(name: 'probe_invoice'), ORM\HasLifecycleCallbacks]
    class Invoice
    {
        /** @var null|\Closure test hook, called from the preFlush lifecycle callback */
        public static ?\Closure $onPreFlush = null;

        #[ORM\Id, ORM\Column, ORM\GeneratedValue]
        private ?int $id = null;

        #[ORM\Column(length: 40)]
        private string $label;

        public function __construct(string $label)
        {
            $this->label = $label;
        }

        public function getId(): ?int         { return $this->id; }
        public function getLabel(): string    { return $this->label; }
        public function setLabel(string $l): void { $this->label = $l; }

        #[ORM\PreFlush]
        public function preFlush(): void
        {
            if (self::$onPreFlush !== null) { (self::$onPreFlush)(); }
        }
    }

    #[Entity('file', 'probe/flags.json')]
    class Flag
    {
        use ArrayMappable;

        private ?int $id = null;
        private string $label = '';

        public function __construct(string $label = '')
        {
            $this->label = $label;
        }

        public function getId(): ?int { return $this->id; }
    }
}

namespace Z77Test\Tx\Checks {

    use Z77\Persistence\Doctrine\OpenWork\Finding;
    use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;

    class BlockingCheck implements OpenWorkCheckInterface
    {
        /** @var list<array{0:string,1:array}> what every call received */
        public static array $calls = [];

        public function check(string $scope, array $parameters): iterable
        {
            self::$calls[] = [$scope, $parameters];
            yield Finding::blocking('2 invoices still in invoicing', 'invoice:7');
        }
    }

    class WarningCheck implements OpenWorkCheckInterface
    {
        public function check(string $scope, array $parameters): iterable
        {
            return [Finding::warning('3 invoiceable orders with service date in the period')];
        }
    }

    class SilentCheck implements OpenWorkCheckInterface
    {
        public function check(string $scope, array $parameters): iterable
        {
            return [];
        }
    }

    class BadYieldCheck implements OpenWorkCheckInterface
    {
        public function check(string $scope, array $parameters): iterable
        {
            yield 'not a finding';
        }
    }

    /** Looks like a check, is not one. */
    class NotACheck
    {
    }

    abstract class AbstractCheck implements OpenWorkCheckInterface
    {
    }

    class NeedsArgsCheck implements OpenWorkCheckInterface
    {
        public function __construct(private string $period) {}

        public function check(string $scope, array $parameters): iterable
        {
            return [];
        }
    }
}

namespace Z77Test\Tx\Repositories {

    use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

    /** Convention repository of Invoice: test-only probes on the driver's own connection. */
    class InvoiceRepository extends DoctrineRepository
    {
        public function sqlMode(): string
        {
            return (string) $this->connection()->fetchOne('SELECT @@sql_mode');
        }

        /** Session setting; lost — deliberately — when the port closes the connection. */
        public function setLockWaitTimeout(int $seconds): void
        {
            $this->connection()->executeStatement('SET innodb_lock_wait_timeout = ' . $seconds);
        }

        /** What nothing but the port may do: a transaction on the driver's connection behind the port's back. */
        public function beginBehindThePortsBack(): void
        {
            $this->connection()->beginTransaction();
        }

        public function rollBackBehindThePortsBack(): void
        {
            $this->connection()->rollBack();
        }
    }
}

namespace {

    use Doctrine\DBAL\DriverManager;
    use Doctrine\DBAL\Exception\DeadlockException;
    use Doctrine\ORM\Tools\SchemaTool;
    use Z77Test\Tx\Checks\AbstractCheck;
    use Z77Test\Tx\Checks\NeedsArgsCheck;
    use Z77Test\Tx\Repositories\InvoiceRepository;
    use Z77\Core\Config\Config;
    use Z77\Core\DI;
    use Z77\Core\Libraries\CacheManager;
    use Z77\Core\Libraries\ConfigManager;
    use Z77\Core\Libraries\FileFinder;
    use Z77\Core\Services\ModuleManager;
    use Z77\Persistence\Doctrine\Entities\NumberRange;
    use Z77\Persistence\Doctrine\EntityManagerFactory;
    use Z77\Persistence\Doctrine\OpenWork\Finding;
    use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;
    use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
    use Z77\Persistence\Exception\TransactionRolledBackException;
    use Z77\Persistence\Interface\TransactionInterface;
    use Z77\Persistence\Resolver\DataSourceResolver;
    use Z77\Persistence\Resolver\UnifiedEntityManager;
    use Z77Test\Tx\Checks\BadYieldCheck;
    use Z77Test\Tx\Checks\BlockingCheck;
    use Z77Test\Tx\Checks\NotACheck;
    use Z77Test\Tx\Checks\SilentCheck;
    use Z77Test\Tx\Checks\WarningCheck;
    use Z77Test\Tx\Entities\Flag;
    use Z77Test\Tx\Entities\Invoice;

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
        fwrite(STDERR, "persistence-doctrine-tx: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
        exit(2);
    }

    /** ModuleManager with injected module configs, no ConfigManager boot (as in tests/persistence-doctrine.php). */
    $moduleManagerWith = function (array $configsByModule): ModuleManager {
        $mm = new class extends ModuleManager {
            public array $fakeConfigs = [];
            public function __construct() {}
            public function getModuleKeys(): array { return array_keys($this->fakeConfigs); }
            public function getNamespacePrefix(string $moduleKey): string { return 'Z77Test\\Unregistered\\'; }
            public function getModuleConfig(string $moduleKey): ?Config
            {
                return isset($this->fakeConfigs[$moduleKey]) ? new Config($this->fakeConfigs[$moduleKey]) : null;
            }
        };
        $mm->fakeConfigs = $configsByModule;
        return $mm;
    };

    /** The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to what the driver reads. */
    $wireDi = function () use ($moduleManagerWith): UnifiedEntityManager {
        DI::getInstance(true)
            ->set('CacheManager', CacheManager::class, true)
            ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
            ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
            ->set('ModuleManager', fn() => $moduleManagerWith([
                'probe' => ['doctrineEntities' => [Invoice::class]],
            ]), true)
            ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
            ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
        ;
        return DI::getUnifiedEntityManager();
    };

    // ── worker mode: one of the parallel processes of the concurrency check ─
    //
    // php tests/persistence-doctrine-tx.php --worker <base> <range> <count> <rollbackEvery>
    // Draws <count> numbers from <range>, each in its own unit of work, and
    // throws inside every <rollbackEvery>th one. Prints JSON: the numbers it
    // KEPT and how many it rolled back.

    // ── lock-b mode: the other side of the deadlock check (raw PDO) ──────────
    //
    // php tests/persistence-doctrine-tx.php --lock-b <db> <fromId> <toId> <victimId>
    // Locks rows fromId..toId, says so on stdout, waits a second, then wants
    // <victimId> — which the parent holds by then. The parent then touches a
    // row of ours: deadlock; InnoDB picks the SMALLER transaction (the parent,
    // one row) as the victim, we get our row, commit and exit 0.

    if (($argv[1] ?? '') === '--lock-b') {
        [, , $db, $fromId, $toId, $victimId] = $argv;
        $pdo = new \PDO("mysql:host={$credentials['host']};dbname={$db}", $credentials['user'], $credentials['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->exec("UPDATE probe_invoice SET label = CONCAT('B-', label) WHERE id BETWEEN " . (int) $fromId . ' AND ' . (int) $toId);
        echo "locked\n";
        flush();
        usleep(1_000_000);
        $pdo->exec('UPDATE probe_invoice SET label = CONCAT(\'B-\', label) WHERE id = ' . (int) $victimId);
        $pdo->commit();
        echo "committed\n";
        exit(0);
    }

    if (($argv[1] ?? '') === '--worker') {
        [, , $workerBase, $range, $count, $rollbackEvery] = $argv;
        define('ABS_BASE_PATH', $workerBase);
        define('DEBUG', false);

        $uem   = $wireDi();
        $tx    = $uem->getTransaction(Invoice::class);
        $repo  = $uem->getRepository(NumberRange::class);
        $kept  = [];
        $rolledBack = 0;
        for ($i = 1; $i <= (int) $count; $i++) {
            try {
                $kept[] = $tx->run(function () use ($repo, $range, $i, $rollbackEvery) {
                    $n = $repo->next($range);
                    usleep(random_int(0, 1500));              // hold the lock a little, interleave
                    if ($i % (int) $rollbackEvery === 0) {
                        throw new \RuntimeException('deliberate rollback');
                    }
                    return $n;
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'deliberate rollback') {
                    throw $e;
                }
                $rolledBack++;
            }
        }
        echo json_encode(['kept' => $kept, 'rolledBack' => $rolledBack, 'pid' => getmypid()]);
        exit(0);
    }

    // ── throwaway schema ─────────────────────────────────────────────────────

    $dbName = 'z77test_' . bin2hex(random_bytes(4));
    $admin  = DriverManager::getConnection([
        'driver'   => 'pdo_mysql',
        'host'     => $credentials['host'],
        'user'     => $credentials['user'],
        'password' => $credentials['password'],
    ]);
    $admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-doctrine-tx-' . getmypid();
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

    $write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'tpl'], 'namespaces' => []];");
    $write('config/client/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
    $write('config/client/database.inc.php', '<?php return ' . var_export([
        'host'     => $credentials['host'],
        'port'     => null,
        'name'     => $dbName,
        'user'     => $credentials['user'],
        'password' => $credentials['password'],
    ], true) . ';');

    $connection = [
        'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
        'user' => $credentials['user'], 'password' => $credentials['password'],
    ];
    // Schema for the probe entity; `number_range` comes along through PACKAGE_ENTITIES.
    $schemaEm = EntityManagerFactory::create($connection, [Invoice::class], 'CHF');
    (new SchemaTool($schemaEm))->createSchema($schemaEm->getMetadataFactory()->getAllMetadata());
    $db = $schemaEm->getConnection();   // a SECOND connection: what it sees is committed

    $uem      = $wireDi();
    $invoices = $uem->getRepository(Invoice::class);
    $rows     = fn(string $label) => (int) $db->fetchOne('SELECT COUNT(*) FROM probe_invoice WHERE label = ?', [$label]);
    $lastNumber = fn(string $range) => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ?', [$range]);

    // ── A. obtaining the port ────────────────────────────────────────────────

    echo "A. The port\n";
    $tx = $uem->getTransaction(Invoice::class);
    check('A1 resolved from a Doctrine entity class', $tx instanceof TransactionInterface);
    check('A2 one port per driver — the same for every Doctrine entity', $uem->getTransaction(NumberRange::class) === $tx);
    check('A3 nothing open before use', $tx->isOpen() === false);
    $msg = thrown(fn() => $uem->getTransaction(Flag::class), \LogicException::class);
    check('A4 the File driver refuses it', $msg !== null);
    check('A5 … naming ARCH-A003', str_contains((string) $msg, 'ARCH-A003'));

    // ── B. commit on return ──────────────────────────────────────────────────

    echo "B. Commit on return\n";
    $openInside = null;
    $result = $tx->run(function () use ($uem, $tx, &$openInside) {
        $uem->persist(new Invoice('B-flushed'));
        $uem->flush();
        $openInside = $tx->isOpen();
        return 'the result';
    });
    check('B1 the unit of work\'s return value comes back', $result === 'the result');
    check('B2 isOpen() true inside', $openInside === true);
    check('B3 … false after', $tx->isOpen() === false);
    check('B4 committed — visible to a second connection', $rows('B-flushed') === 1);

    $tx->run(fn() => $uem->persist(new Invoice('B-unflushed')));
    check('B5 a persist without an explicit flush is flushed and committed on return', $rows('B-unflushed') === 1);

    $loaded = $invoices->findOneBy(['label' => 'B-flushed']);
    check('B6 the row reads back through the repository', $loaded instanceof Invoice);

    // ── C. rollback on exception, rethrown ───────────────────────────────────

    echo "C. Rollback on exception\n";
    $boom = new \DomainException('domain says no');
    try {
        $tx->run(function () use ($uem, $boom) {
            $uem->persist(new Invoice('C-lost'));
            $uem->flush();
            throw $boom;
        });
        check('C1 the exception is rethrown', false);
    } catch (\DomainException $e) {
        check('C1 the exception is rethrown — the very same instance', $e === $boom);
    }
    check('C2 nothing committed', $rows('C-lost') === 0);
    check('C3 isOpen() false after the rollback', $tx->isOpen() === false);
    check('C4 nothing left open on the connection either', !$db->isTransactionActive());

    // ── D. the EntityManager is replaced after a rollback ────────────────────

    echo "D. EntityManager replaced\n";
    $again = $invoices->findOneBy(['label' => 'B-flushed']);
    check('D1 reads work after the rollback, through the repository obtained before it', $again instanceof Invoice && $again->getId() === $loaded->getId());
    check('D2 … but the entity loaded before is detached: a fresh object now', $again !== $loaded);
    $uem->persist(new Invoice('D-after'));
    $uem->flush();
    check('D3 writes work again (no closed EntityManager)', $rows('D-after') === 1);
    $tx->run(fn() => $uem->persist(new Invoice('D-tx-after')));
    check('D4 … and so does the port', $rows('D-tx-after') === 1);

    // ── E. nesting joins ─────────────────────────────────────────────────────

    echo "E. Nesting joins\n";
    $visibleAfterInner = null;
    $openInInner       = null;
    $tx->run(function () use ($uem, $tx, $rows, &$visibleAfterInner, &$openInInner) {
        $tx->run(function () use ($uem, $tx, &$openInInner) {
            $uem->persist(new Invoice('E-inner'));
            $uem->flush();
            $openInInner = $tx->isOpen();
        });
        $visibleAfterInner = $rows('E-inner');   // the second connection: committed rows only
        $uem->persist(new Invoice('E-outer'));
        $uem->flush();
    });
    check('E1 no inner commit: the inner write is invisible to a second connection until the outermost returns', $visibleAfterInner === 0);
    check('E2 isOpen() true in the inner unit of work', $openInInner === true);
    check('E3 both committed when the outermost returns', $rows('E-inner') === 1 && $rows('E-outer') === 1);
    check('E4 closed afterwards', $tx->isOpen() === false && !$db->isTransactionActive());

    // ── F. inner exception propagates through the outer ──────────────────────

    echo "F. Inner exception, propagated\n";
    $msg = thrown(function () use ($uem, $tx) {
        $tx->run(function () use ($uem, $tx) {
            $uem->persist(new Invoice('F-outer'));
            $uem->flush();
            $tx->run(function () use ($uem) {
                $uem->persist(new Invoice('F-inner'));
                $uem->flush();
                throw new \RuntimeException('inner failed');
            });
        });
    }, \RuntimeException::class);
    check('F1 the inner exception comes out of the outermost run()', $msg === 'inner failed');
    check('F2 the whole is rolled back — outer write included', $rows('F-outer') === 0 && $rows('F-inner') === 0);
    check('F3 closed afterwards', $tx->isOpen() === false && !$db->isTransactionActive());

    // ── G. inner exception CAUGHT by the outer code ──────────────────────────

    echo "G. Inner exception, caught by the outer\n";
    $outerReached = false;
    $msg = thrown(function () use ($uem, $tx, &$outerReached) {
        $tx->run(function () use ($uem, $tx, &$outerReached) {
            $uem->persist(new Invoice('G-before'));
            $uem->flush();
            try {
                $tx->run(function () {
                    throw new \RuntimeException('inner failed, swallowed');
                });
            } catch (\RuntimeException) {
                // the outer code thinks it can go on
            }
            $uem->persist(new Invoice('G-after'));
            $uem->flush();
            $outerReached = true;
            return 'would have committed';
        });
    }, TransactionRolledBackException::class);
    check('G1 the outermost run() throws TransactionRolledBackException instead of committing', $msg !== null);
    check('G2 … the outer code did run to its end', $outerReached);
    check('G3 nothing committed — before or after the swallowed failure', $rows('G-before') === 0 && $rows('G-after') === 0);
    check('G4 closed afterwards, state reset', $tx->isOpen() === false && !$db->isTransactionActive());
    $tx->run(fn() => $uem->persist(new Invoice('G-next')));
    check('G5 the next unit of work commits normally', $rows('G-next') === 1);

    // ── H. a failed flush() inside a unit of work ────────────────────────────

    echo "H. Failed flush inside\n";
    $tooLong = str_repeat('x', 41);   // label is VARCHAR(40); strict sql_mode makes it an error
    $msg = thrown(function () use ($uem, $tx, $tooLong) {
        $tx->run(function () use ($uem, $tooLong) {
            $uem->persist(new Invoice('H-before'));
            $uem->flush();
            try {
                $uem->persist(new Invoice($tooLong));
                $uem->flush();
            } catch (\Throwable) {
                // swallowed — Doctrine has closed its EntityManager by now
            }
            return 'went on';
        });
    }, TransactionRolledBackException::class);
    check('H1 a swallowed flush failure poisons the unit of work: TransactionRolledBackException', $msg !== null);
    check('H2 nothing committed', $rows('H-before') === 0);
    check('H3 closed afterwards', $tx->isOpen() === false && !$db->isTransactionActive());
    check('H4 reads work afterwards', $invoices->findOneBy(['label' => 'G-next']) instanceof Invoice);

    $msg = thrown(function () use ($uem, $tx, $tooLong) {
        $tx->run(function () use ($uem, $tooLong) {
            $uem->persist(new Invoice($tooLong));
            $uem->flush();
        });
    }, \Doctrine\DBAL\Exception::class);
    check('H5 an unswallowed flush failure surfaces as the driver\'s exception', $msg !== null);

    // ── I. a failed flush() OUTSIDE any unit of work ─────────────────────────

    echo "I. Failed flush outside\n";
    $uem->persist(new Invoice($tooLong));
    check('I1 the flush fails', thrown(fn() => $uem->flush(), \Doctrine\DBAL\Exception::class) !== null);
    $uem->persist(new Invoice('I-after'));
    $uem->flush();
    check('I2 the EntityManager was replaced: the next write works', $rows('I-after') === 1);

    // ── J. NumberRange ───────────────────────────────────────────────────────

    echo "J. NumberRange\n";
    $ranges = $uem->getRepository(NumberRange::class);
    check('J1 the package entity is announced without a module config (PACKAGE_ENTITIES)', $ranges instanceof NumberRangeRepository);
    $msg = thrown(fn() => $ranges->next('invoice'), \LogicException::class);
    check('J2 refused outside a transaction', $msg !== null && str_contains((string) $msg, 'transaction'));
    check('J3 … and no row was created by the refusal', $lastNumber('invoice') === false);

    $drawn = $tx->run(fn() => [$ranges->next('invoice'), $ranges->next('invoice'), $ranges->next('invoice')]);
    check('J4 sequential from 1', $drawn === [1, 2, 3]);
    check('J5 the row holds the last number after commit', (int) $lastNumber('invoice') === 3);

    thrown(fn() => $tx->run(function () use ($ranges) {
        $ranges->next('invoice');   // 4 — never committed
        throw new \RuntimeException('roll it back');
    }), \RuntimeException::class);
    check('J6 a rolled-back unit of work does not consume a number', (int) $lastNumber('invoice') === 3);
    check('J7 … the next caller gets it', $tx->run(fn() => $ranges->next('invoice')) === 4);

    thrown(fn() => $tx->run(function () use ($tx, $ranges) {
        $tx->run(fn() => $ranges->next('invoice'));   // drawn in a joined unit of work
        throw new \RuntimeException('outer fails');
    }), \RuntimeException::class);
    check('J8 drawn in a nested unit of work, outer fails: not consumed either', $tx->run(fn() => $ranges->next('invoice')) === 5);

    check('J9 a second range is independent', $tx->run(fn() => $ranges->next('credit-note')) === 1 && (int) $lastNumber('invoice') === 5);
    check('J10 a per-year key is just a name', $tx->run(fn() => $ranges->next('journal-entry.2026')) === 1);

    foreach (['' => 'empty', ' invoice' => 'padded', str_repeat('n', 65) => 'over-long'] as $name => $why) {
        check("J11 {$why} name refused before SQL", thrown(fn() => $tx->run(fn() => $ranges->next($name)), \InvalidArgumentException::class) !== null);
    }
    check('J12 a 64-byte name passes', $tx->run(fn() => $ranges->next(str_repeat('n', 64))) === 1);

    echo "J. … explicit creation (DOCTRINE-NR-003)\n";
    $ranges->create('journal-entry.2027');
    check('J13 create() outside a transaction: row committed at 0, no number consumed', $lastNumber('journal-entry.2027') !== false && (int) $lastNumber('journal-entry.2027') === 0);
    check('J14 … the first draw still returns 1', $tx->run(fn() => $ranges->next('journal-entry.2027')) === 1);
    $ranges->create('journal-entry.2027');
    $ranges->create('credit-note');
    check('J15 create() on an existing range is idempotent: nothing reset, nothing consumed', (int) $lastNumber('journal-entry.2027') === 1 && (int) $lastNumber('credit-note') === 1);
    check('J16 … the sequence goes on', $tx->run(fn() => $ranges->next('credit-note')) === 2);
    $tx->run(fn() => $ranges->create('journal-entry.2028'));
    check('J17 create() inside run() is committed with the unit of work', (int) $lastNumber('journal-entry.2028') === 0);
    thrown(fn() => $tx->run(function () use ($ranges) {
        $ranges->create('journal-entry.2029');
        throw new \RuntimeException('opening failed');
    }), \RuntimeException::class);
    check('J18 … and rolled back with it', $lastNumber('journal-entry.2029') === false);
    check('J19 create() refuses a bad name before SQL', thrown(fn() => $ranges->create(' padded'), \InvalidArgumentException::class) !== null && $lastNumber(' padded') === false);

    // ── K. NumberRange under REAL concurrency: parallel processes ────────────

    echo "K. Concurrency (parallel PHP processes)\n";
    $workers       = 4;
    $perWorker     = 25;
    $rollbackEvery = 5;    // every 5th draw of a worker is rolled back → 20 kept per worker
    $expectedKept  = $workers * ($perWorker - intdiv($perWorker, $rollbackEvery));

    $procs = [];
    for ($w = 0; $w < $workers; $w++) {
        $procs[$w] = proc_open(
            [PHP_BINARY, __FILE__, '--worker', $base, 'concurrent', (string) $perWorker, (string) $rollbackEvery],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes[$w]
        );
        check("K0 worker {$w} started", is_resource($procs[$w]));
    }
    $union = [];
    $rolledBack = 0;
    $allExitedClean = true;
    foreach ($procs as $w => $proc) {
        $out  = stream_get_contents($pipes[$w][1]);
        $err  = stream_get_contents($pipes[$w][2]);
        fclose($pipes[$w][1]);
        fclose($pipes[$w][2]);
        $code = proc_close($proc);
        $data = json_decode($out, true);
        if ($code !== 0 || !is_array($data)) {
            $allExitedClean = false;
            echo "       worker {$w}: exit {$code}\n" . ($err !== '' ? "       stderr: " . trim($err) . "\n" : '') . ($out !== '' ? "       stdout: " . trim($out) . "\n" : '');
            continue;
        }
        check("K1 worker {$w} kept " . count($data['kept']) . ", rolled back {$data['rolledBack']}", count($data['kept']) === $perWorker - intdiv($perWorker, $rollbackEvery) && $data['rolledBack'] === intdiv($perWorker, $rollbackEvery));
        $union = array_merge($union, $data['kept']);
        $rolledBack += $data['rolledBack'];
    }
    check('K2 every worker exited clean (no deadlock, no error)', $allExitedClean);
    sort($union);
    check("K3 the union of all kept numbers is exactly 1..{$expectedKept} — no gap, no duplicate", $union === range(1, $expectedKept));
    check('K4 the row agrees: last_number = ' . $expectedKept, (int) $lastNumber('concurrent') === $expectedKept);
    check('K5 the parent\'s own ranges were untouched', (int) $lastNumber('invoice') === 5);

    // ── M. isOpen() until the commit is through; the guard at depth 0 ────────

    echo "M. isOpen() during commit, depth-0 guard\n";
    check('M0 the convention repository of Invoice (test probes on the driver connection)', $invoices instanceof InvoiceRepository);
    $seenInPreFlush = null;
    Invoice::$onPreFlush = function () use ($tx, &$seenInPreFlush) { $seenInPreFlush = $tx->isOpen(); };
    $tx->run(fn() => $uem->persist(new Invoice('M-open')));
    Invoice::$onPreFlush = null;
    check('M1 isOpen() is still true while the outermost run() flushes before its commit', $seenInPreFlush === true);
    check('M2 … and false once it committed', $tx->isOpen() === false && $rows('M-open') === 1);

    $invoices->beginBehindThePortsBack();
    $msg = thrown(fn() => $tx->run(fn() => null), \LogicException::class);
    check('M3 run() refuses a transaction the port did not start (it would nest into a savepoint)', $msg !== null && str_contains((string) $msg, 'did not start'));
    $invoices->rollBackBehindThePortsBack();
    check('M4 … and works again once that is gone', $tx->run(fn() => 'ok') === 'ok');

    // ── N. a failed statement inside next() poisons the unit of work ─────────

    echo "N. Failed statement in next()\n";
    $invoices->setLockWaitTimeout(1);
    $db->beginTransaction();
    $db->executeStatement("INSERT INTO number_range (name, last_number) VALUES ('held', 0)");   // uncommitted: everyone else waits on it
    $msg = thrown(function () use ($uem, $tx, $ranges) {
        $tx->run(function () use ($uem, $ranges) {
            $uem->persist(new Invoice('N-before'));
            $uem->flush();
            try {
                $ranges->next('held');
            } catch (\Throwable) {
                // swallowed: the lock wait timeout rolled back that statement only — the server transaction is still open
            }
            return 'went on';
        });
    }, TransactionRolledBackException::class);
    check('N1 a swallowed lock-wait failure in next() ends in TransactionRolledBackException', $msg !== null);
    check('N2 nothing committed', $rows('N-before') === 0);
    $msg = thrown(fn() => $tx->run(fn() => $ranges->next('held')), \Doctrine\DBAL\Exception\LockWaitTimeoutException::class);
    check('N3 not swallowed: the driver\'s LockWaitTimeoutException surfaces as is', $msg !== null);
    $db->rollBack();
    check('N4 closed afterwards', $tx->isOpen() === false && !$db->isTransactionActive());
    check('N5 … and the held range was never created by the parent', $lastNumber('held') === false);

    // ── O. deadlock inside flush(): two processes ────────────────────────────
    //
    // B (raw PDO, --lock-b) holds rows 2..10 and then wants row 1. A (the
    // port) holds row 1 after its first flush and then wants row 2 in a second
    // flush → deadlock, InnoDB picks A (one row) as the victim. The server
    // rolls A back and discards its savepoints; Doctrine's UnitOfWork then
    // tries ROLLBACK TO SAVEPOINT on a savepoint that is gone.

    echo "O. Deadlock inside flush() (two processes)\n";
    for ($i = 1; $i <= 10; $i++) { $uem->persist(new Invoice("dl-{$i}")); }
    $uem->flush();
    $ids = array_map('intval', $db->fetchFirstColumn("SELECT id FROM probe_invoice WHERE label LIKE 'dl-%' ORDER BY id"));
    [$victimId, $fromId, $toId] = [$ids[0], $ids[1], $ids[9]];

    $deadlock = function (bool $swallow) use ($uem, $tx, $invoices, $ranges, $dbName, $victimId, $fromId, $toId): array {
        $pipes = [];
        $proc  = proc_open(
            [PHP_BINARY, __FILE__, '--lock-b', $dbName, (string) $fromId, (string) $toId, (string) $victimId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $outcome = ['bLocked' => trim((string) fgets($pipes[1])) === 'locked', 'exception' => null];   // blocks until B holds its rows
        try {
            $tx->run(function () use ($uem, $invoices, $ranges, $victimId, $fromId, $swallow) {
                $invoices->find($victimId)->setLabel('A-first');
                $uem->flush();                       // A holds the victim row
                usleep(1_500_000);                   // B is waiting for it by now
                $invoices->find($fromId)->setLabel('A-second');
                try {
                    $uem->flush();                   // waits for B's row → deadlock, A is the victim
                } catch (\Throwable $e) {
                    if (!$swallow) { throw $e; }
                    $ranges->next('swallowed');      // «goes on» — the server transaction is already gone
                }
                return 'returned';
            });
        } catch (\Throwable $e) {
            $outcome['exception'] = $e;
        }
        $bOut = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $outcome['bExit']      = proc_close($proc);
        $outcome['bCommitted'] = str_contains($bOut, 'committed');
        return $outcome;
    };
    /** The deadlock itself, or anywhere down the previous chain (PHP appends the exception a finally block replaced). */
    $isDeadlock = function (?\Throwable $e): bool {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof DeadlockException) { return true; }
        }
        return false;
    };
    $describe = function (?\Throwable $e): string {
        $chain = [];
        for (; $e !== null; $e = $e->getPrevious()) { $chain[] = get_class($e); }
        return $chain === [] ? 'nothing' : implode(' → ', $chain);
    };

    $o = $deadlock(false);
    check('O1 B locked its rows first', $o['bLocked']);
    check('O2 the deadlock surfaces from run() — got ' . $describe($o['exception']), $isDeadlock($o['exception']));
    check('O3 B got its row, committed, exit 0', $o['bCommitted'] && $o['bExit'] === 0);
    check('O4 isOpen() false afterwards', !$tx->isOpen());
    $tx->run(fn() => $uem->persist(new Invoice('O-after-deadlock')));
    check('O5 the next run() in the same process truly commits (a second connection sees it)', $rows('O-after-deadlock') === 1);
    check('O6 the reconnected driver connection has the strict sql_mode again', str_contains($invoices->sqlMode(), 'STRICT_TRANS_TABLES'));
    check('O7 the victim row carries B\'s change, not A\'s', $invoices->find($victimId)->getLabel() === 'B-dl-1');
    $n = $tx->run(fn() => $ranges->next('after-deadlock'));
    $db->executeStatement('SET innodb_lock_wait_timeout = 2');
    $db->beginTransaction();
    $rangeLockable  = thrown(fn() => $db->fetchOne('SELECT last_number FROM number_range WHERE name = ? FOR UPDATE', ['after-deadlock']), \Throwable::class) === null;
    $victimLockable = thrown(fn() => $db->fetchOne('SELECT id FROM probe_invoice WHERE id = ? FOR UPDATE', [$victimId]), \Throwable::class) === null;
    $db->rollBack();
    check('O8 no lingering locks: another connection can lock the new range row and the victim row', $n === 1 && $rangeLockable && $victimLockable);

    $o = $deadlock(true);
    check('O9 swallowed deadlock in flush(), then a draw, normal return: TransactionRolledBackException — got ' . $describe($o['exception']), $o['exception'] instanceof TransactionRolledBackException);
    check('O10 the draw after the swallowed failure was never committed', $lastNumber('swallowed') === false);
    check('O11 B committed again, exit 0', $o['bCommitted'] && $o['bExit'] === 0);
    $tx->run(fn() => $uem->persist(new Invoice('O-after-swallow')));
    check('O12 the next run() truly commits', $rows('O-after-swallow') === 1);
    check('O13 nothing open anywhere', !$tx->isOpen() && !$db->isTransactionActive());

    // ── L. open-work registry ────────────────────────────────────────────────

    echo "L. Open-work registry\n";
    $none = new OpenWorkChecks([]);
    $answer = $none->ask('period-close', ['fiscalYear' => 2026, 'period' => 3]);
    check('L1 no checks registered: nothing open', $answer->isEmpty() && !$answer->isBlocked() && $answer->blocking() === [] && $answer->warnings() === []);

    $registry = new OpenWorkChecks([
        'period-close' => [BlockingCheck::class, WarningCheck::class, SilentCheck::class],
        'stocktake'    => [WarningCheck::class],
    ]);
    $answer = $registry->ask('period-close', ['fiscalYear' => 2026, 'period' => 3]);
    check('L3 one blocking and one warning finding', count($answer->blocking()) === 1 && count($answer->warnings()) === 1);
    check('L4 blocked', $answer->isBlocked() && !$answer->isEmpty());
    check('L5 the finding carries message and opaque reference', $answer->blocking()[0]->message === '2 invoices still in invoicing' && $answer->blocking()[0]->reference === 'invoice:7');
    check('L6 scope and parameters reach the check untouched', BlockingCheck::$calls === [['period-close', ['fiscalYear' => 2026, 'period' => 3]]]);
    $answer = $registry->ask('stocktake', ['countedAt' => '2026-12-31 08:00']);
    check('L7 a warning alone: something open, not blocked', !$answer->isEmpty() && !$answer->isBlocked() && count($answer->warnings()) === 1);
    check('L8 a scope nobody registered for: nothing open', $registry->ask('year-end')->isEmpty());

    echo "L. … bad registrations fail loudly\n";
    $msg = thrown(fn() => new OpenWorkChecks(['period-close' => ['No\\Such\\Check']]), \RuntimeException::class);
    check('L9 non-existent class', $msg !== null && str_contains((string) $msg, "scope 'period-close'") && str_contains((string) $msg, var_export('No\\Such\\Check', true)));
    $msg = thrown(fn() => new OpenWorkChecks(['period-close' => [NotACheck::class]]), \RuntimeException::class);
    check('L10 class that is not a check', $msg !== null && str_contains((string) $msg, 'does not implement'));
    $msg = thrown(fn() => new OpenWorkChecks([BlockingCheck::class]), \RuntimeException::class);
    check('L11 a flat list instead of scope => classes', $msg !== null && str_contains((string) $msg, 'scope must be a non-empty string'));
    $msg = thrown(fn() => new OpenWorkChecks(['period-close' => BlockingCheck::class]), \RuntimeException::class);
    check('L12 a class instead of a list', $msg !== null && str_contains((string) $msg, 'list of check classes'));
    $msg = thrown(fn() => (new OpenWorkChecks(['x' => [BadYieldCheck::class]]))->ask('x'), \RuntimeException::class);
    check('L13 a check yielding something that is not a Finding', $msg !== null && str_contains((string) $msg, BadYieldCheck::class));
    check('L14 a finding without a message', thrown(fn() => Finding::warning('  '), \InvalidArgumentException::class) !== null);
    $msg = thrown(fn() => new OpenWorkChecks(['x' => [AbstractCheck::class]]), \RuntimeException::class);
    check('L18 an abstract check is refused at registration', $msg !== null && str_contains((string) $msg, 'cannot be instantiated'));
    $msg = thrown(fn() => new OpenWorkChecks(['x' => [NeedsArgsCheck::class]]), \RuntimeException::class);
    check('L19 a check whose constructor requires arguments is refused at registration', $msg !== null && str_contains((string) $msg, 'requires arguments'));

    echo "L. … collected from the modules' config\n";
    $modules = $moduleManagerWith([
        'debtor' => ['openWorkChecks' => ['period-close' => [BlockingCheck::class, SilentCheck::class]]],
        'order'  => ['openWorkChecks' => ['period-close' => [WarningCheck::class, BlockingCheck::class], 'stocktake' => [WarningCheck::class]]],
        'vat'    => ['doctrineEntities' => []],
    ]);
    $fromModules = OpenWorkChecks::fromModules($modules);
    $closing = $fromModules->ask('period-close');
    check('L15 union across modules, deduplicated, declaration order', count($closing->blocking()) === 1 && count($closing->warnings()) === 1 && !$fromModules->ask('stocktake')->isEmpty());
    $bad = $moduleManagerWith(['ledger' => ['openWorkChecks' => ['period-close' => [NotACheck::class]]]]);
    $msg = thrown(fn() => OpenWorkChecks::fromModules($bad), \RuntimeException::class);
    check('L16 a bad entry names the module', $msg !== null && str_contains((string) $msg, "module 'ledger'"));
    $bad = $moduleManagerWith(['ledger' => ['openWorkChecks' => 'nope']]);
    check('L17 a non-array key fails', thrown(fn() => OpenWorkChecks::fromModules($bad), \RuntimeException::class) !== null);

    $schemaEm->getConnection()->close();

    echo "\n" . ($fail === 0 ? "PASS — {$pass} checks" : "FAIL — {$fail} of " . ($pass + $fail) . " checks") . "\n";
    exit($fail === 0 ? 0 : 1);
}
