<?php

namespace Z77\Module\Api\Entities;

use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A paired device of the API device channel (ADR-057): a phone or PC that
 * reaches the gateway with the HttpOnly cookie `z77_api_device` instead of a
 * bearer key. One row per pairing, owned by the backend user who paired it.
 *
 *   cookie value = id.secret        — plaintext ONLY on the device
 *   row          = sha256(secret), owner, label, scopes, timestamps
 *
 * A device is revoked, never deleted: `revoked_at` set = the next request is a
 * 401, and the row stays for the backend list and the receipt journal's
 * «captured by» (E8). Collection mode: devices per installation are few.
 *
 * Written only by {@see \Z77\Module\Api\Services\ApiDevices}; no form ever maps
 * onto this entity (the backend screen «Geräte» sets label and scopes through
 * ApiDevices::update()).
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

    /** The backend user ({@see \Z77\Shared\Entities\BackendUser}) who paired the device. */
    private int $ownerId = 0;

    /** Display name («iPhone Peter»), given at pairing or derived from the user agent. */
    private string $label = '';

    /**
     * What the browser said at pairing («Safari · iPhone», {@see \Z77\Module\Api\Services\ApiDevices::label()}) —
     * the list's «Gerät», a hint to recognise the phone, never an identity. '' on rows paired
     * before 2026-11-12's backend screen.
     */
    private string $agent = '';

    /** @var list<string> what the device may reach — narrow, independent of the owner's roles */
    private array $scopes = [];

    /** SHA-256 of the cookie secret — never the secret itself. */
    private string $keyHash = '';

    private string $createdAt = '';

    /** Written at most once per hour (ApiDevices::TOUCH_SECONDS) — not on every request. */
    private string $lastUsedAt = '';

    /** '' = live; an ISO timestamp = revoked since then. */
    private string $revokedAt = '';

    public function getId(): ?int          { return $this->id; }
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
