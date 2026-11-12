<?php

namespace Z77\Shared\Auth;

/**
 * WHO called the API. Two channels share this shape (ADR-057):
 *
 *   - MACHINE (bearer key): the tenant, and optionally WHICH of the tenant's
 *     connections (api-principal handoff, 2026-09-02). A tenant can hold
 *     several connections, each delivering a different selection, each the
 *     unit of revocation — the widget established the pattern before the API.
 *   - DEVICE (HttpOnly device cookie, {@see DeviceKeyResolverInterface}): a
 *     paired phone or PC, the backend user who owns it, and the scopes the
 *     device was granted. Built by {@see device()}; its tenant is the constant
 *     {@see DEVICE_TENANT} — a device belongs to the installation, not to a
 *     tenant, and a machine service never sees one (devices only reach
 *     services that declare a scope, ScopedApiServiceInterface).
 *
 * `keyRef` is OPAQUE to the framework: the project assigns and interprets it,
 * the framework only passes it through (into ApiRequest, the throttle key,
 * and the api.log line). It MUST NOT be the key or its hash — it appears in
 * logs. A short stable slug of the connection (`[a-z0-9-]`) is the intended
 * shape. Projects resolving only a tenant return null and run unchanged.
 *
 * The device fields are DATA for the target service (who captured a receipt),
 * never an identity to act as: there is no "run as user" on the stateless path.
 */
final class ApiPrincipal
{
    /** The tenant column of every device principal (log, throttle). */
    public const DEVICE_TENANT = 'device';

    /**
     * @param list<string> $scopes device only: what the device may reach (`receipt.capture`, …)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $keyRef = null,
        public readonly ?string $deviceId = null,
        public readonly ?string $ownerId = null,
        public readonly ?string $ownerName = null,
        public readonly array $scopes = [],
    ) {
    }

    /** @param list<string> $scopes */
    public static function device(string $deviceId, string $ownerId, string $ownerName, array $scopes): self
    {
        return new self(self::DEVICE_TENANT, null, $deviceId, $ownerId, $ownerName, array_values($scopes));
    }

    public function isDevice(): bool
    {
        return $this->deviceId !== null;
    }

    /** True only for a device granted that scope — a machine principal has no scopes. */
    public function hasScope(string $scope): bool
    {
        return $this->isDevice() && in_array($scope, $this->scopes, true);
    }

    /** Log/throttle rendering: `8`, `8:widget-a`, or `device:3`. */
    public function label(): string
    {
        if ($this->isDevice()) {
            return 'device:' . $this->deviceId;
        }
        return $this->keyRef === null ? $this->tenantId : $this->tenantId . ':' . $this->keyRef;
    }
}
