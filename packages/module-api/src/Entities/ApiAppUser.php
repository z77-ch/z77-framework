<?php

namespace Z77\Module\Api\Entities;

use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * An app user (ADR-058): an account type of its own, made only for the z77 App. It has NO
 * backend role — it may do exactly the app functions (scopes) stored on it, and only as far as
 * the linked person's roles allow at the moment of the request.
 *
 *   - linked to exactly ONE person: a backend user (`linked_realm` `backend`) or, later, a
 *     member (`member`, A8 — the model is ready, not offered yet); unique on (realm, linked id);
 *   - identified by its app e-mail (`email`, normalised lower case, unique across app users) —
 *     the sign-in is a code sent there (A4), no password;
 *   - no display name: the linked person's name is the name (Rule 2);
 *   - many devices ({@see ApiDevice::getAppUserId()}).
 *
 * Deactivating revokes every device and spends pending login codes; reactivating means a fresh
 * code sign-in (E13: a block is final for the device). Never deleted — the devices' rows and the
 * receipts captured name it.
 *
 * Written only by {@see \Z77\Module\Api\Services\ApiAppUsers} (and `last_login_at` by the
 * sign-in); no form ever maps onto this entity. Collection mode: app users are few.
 */
#[Entity('file', 'framework/api/app_users.json')]
class ApiAppUser
{
    use ArrayMappable;

    public const REALM_BACKEND = 'backend';
    public const REALM_MEMBER  = 'member';

    public const EMAIL_LENGTH = 254;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** Server-controlled — the collection store assigns it on the first save. */
    private ?int $id = null;

    /** The app e-mail, lower case — where the sign-in code goes; unique across app users. */
    private string $email = '';

    /** `backend` | `member` — the id space of {@see $linkedId} (AuthUser realms). */
    private string $linkedRealm = self::REALM_BACKEND;

    /** The linked person's id in its realm, as a string (a member id is not an int). */
    private string $linkedId = '';

    /** @var list<string> the app functions assigned (`receipt.capture`, …) */
    private array $scopes = [];

    private bool $active = true;

    private string $createdAt = '';

    /** The backend user who created it. */
    private int $createdBy = 0;

    private string $changedAt = '';

    /** The backend user who changed it last; 0 = the system (the linked person was deleted). */
    private int $changedBy = 0;

    /** The last successful code sign-in; '' = never. */
    private string $lastLoginAt = '';

    public function getId(): ?int            { return $this->id; }
    public function getEmail(): string       { return $this->email; }
    public function getLinkedRealm(): string { return $this->linkedRealm; }
    public function getLinkedId(): string    { return $this->linkedId; }
    /** @return list<string> */
    public function getScopes(): array       { return $this->scopes; }
    public function isActive(): bool         { return $this->active; }
    public function getCreatedAt(): string   { return $this->createdAt; }
    public function getCreatedBy(): int      { return $this->createdBy; }
    public function getChangedAt(): string   { return $this->changedAt; }
    public function getChangedBy(): int      { return $this->changedBy; }
    public function getLastLoginAt(): string { return $this->lastLoginAt; }

    public function setEmail(string $email): void             { $this->email = $email; }
    public function setLinkedRealm(string $realm): void       { $this->linkedRealm = $realm; }
    public function setLinkedId(string $linkedId): void       { $this->linkedId = $linkedId; }
    /** @param list<string> $scopes */
    public function setScopes(array $scopes): void            { $this->scopes = array_values($scopes); }
    public function setActive(bool $active): void             { $this->active = $active; }
    public function setCreatedAt(string $createdAt): void     { $this->createdAt = $createdAt; }
    public function setCreatedBy(int $createdBy): void        { $this->createdBy = $createdBy; }
    public function setChangedAt(string $changedAt): void     { $this->changedAt = $changedAt; }
    public function setChangedBy(int $changedBy): void        { $this->changedBy = $changedBy; }
    public function setLastLoginAt(string $lastLoginAt): void { $this->lastLoginAt = $lastLoginAt; }

    /** Trimmed and lower-cased — the one form an app e-mail is stored and compared in. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
