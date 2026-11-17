<?php

namespace Z77\Module\Financial\Bank;

use Z77\Core\Services\ModuleManager;

/**
 * The registered {@see BankEntryOwnerInterface}s — the union of every module's config key
 * `bankEntryOwners` (a list of classes) and its extension files
 * `App/Config/bankEntryOwnersConfig.inc.php`, the `OpenWorkChecks` / `SubledgerReconciliations`
 * pattern: a module DECLARES, financial asks, nothing is scanned. A bad entry fails at
 * registration, naming its origin.
 */
final class BankEntryOwners
{
    public const CONFIG_KEY = 'bankEntryOwners';

    /** @var list<class-string<BankEntryOwnerInterface>> */
    private array $classes = [];

    /** @param list<class-string> $classes */
    public function __construct(array $classes)
    {
        $this->add($classes, self::CONFIG_KEY);
    }

    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self([]);
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
            foreach ($sources as $origin => $classes) {
                $registry->add($classes, $origin);
            }
        }

        return $registry;
    }

    /**
     * Every registered module's answer for $entries, merged — the first module that names an
     * entry wins (an entry has one origin, so two answers for one entry would be a module bug).
     *
     * @param list<array{id: int, sourceType: string, sourceRef: string}> $entries
     * @return array<int, string> journal entry id → owner key
     */
    public function ownerKeys(array $entries): array
    {
        $out = [];
        if ($entries === []) {
            return $out;
        }
        foreach ($this->classes as $class) {
            foreach ((new $class())->ownerKeys($entries) as $entryId => $ownerKey) {
                if (is_string($ownerKey) && $ownerKey !== '') {
                    $out[(int) $entryId] ??= $ownerKey;
                }
            }
        }

        return $out;
    }

    private function add(mixed $classes, string $origin): void
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . BankEntryOwnerInterface::class . ' classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, BankEntryOwnerInterface::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . BankEntryOwnerInterface::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which cannot be instantiated without arguments — it reaches its services through DI.");
            }
            if (!in_array($class, $this->classes, true)) {
                $this->classes[] = $class;
            }
        }
    }
}
