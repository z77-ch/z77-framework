<?php

namespace Z77\Persistence\Doctrine;

use Doctrine\ORM\EntityManager,
    Z77\Core\DI,
    Z77\Persistence\Interface\EntityManagerInterface
;

/**
 * Driver bootstrap, found by `UnifiedEntityManager::bootManager()` through the
 * `Z77\Persistence\{Driver}\Bootstrap` convention — the same shape as the
 * File driver's.
 *
 * Lazy by construction (ADR-001, ADR-039 decision 3): the resolver instantiates
 * this class on the FIRST Doctrine entity of a request and never on a request
 * that touches only files. Doctrine's EntityManager is built here; the DBAL
 * connection it holds opens on the first query, not on construction.
 *
 * Everything the driver needs is read once, from its single source:
 *   - the connection from `config/client/database.inc.php` (decision 4), the
 *     file the `db` backup reads as well — never a second copy of the
 *     credentials, and never cached in APCu;
 *   - the entity classes from the modules' `doctrineEntities` lists
 *     (decision 5) via `ModuleManager::getDoctrineEntities()`;
 *   - the base currency for `Money` columns from `systemConfig.inc.php`
 *     (`baseCurrency`, ADR-042 decision 4: the database carries amounts in
 *     the base currency only);
 *   - the cache directory from the kernel's `GeneratedPhpCache` (decision 11)
 *     — in production; in DEBUG the caches live in memory.
 *
 * `buildEntityManager()` is the one code path for all of that: the driver
 * uses it here, the migrations CLI (`bin/z77-db`) uses it to reach the same
 * database with the same metadata — never a second reading of the config.
 */
class Bootstrap
{
    private DoctrineEntityManager $entityManager;

    public function __construct()
    {
        $this->entityManager = new DoctrineEntityManager(self::buildEntityManager());
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    /**
     * Doctrine's EntityManager as the installation configures it. Shared with
     * the migrations CLI; never handed to a consumer (decision 6).
     *
     * @internal package use only
     */
    public static function buildEntityManager(): EntityManager
    {
        $configManager = DI::getConfigManager();

        $connection = $configManager
            ->getBaseConfig(configName: 'config/database', cachePersist: false)
            ->toArray()
        ;
        if (trim((string)($connection['name'] ?? '')) === '') {
            throw new \RuntimeException(
                'A Doctrine entity was requested but no database is configured — '
                . "set 'name' and the credentials in config/client/database.inc.php."
            );
        }

        $baseCurrency = (string)$configManager
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false, cachePersist: false)
            ->get('baseCurrency', 'CHF')
        ;

        // DEBUG (var/state/debug.flag): metadata in memory, rebuilt per request,
        // so an entity change shows without «Cache leeren». Otherwise compiled
        // PHP files under var/cache/doctrine — the directory the kernel's pool
        // owns and clears (decision 11).
        $cacheDir = (defined('DEBUG') && DEBUG)
            ? null
            : DI::getCacheManager()->generatedPhp()->dir('doctrine');

        return EntityManagerFactory::create(
            $connection,
            DI::getModuleManager()->getDoctrineEntities(),
            $baseCurrency,
            $cacheDir
        );
    }
}
