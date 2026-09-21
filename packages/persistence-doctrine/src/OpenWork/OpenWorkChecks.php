<?php

namespace Z77\Persistence\Doctrine\OpenWork;

use Z77\Core\Services\ModuleManager;

/**
 * The open-work check registry (plan §2, ADR-039 decision 15) — the one
 * mechanism behind the period-close check (ADR-042 decision 11) and the
 * stocktake block (ADR-043 decision 17). It knows no module and no domain:
 * a module REGISTERS checks, a caller ASKS for one scope, every finding is
 * blocking or a warning.
 *
 * Registration is configuration, not a runtime call — the pattern of
 * `doctrineEntities`, `importEntities` and `jobs`: a module declares under
 * its config key `openWorkChecks` which classes answer which scope, and the
 * registry collects the union across all modules. Explicit, visible in one
 * file per module, and a bad entry fails at the first question, naming the
 * module — a runtime `register()` would depend on who happened to boot first.
 *
 * ```php
 * // module-debtor: debtorConfig.inc.php
 * 'openWorkChecks' => [
 *     'period-close' => [InvoicingInProgressCheck::class, UnbookedPaymentsCheck::class],
 * ],
 * // module-order: orderConfig.inc.php
 * 'openWorkChecks' => [
 *     'period-close' => [InvoiceableOrdersCheck::class],   // a warning, plan §5.3
 *     'stocktake'    => [UnbookedStockOrdersCheck::class],
 * ],
 * ```
 *
 * The SCOPE is a name the asking module owns and documents together with the
 * `$parameters` it passes (`period-close` → the period; `stocktake` → the
 * count time). A scope nobody registered for is simply «nothing open».
 *
 * Asking:
 *
 *   $open = OpenWorkChecks::fromModules(DI::getModuleManager())->ask('period-close', ['fiscalYear' => 2026, 'period' => 3]);
 *   if ($open->isBlocked()) { … }
 */
final class OpenWorkChecks
{
    public const CONFIG_KEY = 'openWorkChecks';

    /** @var array<string, list<class-string<OpenWorkCheckInterface>>> scope → check classes */
    private array $checks = [];

    /** @param array<string, list<class-string>> $checksByScope scope → check classes */
    public function __construct(array $checksByScope)
    {
        foreach ($checksByScope as $scope => $classes) {
            $this->add($scope, $classes, self::CONFIG_KEY);
        }
    }

    /**
     * The union of every module's `openWorkChecks`, deduplicated per scope; a
     * bad entry names its module (and file).
     *
     * Two additive sources per module — the pattern of `doctrineEntities`:
     *   1. the `openWorkChecks` key of the module config;
     *   2. every extension file `App/Config/openWorkChecksConfig.inc.php` of
     *      the module (`ModuleManager::getConfigExtensions()`), returning the
     *      same shape (scope => list of check classes). This is how a project
     *      adds a check of its own without copying the module config (Rule 2):
     *
     * ```php
     * // override/z77/module/order/src/App/Config/openWorkChecksConfig.inc.php
     * return [
     *     'period-close' => [ProjectOpenDeliveriesCheck::class],
     * ];
     * ```
     */
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

            foreach ($sources as $origin => $checksByScope) {
                if (!is_array($checksByScope) || ($checksByScope !== [] && array_is_list($checksByScope))) {
                    throw new \RuntimeException("❌ {$origin} must be an array of scope => [check classes].");
                }
                foreach ($checksByScope as $scope => $classes) {
                    $registry->add($scope, $classes, $origin);
                }
            }
        }

        return $registry;
    }

    /**
     * Asks every check registered for $scope. Findings come back in
     * registration order; the checks run in the caller's context — inside
     * its transaction if it opened one.
     *
     * @param array<string, mixed> $parameters what the scope documents
     */
    public function ask(string $scope, array $parameters = []): OpenWork
    {
        $findings = [];
        foreach ($this->checks[$scope] ?? [] as $class) {
            foreach ((new $class())->check($scope, $parameters) as $finding) {
                if (!$finding instanceof Finding) {
                    throw new \RuntimeException(
                        "❌ Open-work check {$class} must yield " . Finding::class . ' objects, got ' . get_debug_type($finding) . '.'
                    );
                }
                $findings[] = $finding;
            }
        }

        return new OpenWork($findings);
    }

    /**
     * Everything that would fail at `ask()` fails here instead, at
     * registration: the class must exist, implement the interface, be
     * instantiable and take no constructor arguments.
     */
    private function add(mixed $scope, mixed $classes, string $origin): void
    {
        if (!is_string($scope) || trim($scope) === '') {
            throw new \RuntimeException("❌ {$origin}: a scope must be a non-empty string, got " . var_export($scope, true) . '.');
        }
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin}: scope '{$scope}' must map to a list of check classes.");
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException(
                    "❌ {$origin}: scope '{$scope}' names a non-existent class: " . var_export($class, true)
                );
            }
            if (!is_subclass_of($class, OpenWorkCheckInterface::class)) {
                throw new \RuntimeException(
                    "❌ {$origin}: scope '{$scope}' names {$class}, which does not implement " . OpenWorkCheckInterface::class . '.'
                );
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new \RuntimeException("❌ {$origin}: scope '{$scope}' names {$class}, which cannot be instantiated (abstract or non-public constructor).");
            }
            if (($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException(
                    "❌ {$origin}: scope '{$scope}' names {$class}, whose constructor requires arguments — a check is instantiated "
                    . 'without any and reaches its services through DI.'
                );
            }
            if (!in_array($class, $this->checks[$scope] ?? [], true)) {
                $this->checks[$scope][] = $class;
            }
        }
    }
}
