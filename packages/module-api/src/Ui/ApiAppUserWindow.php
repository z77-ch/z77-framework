<?php

namespace Z77\Module\Api\Ui;

use Z77\Core\Config\AuthRole,
    Z77\Module\Api\Entities\ApiAppUser,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Module\Api\Services\ApiAppUserDenied,
    Z77\Module\Api\Services\ApiDevices,
    Z77\Module\Api\Services\ApiDeviceScopes,
    Z77\Shared\Entities\BackendUser
;

/**
 * What the screen «App-Benutzer» shows and keeps, decided in ONE place so the list, the window and
 * the harness agree (board «App-Benutzer», approved 2026-11-12; ADR-058 §4 «Rights»):
 *
 *   - the PERSON picker of a new app user: every backend user, the free ones first; a person who
 *     already has an app user (A2) or whose roles lie above the actor's (A9) stays visible but
 *     cannot be chosen, the reason in the option;
 *   - the FUNCTION switches: every declared scope (and any held one nobody declares any more); a
 *     switch the person's role — or the actor's — does not reach is disabled with the reason
 *     «Braucht mindestens <Rolle> — <Name> ist <Rolle>». The service asks the same rule again on
 *     save ({@see \Z77\Module\Api\Services\ApiAppUsers}); a disabled switch keeps what is held
 *     ({@see keep()}), so a post can never add or drop it;
 *   - the WORDS of a refusal: the service throws English exception text, the screen puts German
 *     words on the field it concerns ({@see explain()});
 *   - a device's state: live, blocked (final, E13) or expired (idle past ApiDevices::IDLE_SECONDS).
 *
 * Presentation only — no write, no request access (Rule 4). Role labels come from the host (the
 * backend's `BackendUserController::roleLabels()`, the one German source), module-api owns none.
 */
final class ApiAppUserWindow
{
    /**
     * @param array<string, string> $roleLabels role => German label
     */
    public function __construct(private readonly ApiDeviceScopes $scopes, private readonly array $roleLabels)
    {
    }

    /** «Peter Muster (peter)», the username alone, «(gelöscht)»; a member link is not offered yet (A8). */
    public static function personName(ApiAppUser $appUser, ?BackendUser $person): string
    {
        if ($appUser->getLinkedRealm() === ApiAppUser::REALM_MEMBER) {
            return 'Mitglied ' . $appUser->getLinkedId();
        }

        return ApiDeviceAccess::ownerName($person);
    }

    /** The highest of $roles by the hierarchy, '' for none. */
    public static function highestRole(array $roles): string
    {
        $hierarchy = AuthRole::getRoleHierarchy();
        $best      = '';
        foreach ($roles as $role) {
            if (is_string($role) && ($best === '' || ($hierarchy[$role] ?? 0) > ($hierarchy[$best] ?? 0))) {
                $best = $role;
            }
        }

        return $best;
    }

    public function roleLabel(string $role): string
    {
        return $role === '' ? 'ohne Rolle' : ($this->roleLabels[$role] ?? $role);
    }

    /**
     * The person picker of a new app user. Free persons first, by name; then the ones that cannot
     * be chosen, each with its reason.
     *
     * @param iterable<BackendUser>  $users
     * @param array<string, true>    $linked backend user id => true for every id that has an app user
     * @return list<array{id: int, label: string, disabled: bool}>
     */
    public function personChoices(iterable $users, array $linked, ApiDeviceAccess $actor): array
    {
        $free  = [];
        $taken = [];
        foreach ($users as $user) {
            if (!$user instanceof BackendUser || $user->getId() === null) {
                continue;
            }
            $id    = (int) $user->getId();
            $label = ApiDeviceAccess::ownerName($user) . ' · ' . $this->roleLabel(self::highestRole($user->getRoles()));
            if (isset($linked[(string) $id])) {
                $taken[] = ['id' => $id, 'label' => $label . ' — hat schon einen App-Benutzer', 'disabled' => true];
            } elseif (!$actor->mayActFor($user)) {
                $taken[] = ['id' => $id, 'label' => $label . ' — über deiner Rolle', 'disabled' => true];
            } else {
                $free[] = ['id' => $id, 'label' => $label, 'disabled' => false];
            }
        }
        $byLabel = static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']);
        usort($free, $byLabel);
        usort($taken, $byLabel);

        return array_merge($free, $taken);
    }

    /** May this person be chosen for a NEW app user by this actor? */
    public static function choosable(?BackendUser $person, array $linked, ApiDeviceAccess $actor): bool
    {
        return $person !== null && $person->getId() !== null && !isset($linked[(string) $person->getId()]) && $actor->mayActFor($person);
    }

