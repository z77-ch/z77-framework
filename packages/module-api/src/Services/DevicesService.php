<?php

namespace Z77\Module\Api\Services;

use Z77\Core\DI,
    Z77\Shared\Api\ApiRequest,
    Z77\Shared\Api\ApiResult,
    Z77\Shared\Api\ScopedApiServiceInterface,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * Built-in `/api/v1/devices` (ADR-057), the device channel's own endpoint:
 *
 *   POST devices/pair  {code}  redeem a one-time pairing code → the device
 *                              cookie (Set-Cookie) + the device's data. The
 *                              one anonymous call: the guard lets it through
 *                              without a credential (X-Z77-App + Origin still
 *                              apply); throttled here per source /64.
 *   GET  devices/me            the calling device: label, owner name, scopes —
 *                              what the app shell needs to show its tiles.
 *
 * Framework-internal, so two liberties a project service does not take: it
 * reads the client address and user agent from the Request (throttle key,
 * fallback label), and it hands the responder a `Set-Cookie` header — the
 * cookie IS this endpoint's payload.
 */
final class DevicesService implements ScopedApiServiceInterface
{
    /** Redemption attempts per source /64 and hour — a code is ~49 bits and lives 10 minutes. */
    public const PAIR_LIMIT_PER_HOUR = 10;

    public function __construct(private ?ApiDevices $devices = null)
    {
    }

    public function scopeFor(string $method, array $slugs): ?string
    {
        return match (true) {
            $slugs === ['pair'] && $method === 'post' => self::NO_SCOPE,
            $slugs === ['me'] && $method === 'get'    => self::NO_SCOPE,
            default                                   => null,
        };
    }

    public function handle(ApiRequest $request): ApiResult
    {
        return $request->slugs === ['pair'] ? $this->pair($request) : $this->me($request);
    }

    private function pair(ApiRequest $request): ApiResult
    {
        // Only the guard's anonymous door leads here; a key holder has nothing to pair.
        if ($request->principal !== null) {
            return ApiResult::error('forbidden', 403, 'Pairing is for devices without a credential.');
        }

        $http     = DI::getRequest();
        $ip       = FileThrottle::normalizeIp((string) ($http->getClientIp() ?? ''));
        $throttle = new FileThrottle(rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/api');
        if ($ip !== null && !$throttle->allow('pair:' . $ip, self::PAIR_LIMIT_PER_HOUR, 3600)) {
            return ApiResult::error('throttled', 429, 'Rate limit exceeded.', $throttle->retryAfter(3600));
        }

        $code = $request->body['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            return ApiResult::error('bad_request', 400, 'Field `code` is required.');
        }

        $paired = $this->devices()->redeemPairingCode($code, $http->getUserAgent());
        if ($paired === null) {
            return ApiResult::error('forbidden', 403, 'Pairing code invalid or expired.');
        }

        $device = $paired['device'];
        $owner  = $this->devices()->resolve($paired['cookie']);

        return ApiResult::payload(
            ['device' => [
                'id'     => (string) $device->getId(),
                'label'  => $device->getLabel(),
                'owner'  => $owner?->ownerName ?? '',
                'scopes' => $device->getScopes(),
            ]],
            null,
            ['Set-Cookie' => ApiDevices::cookieHeader($paired['cookie'], $http->isHttps())]
        );
    }

    private function me(ApiRequest $request): ApiResult
    {
        $principal = $request->device();
        if ($principal === null) {
            return ApiResult::error('forbidden', 403, 'Only a paired device has a `me`.');
        }

        $device = $this->devices()->find((int) $principal->deviceId);

        return ApiResult::payload(['device' => [
            'id'     => $principal->deviceId,
            'label'  => $device?->getLabel() ?? '',
            'owner'  => $principal->ownerName,
            'scopes' => $principal->scopes,
        ]]);
    }

    private function devices(): ApiDevices
    {
        return $this->devices ??= new ApiDevices();
    }
}
