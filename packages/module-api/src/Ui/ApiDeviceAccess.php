<?php

namespace Z77\Module\Api\Ui;

use Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Shared\Entities\BackendUser
;

/**
 * Who may do what in Stammdaten › System › Geräte — the backend's existing permission model,
 * nothing new (api.md «backend screen»):
 *
 *   - every backend user (role EDITOR and up — the screen's controller role) pairs, renames and
 *     blocks HIS OWN devices: like the second factor of his own account (login.md
 *     BACKEND-2FA-001), a device is a credential of his;
 *   - whoever GOVERNS the users — the role that reaches Stammdaten › Benutzer, ADMIN and up —
 *     sees every device and acts for another owner, but, as in the user list, only for an owner
 *     whose roles do not lie above his own (BackendUserController «above the actor»);
 *   - the actor's roles come from the STORE, not the session (a session carries the roles it
 *     started with — the user list's rule).
 *
 * The scopes a device gets are independent of its owner's roles (ADR-057): a device never
 * acts as its owner, it only names him.
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

    /** Sees every device and may pair for others: the user list's role. */
    public function governs(): bool
    {
        return $this->actorId > 0 && $this->actorRoles !== [] && AuthRole::rolesSatisfy($this->actorRoles, AuthRole::ADMIN);
    }

    /** May this owner's devices be paired, renamed, blocked by the actor? Null = the owner is gone. */
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

    /** May the actor SEE this device (the list, the window)? Own ones always, all for a governor. */
    public function sees(ApiDevice $device): bool
    {
        return $this->governs() || ($this->actorId > 0 && $device->getOwnerId() === $this->actorId);
    }

    /**
     * The owners the pairing window offers, the actor first: himself only, or — governing —
     * every user he may act for.
     *
     * @param iterable<BackendUser> $users
     * @return array<int, string> id => «Name (username)»
     */
    public function ownerChoices(iterable $users): array
    {
        $own    = [];
        $others = [];
        foreach ($users as $user) {
            if (!$user instanceof BackendUser || $user->getId() === null) {
                continue;
            }
            $id = (int) $user->getId();
            if ($id === $this->actorId) {
                $own[$id] = self::ownerName($user);
            } elseif ($this->governs() && $this->mayActFor($user)) {
                $others[$id] = self::ownerName($user);
            }
        }
        asort($others, SORT_NATURAL | SORT_FLAG_CASE);

        return $own + $others;
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
