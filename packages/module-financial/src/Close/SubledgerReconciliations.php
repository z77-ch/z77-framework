<?php

namespace Z77\Module\Financial\Close;

use Z77\Core\Services\ModuleManager;

/**
 * The registered {@see SubledgerReconciliationInterface}s — the union of every module's
 * config key `subledgerReconciliations` (a list of classes) and its extension files
 * `App/Config/subledgerReconciliationsConfig.inc.php`, the `OpenWorkChecks` pattern: a
 * module DECLARES, financial asks, nothing is scanned.
 *
 * ```php
 * // module-debtor: debtorConfig.inc.php
 * 'subledgerReconciliations' => [ReceivablesReconciliation::class],
 * ```
 *
 * A bad entry fails at registration, naming its origin.
 */
final class SubledgerReconciliations
{
    public const CONFIG_KEY = 'subledgerReconciliations';

    /** @var list<class-string<SubledgerReconciliationInterface>> */
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

    /** @return list<SubledgerReconciliationInterface> a fresh instance of each, in registration order */
    public function all(): array
    {
        return array_map(static fn(string $class) => new $class(), $this->classes);
    }

    private function add(mixed $classes, string $origin): void
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . SubledgerReconciliationInterface::class . ' classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, SubledgerReconciliationInterface::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . SubledgerReconciliationInterface::class . '.');
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
