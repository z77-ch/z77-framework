<?php

namespace Z77\Module\Api\Services;

use Z77\Core\DI,
    Z77\Core\Services\ApiKeyGuard,
    Z77\Shared\Api\ApiRequest,
    Z77\Shared\Api\ApiResult,
    Z77\Shared\Api\ScopedApiServiceInterface,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * Built-in `/api/v1/devices` (ADR-057, ADR-058), the device channel's own endpoint:
 *
 *   POST devices/login   {email}  anonymous: always 202 + the request nonce cookie
 *                                 `z77_api_login`; a code goes to the address only when it
 *                                 belongs to a usable app user ({@see ApiAppLogin::request()})
 *   POST devices/verify  {code}   anonymous + the nonce cookie: the device cookie
 *                                 (Set-Cookie) + the device's data, or one generic 403
 *   GET  devices/me               a device: label, owner name, app user id, scopes (live)
 *
 * verify and me name the app user (`appUser`, its id): the app stamps every waiting capture with
 * it and sends only those of the app user signed in now (security review S1).
 *   POST devices/logout           a device: revokes THIS device and clears its cookie
 *
 * login and verify are the guard's anonymous door ({@see \Z77\Core\Services\ApiKeyGuard::ANONYMOUS_SLUGS}):
 * they stay anonymous on purpose — a request with a principal passes the IdempotencyStore,
 * which stores the answer's headers, and verify's `Set-Cookie` carries the plaintext secret.
 *
 * Framework-internal, so liberties a project service does not take: it reads the client
 * address, user agent and cookies from the Request (throttle key, device label, the nonce, the
 * device a re-login replaces), and it hands the responder a `Set-Cookie` header — the cookie
 * IS this endpoint's payload.
 */
final class DevicesService implements ScopedApiServiceInterface
{
    /** The one failure text of verify — the app says it in German («Code falsch oder abgelaufen …»). */
    public const VERIFY_FAILED = 'Code invalid or expired.';

    public function __construct(private ?ApiAppLogin $login = null, private ?ApiDevices $devices = null)
    {
    }

    public function scopeFor(string $method, array $slugs): ?string
    {
        return match (true) {
            $slugs === ['login'] && $method === 'post'  => self::NO_SCOPE,
            $slugs === ['verify'] && $method === 'post' => self::NO_SCOPE,
            $slugs === ['me'] && $method === 'get'      => self::NO_SCOPE,
            $slugs === ['logout'] && $method === 'post' => self::NO_SCOPE,
            default                                     => null,
        };
    }

    public function handle(ApiRequest $request): ApiResult
    {
        return match ($request->slugs) {
            ['login']  => $this->login($request),
            ['verify'] => $this->verify($request),
            ['logout'] => $this->logout($request),
            default    => $this->me($request),
        };
    }

    /**
     * Only string and length are checked here: what is not an app user's address is nobody's
     * inside {@see ApiAppLogin::request()} — the same 202, never counted, never mailed (review M1).
     */
    private function login(ApiRequest $request): ApiResult
    {
        // Only the guard's anonymous door leads here; a key holder has nothing to sign in.
        if ($request->principal !== null) {
            return ApiResult::error('forbidden', 403, 'Sign-in is for devices without a credential.');
        }
        $email = $request->body['email'] ?? null;
        if (!is_string($email) || trim($email) === '' || strlen($email) > 320) {
            return ApiResult::error('bad_request', 400, 'Field `email` is required.');
        }

        $http   = DI::getRequest();
        $result = $this->appLogin()->request($email, self::net($http->getClientIp()), $http->getUserAgent());
        if ($result['status'] === 429) {
            return ApiResult::error('throttled', 429, 'Rate limit exceeded.', $result['retryAfter']);
        }

        return ApiResult::accepted(
            ['status' => 'accepted'],
            ['Set-Cookie' => ApiAppLogin::nonceCookieHeader($result['nonce'], $http->isHttps())]
        );
    }

    private function verify(ApiRequest $request): ApiResult
    {
        if ($request->principal !== null) {
            return ApiResult::error('forbidden', 403, 'Sign-in is for devices without a credential.');
        }
        $code = $request->body['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            return ApiResult::error('bad_request', 400, 'Field `code` is required.');
        }

        $http   = DI::getRequest();
        $result = $this->appLogin()->verify(
            (string) ($http->getCookie(ApiAppLogin::NONCE_COOKIE) ?? ''),
            mb_substr($code, 0, 32),
            self::net($http->getClientIp()),
            $http->getCookie(ApiKeyGuard::DEVICE_COOKIE),
            $http->getUserAgent()
        );

        return match ($result['status']) {
            'throttled' => ApiResult::error('throttled', 429, 'Rate limit exceeded.', $result['retryAfter']),
            'failed'    => ApiResult::error('forbidden', 403, self::VERIFY_FAILED),
            // The nonce cookie is not cleared here: ApiResult carries one value per header name
            // and JsonResponse::send() replaces a header of the same name, so a second Set-Cookie
            // would need an envelope change. The record behind it is spent; the cookie is inert
            // and expires within LoginCodeStore::TTL_SECONDS.
            default     => ApiResult::payload(
                ['device' => [
                    'id'      => (string) $result['device']->getId(),
                    'label'   => $result['device']->getLabel(),
                    'owner'   => $result['ownerName'],
                    'appUser' => (string) $result['appUser']->getId(),
                    'scopes'  => $result['scopes'],
                ]],
                null,
                ['Set-Cookie' => ApiDevices::cookieHeader($result['cookie'], $http->isHttps())]
            ),
        };
    }

    private function me(ApiRequest $request): ApiResult
    {
        $principal = $request->device();
        if ($principal === null) {
            return ApiResult::error('forbidden', 403, 'Only a signed-in device has a `me`.');
        }

        return ApiResult::payload(['device' => [
            'id'      => $principal->deviceId,
            'label'   => (string) $principal->deviceLabel,
            'owner'   => $principal->ownerName,
            'appUser' => (string) $principal->appUserId,
            'scopes'  => $principal->scopes,
        ]]);
    }

    /** Sign-out: this device is revoked — a new sign-in is a fresh code — and its cookie cleared. */
    private function logout(ApiRequest $request): ApiResult
    {
        $principal = $request->device();
        if ($principal === null) {
            return ApiResult::error('forbidden', 403, 'Only a signed-in device can sign out.');
        }
        $this->devices()->revoke((int) $principal->deviceId);

        return ApiResult::payload(
            ['device' => null],
            null,
            ['Set-Cookie' => ApiDevices::clearCookieHeader(DI::getRequest()->isHttps())]
        );
    }

    /** The source /64 of a request; null when there is no usable address (CLI). */
    private static function net(?string $clientIp): ?string
    {
        return FileThrottle::normalizeIp((string) ($clientIp ?? ''));
    }

    private function appLogin(): ApiAppLogin
    {
        return $this->login ??= ApiAppLogin::create();
    }

    private function devices(): ApiDevices
    {
        return $this->devices ??= new ApiDevices();
    }
}
