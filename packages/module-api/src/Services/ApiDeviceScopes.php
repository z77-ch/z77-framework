<?php

namespace Z77\Module\Api\Services;

use Z77\Core\Config\AuthRole,
    Z77\Core\Services\ModuleManager,
    Z77\Module\Api\Entities\ApiAppUser
;

/**
 * The scopes (app functions) an app user can be given — collected from the module configs, the
 * declaration pattern of `apiServices`: the module that OWNS a target declares the scope its
 * {@see \Z77\Shared\Api\ScopedApiServiceInterface} asks for, with the words a screen shows and
 * the MINIMUM ROLE per realm the linked person needs to capture (ADR-058, review B7):
 *
 *   // module-receipt's receiptConfig.inc.php
 *   'apiDeviceScopes' => [
 *       'receipt.capture' => [
 *           'label'  => 'Belege erfassen',
 *           'hint'   => 'Foto und Betrag ins Belegjournal',
 *           'realms' => ['backend' => AuthRole::EDITOR],   // no `member` key = never for members
 *       ],
 *   ],
 *
 * The rule is asked when a scope is ASSIGNED and on EVERY request ({@see filter()}, from
 * ApiDevices::resolve()): rights can shrink, and a scope the person may no longer hold simply
 * stops applying. A scope without `realms` is offered to nobody (a declaration from before
 * ADR-058 stays harmless instead of breaking the guard).
 *
 * Why a declaration and not the services themselves: `scopeFor($method, $slugs)` answers a
 * question about ONE request, it cannot list what it would answer — and a scope needs a label a
 * person understands.
 *
 * A bad scope name, a label missing, a malformed `realms` entry or a scope declared by two
 * modules is a config error and throws (fail fast, like `apiServices`).
 */
final class ApiDeviceScopes
{
    /** @var array<string, array{label: string, hint: string, realms: array<string, string>}>|null scope => words, in declaration order */
    private ?array $declared = null;

    public function __construct(private readonly ModuleManager $moduleManager)
    {
    }

    /** @return array<string, array{label: string, hint: string, realms: array<string, string>}> */
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
                $scopes[$scope] = [
                    'label'  => $label,
                    'hint'   => trim((string) ($words['hint'] ?? '')),
                    'realms' => self::realms($scope, $words['realms'] ?? []),
                ];
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
     * May a person of $realm with $roles hold $scope? Declared for that realm, and the person's
     * highest role reaches the declared minimum (`AuthRole::rolesSatisfy()`). No roles = no.
     *
     * @param list<string> $roles the person's roles as STORED now
     */
    public function holdable(string $scope, string $realm, array $roles): bool
    {
        $min = $this->all()[$scope]['realms'][$realm] ?? null;

        return $min !== null && $roles !== [] && AuthRole::rolesSatisfy($roles, $min);
    }

    /**
     * The scopes of $scopes the person may hold now — the live filter of every device request.
     *
     * @param list<string> $scopes
     * @param list<string> $roles
     * @return list<string>
     */
    public function filter(array $scopes, string $realm, array $roles): array
    {
        return array_values(array_filter($scopes, fn($scope): bool => is_string($scope) && $this->holdable($scope, $realm, $roles)));
    }

    /**
     * The switches of a window: every declared scope, then those the app user holds beyond them —
     * so nothing it holds is invisible, and switching one off is possible.
     *
     * @param list<string> $held
     * @return array<string, array{label: string, hint: string, realms: array<string, string>}>
     */
    public function choices(array $held = []): array
    {
        $choices = $this->all();
        foreach ($held as $scope) {
            $choices[$scope] ??= ['label' => $scope, 'hint' => 'von keinem Modul mehr angeboten', 'realms' => []];
        }

        return $choices;
    }

    /**
     * What the server keeps of a posted switch set: only the scopes the window offered — a
     * forged body cannot add one (Rule 7).
     *
     * @param mixed        $posted the posted `scopes[]`
     * @param list<string> $held   the scopes held today
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

    /**
     * @return array<string, string> realm => minimum role
     * @throws \LogicException a realm that is not backend/member, a role the hierarchy does not know
     */
    private static function realms(string $scope, mixed $declared): array
    {
        if (!is_array($declared)) {
            throw new \LogicException("apiDeviceScopes: `realms` of '{$scope}' must be a map realm => minimum role.");
        }
        $hierarchy = AuthRole::getRoleHierarchy();
        $realms    = [];
        foreach ($declared as $realm => $role) {
            if (!in_array($realm, [ApiAppUser::REALM_BACKEND, ApiAppUser::REALM_MEMBER], true)
                || !is_string($role) || !isset($hierarchy[$role])
            ) {
                throw new \LogicException("apiDeviceScopes: '{$scope}' declares an invalid realm/role " . var_export([$realm => $role], true) . '.');
            }
            $realms[$realm] = $role;
        }

        return $realms;
    }
}
