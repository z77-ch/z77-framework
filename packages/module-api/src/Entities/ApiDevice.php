<?php

namespace Z77\Module\Api\Entities;

use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A signed-in device of the API device channel (ADR-057, ADR-058): a phone or PC that reaches
 * the gateway with the HttpOnly cookie `z77_api_device` instead of a bearer key. One row per
 * sign-in, belonging to the APP USER it was signed in as ({@see ApiAppUser}, ADR-058).
 *
 *   cookie value = id.secret        — plaintext ONLY on the device
 *   row          = sha256(secret), app user, label, timestamps
 *
 * A device is revoked, never deleted: `revoked_at` set = the next request is a 401, and the row
 * stays for the backend list and the receipt journal's «captured by» (E8). Collection mode:
 * devices per installation are few.
 *
 * `app_user_id` is NEW with ADR-058 and the only link that counts: a row without it (paired
 * under ADR-057) resolves to nothing — `owner_id` is never reinterpreted as an app user id (the
 * old test phone of backend user 1 would otherwise become app user 1's device). `owner_id`
 * keeps its ADR-057 meaning, the backend user the device belongs to (the linked backend user
 * of the app user; 0 for a member), and `scopes` is no longer read — the app user's apply.
 *
 * Written only by {@see \Z77\Module\Api\Services\ApiDevices}; no form ever maps onto this
 * entity.
 */
#[Entity('file', 'framework/api/devices.json')]
class ApiDevice
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** Server-controlled — the collection store assigns it on the first save. */
    private ?int $id = null;

    /**
     * The app user ({@see ApiAppUser}) the device is signed in as — 0 on a row of the ADR-057
     * pairing, which therefore resolves to nothing (ADR-058: no upgrade step).
     */
    private int $appUserId = 0;

    /** The backend user ({@see \Z77\Shared\Entities\BackendUser}) the device belongs to; 0 for a member's. */
    private int $ownerId = 0;

    /** Display name («Safari · iPhone»), derived from the user agent at sign-in. */
    private string $label = '';

    /**
     * What the browser said at sign-in («Safari · iPhone», {@see \Z77\Module\Api\Services\ApiDevices::label()}) —
     * the list's «Gerät», a hint to recognise the phone, never an identity. '' on rows paired
     * before 2026-11-12's backend screen.
     */
    private string $agent = '';

    /** @var list<string> ADR-057 only — no longer read (ADR-058: the app user's scopes apply); [] on new rows */
    private array $scopes = [];

    /** SHA-256 of the cookie secret — never the secret itself. */
    private string $keyHash = '';

    private string $createdAt = '';

    /** Written at most once per hour (ApiDevices::TOUCH_SECONDS) — not on every request. */
    private string $lastUsedAt = '';

    /** '' = live; an ISO timestamp = revoked since then. */
    private string $revokedAt = '';

    public function getId(): ?int          { return $this->id; }
    public function getAppUserId(): int    { return $this->appUserId; }
    public function getOwnerId(): int      { return $this->ownerId; }
    public function getLabel(): string     { return $this->label; }
    public function getAgent(): string     { return $this->agent; }
    /** @return list<string> */
    public function getScopes(): array     { return $this->scopes; }
    public function getKeyHash(): string   { return $this->keyHash; }
    public function getCreatedAt(): string { return $this->createdAt; }
    public function getLastUsedAt(): string { return $this->lastUsedAt; }
    public function getRevokedAt(): string { return $this->revokedAt; }
    public function isRevoked(): bool      { return $this->revokedAt !== ''; }

    public function setAppUserId(int $appUserId): void      { $this->appUserId = $appUserId; }
    public function setOwnerId(int $ownerId): void          { $this->ownerId = $ownerId; }
    public function setLabel(string $label): void           { $this->label = $label; }
    public function setAgent(string $agent): void           { $this->agent = $agent; }
    /** @param list<string> $scopes */
    public function setScopes(array $scopes): void          { $this->scopes = array_values($scopes); }
    public function setKeyHash(string $keyHash): void       { $this->keyHash = $keyHash; }
    public function setCreatedAt(string $createdAt): void   { $this->createdAt = $createdAt; }
    public function setLastUsedAt(string $lastUsedAt): void { $this->lastUsedAt = $lastUsedAt; }
    public function setRevokedAt(string $revokedAt): void   { $this->revokedAt = $revokedAt; }
}
