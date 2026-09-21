<?php

namespace Z77\Persistence\Doctrine;

use Doctrine\DBAL\DriverManager,
    Doctrine\DBAL\Types\Type,
    Doctrine\ORM\Configuration,
    Doctrine\ORM\EntityManager,
    Doctrine\ORM\Mapping\Driver\AttributeDriver,
    Doctrine\Persistence\Mapping\Driver\ClassNames,
    Psr\Cache\CacheItemPoolInterface,
    Symfony\Component\Cache\Adapter\ArrayAdapter,
    Z77\Persistence\Doctrine\Type\MoneyType
;

/**
 * Builds Doctrine's EntityManager the way ADR-039 fixes it — one place for the
 * connection parameters, the metadata driver and the caches, so a test with a
 * throwaway schema and the production boot construct the same thing.
 *
 *   - Metadata from PHP attributes over an EXPLICIT class list (decision 5):
 *     the modules announce their entities, nothing scans a directory.
 *   - Native lazy objects (decision 11): no proxy classes, no proxy directory;
 *     the reason the package requires PHP 8.4.
 *   - One charset and collation on the connection and as the default for every
 *     table the schema tooling creates: utf8mb4 / utf8mb4_unicode_ci
 *     (decision 18). `SET NAMES … COLLATE …` on connect, because a bare
 *     charset would take the SERVER's default collation for utf8mb4 — and a
 *     hoster's default is not necessarily ours.
 *   - A strict `sql_mode` on every connection: a value a column cannot hold
 *     is an error, never a silent clamp or truncation.
 *   - Result cache, hydration cache and second-level cache are not used.
 */
final class EntityManagerFactory
{
    public const CHARSET   = 'utf8mb4';
    public const COLLATION = 'utf8mb4_unicode_ci';

    /**
     * MariaDB 10.6's own default (minus NO_AUTO_CREATE_USER, a no-op there) —
     * pinned per session because a hoster's my.cnf may say otherwise, and a
     * non-strict mode turns an out-of-range DECIMAL into the column maximum
     * and an over-long string into a truncated one, both without an error.
     * STRICT_TRANS_TABLES is what makes InnoDB refuse; the other two keep
     * `x / 0` an error and a missing engine a failure instead of a fallback.
     */
    public const SQL_MODE = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * The package's OWN entities (ADR-039 decision 15): mapped on every
     * EntityManager in front of the modules' lists, so their tables exist
     * wherever the driver runs. The migrations command (part 3) reads this
     * constant too — the package's first migration is `number_range`.
     *
     * @var list<class-string>
     */
    public const PACKAGE_ENTITIES = [Entities\NumberRange::class];

    /**
     * @param array              $connection    host, port, name, user, password — `config/client/database.inc.php`
     * @param list<class-string> $entityClasses the modules' `doctrineEntities`; PACKAGE_ENTITIES are added here
     * @param string             $baseCurrency  ISO 4217 code every `Money` column is read in
     */
    public static function create(
        #[\SensitiveParameter] array $connection,
        array $entityClasses,
        string $baseCurrency
    ): EntityManager {
        self::registerTypes($baseCurrency);

        $classes = array_values(array_unique([...self::PACKAGE_ENTITIES, ...$entityClasses]));

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver(new ClassNames($classes)));
        $config->enableNativeLazyObjects(true);

        $cache = self::createCache();
        $config->setMetadataCache($cache);
        $config->setQueryCache($cache);

        return new EntityManager(
            DriverManager::getConnection(self::connectionParams($connection), $config),
            $config
        );
    }

    /**
     * Metadata and query cache. Part (1) of the package: an in-memory pool,
     * rebuilt per request. The production setup of ADR-039 decision 11 —
     * `PhpFilesAdapter` under `var/cache/doctrine/`, the DEBUG switch, «Cache
     * leeren» with OPcache invalidation — replaces this method's body and
     * nothing else.
     */
    private static function createCache(): CacheItemPoolInterface
    {
        return new ArrayAdapter();
    }

    /** @param array $connection host, port, name, user, password */
    private static function connectionParams(#[\SensitiveParameter] array $connection): array
    {
        // One init statement (the PDO option takes exactly one): what
        // `SET NAMES … COLLATE …` expands to, with the collation set AFTER
        // character_set_connection (setting the charset resets it), plus the
        // sql_mode. `charset` in the DSN as well, so DBAL's platform agrees.
        $init = sprintf(
            'SET character_set_client = %1$s, character_set_results = %1$s, character_set_connection = %1$s, '
            . "collation_connection = %2\$s, sql_mode = '%3\$s'",
            self::CHARSET,
            self::COLLATION,
            self::SQL_MODE
        );

        $params = [
            'driver'              => 'pdo_mysql',
            'host'                => (string)($connection['host'] ?? 'localhost'),
            'dbname'              => (string)$connection['name'],
            'user'                => (string)($connection['user'] ?? ''),
            'password'            => (string)($connection['password'] ?? ''),
            'charset'             => self::CHARSET,
            'defaultTableOptions' => ['charset' => self::CHARSET, 'collation' => self::COLLATION],
            'driverOptions'       => [\Pdo\Mysql::ATTR_INIT_COMMAND => $init],
        ];
        if (($connection['port'] ?? null) !== null) {
            $params['port'] = (int)$connection['port'];
        }

        return $params;
    }

    /**
     * DBAL's type registry is process-global; registering twice throws, so the
     * second EntityManager of a process (tests, the replacement after a
     * rollback) finds the type already there.
     */
    private static function registerTypes(string $baseCurrency): void
    {
        if (!Type::hasType(MoneyType::NAME)) {
            Type::addType(MoneyType::NAME, MoneyType::class);
        }
        MoneyType::useCurrency($baseCurrency);
    }
}