    /**
     * The function switches of the window.
     *
     * @param list<string>      $held       the scopes the app user holds (a new one: what was posted)
     * @param list<string>      $actorRoles the actor's roles as stored now
     * @param bool              $canEdit    the window is editable at all (actor rule, person present)
     * @return list<array{scope: string, label: string, hint: string, checked: bool, enabled: bool, reason: string}>
     */
    public function functions(array $held, string $realm, ?BackendUser $person, string $personName, array $actorRoles, bool $canEdit): array
    {
        $personRoles = array_values($person?->getRoles() ?? []);
        $switches    = [];
        foreach ($this->scopes->choices($held) as $scope => $words) {
            $min    = $words['realms'][$realm] ?? null;
            $reason = '';
            if ($min === null) {
                $reason = isset($this->scopes->all()[$scope])
                    ? 'Für ' . ($realm === ApiAppUser::REALM_MEMBER ? 'Mitglieder' : 'Backend-Benutzer') . ' nicht angeboten.'
                    : 'Von keinem Modul mehr angeboten.';
            } elseif ($person === null) {
                $reason = $canEdit ? 'Erst eine Person wählen.' : '';
            } elseif (!$this->scopes->holdable($scope, $realm, $personRoles)) {
                $reason = 'Braucht mindestens ' . $this->roleLabel($min) . ' — ' . $personName . ' ist ' . $this->roleLabel(self::highestRole($personRoles)) . '.';
            } elseif (!$this->scopes->holdable($scope, ApiAppUser::REALM_BACKEND, $actorRoles)) {
                $reason = 'Braucht mindestens ' . $this->roleLabel($min) . ' — du bist ' . $this->roleLabel(self::highestRole($actorRoles)) . '.';
            }
            $switches[] = [
                'scope'   => $scope,
                'label'   => $words['label'],
                'hint'    => $words['hint'],
                'checked' => in_array($scope, $held, true),
                'enabled' => $canEdit && $reason === '',
                'reason'  => $reason,
            ];
        }

        return $switches;
    }

    /**
     * What a save stores: the posted state of every ENABLED switch, the held state of every
     * disabled one — a forged post can neither add a scope the window did not offer nor drop one
     * it showed locked (Rule 7).
     *
     * @param mixed $posted the posted `scopes[]`
     * @param list<array{scope: string, checked: bool, enabled: bool}> $switches from {@see functions()}
     * @return list<string>
     */
    public static function keep(mixed $posted, array $switches): array
    {
        $posted = is_array($posted) ? array_values(array_filter($posted, 'is_string')) : [];
        $kept   = [];
        foreach ($switches as $switch) {
            $on = $switch['enabled'] ? in_array($switch['scope'], $posted, true) : $switch['checked'];
            if ($on) {
                $kept[] = $switch['scope'];
            }
        }

        return $kept;
    }

    /**
     * German words for a refusal of {@see \Z77\Module\Api\Services\ApiAppUsers}, and the field it
     * belongs to ('' = the window as a whole). The service's texts are English and stable; an
     * unknown one gets a general sentence (the caller logs it).
     *
     * @return array{field: string, message: string, known: bool}
     */
    public static function explain(\Throwable $e): array
    {
        $text  = $e->getMessage();
        $rules = $e instanceof ApiAppUserDenied
            ? [
                ['could not hold scope', 'scopes', 'Diese Funktion kannst du nicht vergeben — deine eigene Rolle reicht dafür nicht.'],
                ['role above the actor', '', 'Diesen App-Benutzer darfst du nicht ändern — die verknüpfte Person hat eine höhere Rolle als du.'],
                ['Only an admin', '', 'Nur ein Admin verwaltet App-Benutzer.'],
            ]
            : [
                ['Invalid app e-mail', 'email', 'Bitte eine gültige E-Mail-Adresse eingeben.'],
                ['e-mail is taken', 'email', 'Diese E-Mail hat schon ein anderer App-Benutzer.'],
                ['already has an app user', 'linked_id', 'Diese Person hat schon einen App-Benutzer.'],
                ['Unknown backend user', 'linked_id', 'Diese Person gibt es nicht (mehr).'],
                ['Only backend users', 'linked_id', 'Nur Backend-Benutzer lassen sich verknüpfen — Mitglieder folgen später.'],
                ['do not reach scope', 'scopes', 'Die Rolle der Person reicht für eine gewählte Funktion nicht.'],
                ['not offered by any module', 'scopes', 'Eine gewählte Funktion bietet kein Modul an.'],
                ['Invalid scope', 'scopes', 'Eine gewählte Funktion ist ungültig.'],
                ['cannot be reactivated', '', 'Die verknüpfte Person gibt es nicht mehr — der App-Benutzer lässt sich nicht wieder aktivieren.'],
                ['being changed by someone else', '', 'Gerade ändert jemand anders die App-Benutzer — bitte nochmals versuchen.'],
                ['Unknown app user', '', 'App-Benutzer nicht gefunden.'],
            ];
        foreach ($rules as [$needle, $field, $message]) {
            if (str_contains($text, $needle)) {
                return ['field' => $field, 'message' => $message, 'known' => true];
            }
        }

        return ['field' => '', 'message' => 'Speichern war nicht möglich — Details im Log.', 'known' => false];
    }

    /** `revoked` (blocked, final — E13), `expired` (idle past the limit), else `live`. */
    public static function deviceState(ApiDevice $device, ?int $now = null): string
    {
        if ($device->isRevoked()) {
            return 'revoked';
        }
        $used = $device->getLastUsedAt() === '' ? false : strtotime($device->getLastUsedAt());

        return $used !== false && $used + ApiDevices::IDLE_SECONDS < ($now ?? time()) ? 'expired' : 'live';
    }

    /**
     * The device's label split for the window's two columns: «Safari · iPhone» → Gerät «iPhone»,
     * Browser «Safari» (ApiDevices::label()). A label without a system stays whole.
     *
     * @return array{device: string, browser: string}
     */
    public static function deviceWords(ApiDevice $device): array
    {
        $agent = $device->getAgent() !== '' ? $device->getAgent() : $device->getLabel();
        $parts = explode(' · ', $agent, 2);

        return count($parts) === 2
            ? ['device' => $parts[1], 'browser' => $parts[0]]
            : ['device' => $device->getLabel(), 'browser' => $agent === $device->getLabel() ? '—' : $agent];
    }
}
