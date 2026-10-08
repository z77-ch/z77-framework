<?php

namespace Z77\Persistence\Doctrine\Console;

use Z77\Core\Libraries\FileFinder,
    Z77\Core\Services\ModuleManager
;

/**
 * Where the migrations are (ADR-039 decision 13): namespace → directory,
 * collected from the package itself and from every module that declares
 * `doctrineEntities`. The same explicit sources as the entity list — nothing
 * scans for migration directories that no entity announcement points at.
 *
 *   - the package: `Z77\Persistence\Doctrine\Migrations` → `res/migrations`
 *     of this package (its first migration is `number_range`);
 *   - a module: `{Module}\Migrations` → `res/migrations` under the module's
 *     source path. The namespace is derived from the module key through
 *     `ModuleManager::getNamespacePrefix()` (Rule 5), the directory sits next
 *     to `src/` like `res/view/templates` does.
 *
 *   - the project: `Z77\Project\Migrations` → `override/z77/project/res/migrations`
 *     under the project root, when that directory exists. ONE directory for
 *     everything the project's own mapping changes (an entity it adds through
 *     `doctrineEntitiesConfig.inc.php`, or a module entity it shadows under
 *     `override/`) — `z77-db setup` writes there when the changed entity's
 *     class file lies under `override/` (ADR-039 addendum 2026-10-08).
 *
 * A module with entities but no `res/migrations` directory yet is simply not
 * listed (Doctrine refuses a configured directory that does not exist). A
 * module whose directory exists under MORE than one source path — package
 * AND `override/z77/module/...` — is refused: Doctrine binds one directory
 * per namespace, and silently taking either would hide the other's
 * migrations. A project's migrations go to the one project directory above,
 * never under `override/z77/module/{module}/res/migrations`.
 */
final class MigrationDirectories
{
    public const PACKAGE_NAMESPACE = 'Z77\\Persistence\\Doctrine\\Migrations';

    /** Relative to a package or module root — the sibling of `src/`. */
    public const RELATIVE_DIR = 'res/migrations';

    /** The project's own migrations (ADR-039 addendum 2026-10-08). */
    public const PROJECT_NAMESPACE = 'Z77\\Project\\Migrations';

    /** Relative to the project root. */
    public const PROJECT_RELATIVE_DIR = 'override/z77/project/' . self::RELATIVE_DIR;

    /**
     * @param ?string $projectRoot null = `ABS_BASE_PATH`; the project directory is listed when it exists
     * @return array<string, string> namespace → absolute directory, the package first, the project last
     */
    public static function collect(ModuleManager $modules, FileFinder $files, ?string $projectRoot = null): array
    {
        $found       = self::scan($modules, $files)['found'];
        $projectRoot ??= defined('ABS_BASE_PATH') ? (string)ABS_BASE_PATH : null;
        if ($projectRoot !== null && is_dir(self::projectDirectory($projectRoot))) {
            $found[self::PROJECT_NAMESPACE] = self::projectDirectory($projectRoot);
        }

        return $found;
    }

    /** `override/z77/project/res/migrations` under the given project root — whether or not it exists. */
    public static function projectDirectory(string $projectRoot): string
    {
        return rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . self::PROJECT_RELATIVE_DIR;
    }

    /**
     * The modules that declare entities but have no `res/migrations` yet:
     * namespace → the directory to create (under the module's package, the
     * last of its source paths — `override/` comes first in lookup order).
     * For the message `diff` / `generate` print instead of Doctrine's
     * «Path not defined».
     *
     * @return array<string, string>
     */
    public static function missing(ModuleManager $modules, FileFinder $files): array
    {
        return self::scan($modules, $files)['missing'];
    }

    /** @return array{found: array<string, string>, missing: array<string, string>} */
    private static function scan(ModuleManager $modules, FileFinder $files): array
    {
        $found   = [self::PACKAGE_NAMESPACE => self::packageDirectory()];
        $missing = [];

        foreach ($modules->getModuleKeys() as $moduleKey) {
            if (!self::declaresDoctrineEntities($modules, $moduleKey)) {
                continue;
            }
            $namespacePrefix = $modules->getNamespacePrefix($moduleKey);   // `Z77\Module\Financial\`
            $namespace       = $namespacePrefix . 'Migrations';
            $candidates      = [];
            $existing        = [];
            foreach ($files->getBasePaths($namespacePrefix, 'sourcePaths') as $basePath) {
                $dir          = rtrim(str_replace('\\', '/', $basePath), '/') . '/' . self::RELATIVE_DIR;
                $candidates[] = $dir;
                if (is_dir($dir)) {
                    $existing[] = $dir;
                }
            }
            if ($existing === []) {
                if ($candidates !== []) {
                    $missing[$namespace] = end($candidates);
                }
                continue;
            }
            if (count($existing) > 1) {
                throw new \RuntimeException(
                    "❌ Module '{$moduleKey}' has " . self::RELATIVE_DIR . ' under more than one source path ('
                    . implode(', ', $existing) . '). One directory per module (ADR-039 decision 13) — a project '
                    . 'writes its migrations to ' . self::PROJECT_RELATIVE_DIR . ' (namespace '
                    . self::PROJECT_NAMESPACE . '), never under override/z77/module/.'
                );
            }
            $found[$namespace] = $existing[0];
        }

        return ['found' => $found, 'missing' => $missing];
    }

    public static function packageDirectory(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2)) . '/' . self::RELATIVE_DIR;
    }

    /**
     * Whether the module announces Doctrine entities — in its config key or in
     * an extension file (`doctrineEntitiesConfig.inc.php`). Per module, unlike
     * `ModuleManager::getDoctrineEntities()`, which is the union.
     */
    private static function declaresDoctrineEntities(ModuleManager $modules, string $moduleKey): bool
    {
        $declared = $modules->getModuleConfig($moduleKey)?->get('doctrineEntities', []);
        if (is_array($declared) && $declared !== []) {
            return true;
        }
        foreach ($modules->getConfigExtensions($moduleKey, 'doctrineEntities') as $listed) {
            if ($listed !== []) {
                return true;
            }
        }

        return false;
    }
}
