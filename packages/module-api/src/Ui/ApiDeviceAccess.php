<?php

namespace Z77\Module\Api\Ui;

use Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Shared\Entities\BackendUser
;

/**
 * The actor rule of the app-user administration (ADR-058 §4 «Rights», review B5) — the backend's
 * existing permission model, nothing new:
 *
 *   - whoever GOVERNS the users — the role that reaches Stammdaten › Benutzer, ADMIN and up —
 *     manages app users (A9), but, as in the user list, only for a person whose roles do not
 *     lie above his own (BackendUserController «above the actor»);
 *   - the actor's roles come from the STORE, not the session (a session carries the roles it
 *     started with — the user list's rule).
 *
 * Asked by {@see \Z77\Module\Api\Services\ApiAppUsers} on every write and by the screen
 * «App-Benutzer» ({@see ApiAppUserControllerTrait}) to render a window read-only where the service
 * would refuse. The name stays from the device screen it was built for (ADR-057).
 */
final class ApiDeviceAccess
{
    /** @param list<string> $actorRoles */
    public function __construct(private readonly int $actorId, private readonly array $actorRoles)
    {
    }

    /** The signed-in backend user, roles as stored now. No backend user → nobody (id 0, no roles). */
    public static function current(): self
    {
        $user = DI::getCurrentUserService()->getBackendUser();

        return new self((int) ($user?->getId() ?? 0), array_values($user?->getRoles() ?? []));
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    /** Manages app users: the user list's role (ADMIN and up). */
    public function governs(): bool
    {
        return $this->actorId > 0 && $this->actorRoles !== [] && AuthRole::rolesSatisfy($this->actorRoles, AuthRole::ADMIN);
    }

    /** May the actor act for this person (his own account, or one not above him)? Null = the person is gone. */
    public function mayActFor(?BackendUser $owner): bool
    {
        if ($this->actorId <= 0) {
            return false;
        }
        if ($owner !== null && (int) $owner->getId() === $this->actorId) {
            return true;
        }
        if (!$this->governs()) {
            return false;
        }
        foreach ($owner?->getRoles() ?? [] as $role) {
            if (!AuthRole::rolesSatisfy($this->actorRoles, (string) $role)) {
                return false;
            }
        }

        return true;
    }

    /** «Peter Muster (peter)», or the username alone when the account has no name. */
    public static function ownerName(?BackendUser $user): string
    {
        if ($user === null) {
            return '(gelöscht)';
        }
        $name = trim($user->getName());

        return $name !== '' && $name !== $user->getUsername() ? $name . ' (' . $user->getUsername() . ')' : $user->getUsername();
    }
}
