<?php

namespace Z77\Module\Api\Services;

use Z77\Core\Services\ModuleManager,
    Z77\Shared\Api\ApiServiceInterface
;

/**
 * Aggregates `apiServices` (endpoint => FQCN) across the module configs —
 * the same declaration pattern as `authBridges` / `apiKeyResolver`: the
 * framework ships the mechanism, the project declares its services in its
 * override config.
 *
 * `health` is built in and cannot be redeclared. A duplicate endpoint across
 * modules, an unknown class, or a wrong type is a config error and throws
 * (fail fast, not open).
 */
final class ServiceRegistry
{
    /** @var array<string, class-string>|null endpoint => FQCN, built once per request */
    private ?array $declared = null;

    public function __construct(private readonly ModuleManager $moduleManager)
    {
    }

    public function resolve(string $endpoint): ?ApiServiceInterface
    {
        if ($endpoint === 'health') {
            return new HealthService();
        }

        $fqcn = $this->declaredServices()[$endpoint] ?? null;
        if ($fqcn === null) {
            return null;
        }

        $service = new $fqcn();
        if (!$service instanceof ApiServiceInterface) {
            throw new \LogicException($fqcn . ' must implement ApiServiceInterface');
        }
        return $service;
    }

    /** @return array<string, class-string> */
    private function declaredServices(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $services = [];
        foreach ($this->moduleManager->getModuleKeys() as $moduleKey) {
            $declared = $this->moduleManager->getModuleConfig($moduleKey)?->get('apiServices', []) ?? [];
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $endpoint => $fqcn) {
                if ($endpoint === 'health') {
                    throw new \LogicException('apiServices: `health` is built in and cannot be redeclared.');
                }
                if (isset($services[$endpoint])) {
                    throw new \LogicException("apiServices: endpoint '{$endpoint}' is declared by more than one module.");
                }
                if (!is_string($fqcn) || !class_exists($fqcn)) {
                    throw new \LogicException("apiServices: '{$endpoint}' points at a missing class: " . var_export($fqcn, true));
                }
                $services[$endpoint] = $fqcn;
            }
        }

        return $this->declared = $services;
    }
}
