<?php

namespace Z77\Module\Api\Services;

use Z77\Core\Services\ModuleManager;

/**
 * The scopes a device can be given in the backend screen «Geräte» — collected from the module
 * configs, the declaration pattern of `apiServices`: the module that OWNS a target declares the
 * scope its {@see \Z77\Shared\Api\ScopedApiServiceInterface} asks for, with the words the
 * pairing window shows:
 *
 *   // module-receipt's receiptConfig.inc.php
 *   'apiDeviceScopes' => [
 *       'receipt.capture' => ['label' => 'Belege erfassen', 'hint' => 'Foto und Betrag ins Belegjournal'],
 *   ],
 *
 * Why a declaration and not the services themselves: `scopeFor($method, $slugs)` answers a
 * question about ONE request, it cannot list what it would answer — and a scope needs a label a
 * person understands. A scope nobody declares is never offered; a device that still holds one
 * (its module was removed) shows it by its key and keeps it until someone switches it off.
 *
 * A bad scope name, a label missing or a scope declared by two modules is a config error and
 * throws (fail fast, like `apiServices`).
 */
final class ApiDeviceScopes
{
    /** @var array<string, array{label: string, hint: string}>|null scope => words, in declaration order */
    private ?array $declared = null;

    public function __construct(private readonly ModuleManager $moduleManager)
    {
    }

    /** @return array<string, array{label: string, hint: string}> */
    public function all(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $scopes = [];
        foreach ($this->moduleManager->getModuleKeys() as $moduleKey) {
            $declared = $this->moduleManager->getModuleConfig($moduleKey)?->get('apiDeviceScopes', []) ?? [];
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $scope => $words) {
                if (!is_string($scope) || preg_match(ApiDevices::SCOPE_PATTERN, $scope) !== 1) {
                    throw new \LogicException('apiDeviceScopes: invalid scope name ' . var_export($scope, true) . " in module '{$moduleKey}'.");
                }
                if (isset($scopes[$scope])) {
                    throw new \LogicException("apiDeviceScopes: scope '{$scope}' is declared by more than one module.");
                }
                $label = is_array($words) ? trim((string) ($words['label'] ?? '')) : '';
                if ($label === '') {
                    throw new \LogicException("apiDeviceScopes: scope '{$scope}' needs a `label`.");
                }
                $scopes[$scope] = ['label' => $label, 'hint' => trim((string) ($words['hint'] ?? ''))];
            }
        }

        return $this->declared = $scopes;
    }

    /** What a person reads for a scope: its label, else the key itself (an undeclared scope). */
    public function label(string $scope): string
    {
        return $this->all()[$scope]['label'] ?? $scope;
    }

    /**
     * The switches of a window: every declared scope, then those the device holds beyond them —
     * so nothing it holds is invisible, and switching one off is possible.
     *
     * @param list<string> $held
     * @return array<string, array{label: string, hint: string}>
     */
    public function choices(array $held = []): array
    {
        $choices = $this->all();
        foreach ($held as $scope) {
            $choices[$scope] ??= ['label' => $scope, 'hint' => 'von keinem Modul mehr angeboten'];
        }

        return $choices;
    }

    /**
     * What the server keeps of a posted switch set: only the scopes the window offered — a
     * forged body cannot add one (Rule 7).
     *
     * @param mixed        $posted the posted `scopes[]`
     * @param list<string> $held   the device's scopes today ([] when pairing)
     * @return list<string>
     */
    public function pick(mixed $posted, array $held = []): array
    {
        $offered = $this->choices($held);
        $picked  = [];
        foreach (is_array($posted) ? $posted : [] as $scope) {
            if (is_string($scope) && isset($offered[$scope])) {
                $picked[$scope] = true;
            }
        }

        return array_keys($picked);
    }
}
