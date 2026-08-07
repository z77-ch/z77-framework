<?php
namespace Z77\Shared\Auth;

use Z77\Core\Config\AuthRole;

/**
 * The session identity the ACL reasons about — regardless of which door it
 * came through. Two realms share this shape:
 *
 *   REALM_BACKEND — BackendUser (password login, backendUsers.json), int id.
 *   REALM_MEMBER  — MemberAccount (module-member, passwordless), string id.
 *
 * The realm keeps the two id spaces apart: consumers that map the id back to
 * an entity (CurrentUserService, DMS Principal) MUST check the realm first —
 * a member account id must never be looked up in the backend user store or
 * matched against backend-user ACEs.
 */
final class AuthUser
{
    public const REALM_BACKEND = 'backend';
    public const REALM_MEMBER  = 'member';

    private int|string $id;
    private string $userName;
    private array $roles;
    private string $realm;

    public function __construct(array $data = [])
    {
        $this->id       = $data['id']        ?? 0;
        $this->userName = $data['user_name'] ?? 'guest';
        $this->roles    = $data['roles']     ?? [AuthRole::GUEST];
        $this->realm    = $data['realm']     ?? self::REALM_BACKEND;
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getRealm(): string
    {
        return $this->realm;
    }

    public function isBackendRealm(): bool
    {
        return $this->realm === self::REALM_BACKEND;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasAtLeast(string $minRole): bool
    {
        return AuthRole::rolesSatisfy($this->roles, $minRole);
    }

    /**
     * Highest role of this user according to AuthRole hierarchy.
     * Returns the AuthRole constant string (e.g. 'admin', 'member').
     * Falls back to GUEST if no known role is assigned.
     */
    public function getHighestRole(): string
    {
        $hierarchy = AuthRole::getRoleHierarchy();
        $bestRole  = AuthRole::GUEST;
        $bestLevel = -1;
        foreach ($this->roles as $role) {
            $level = $hierarchy[$role] ?? -1;
            if ($level > $bestLevel) {
                $bestLevel = $level;
                $bestRole  = $role;
            }
        }
        return $bestRole;
    }

    public function isLoggedIn(): bool
    {
        return $this->id !== 0 && $this->id !== '';
    }
}
