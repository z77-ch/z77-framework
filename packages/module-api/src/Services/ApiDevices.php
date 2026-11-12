<?php

namespace Z77\Module\Api\Services;

use Z77\Core\DI,
    Z77\Core\Services\ApiKeyGuard,
    Z77\Module\Api\Entities\ApiAppUser,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Persistence\Resolver\UnifiedEntityManager,
    Z77\Shared\Auth\ApiPrincipal,
    Z77\Shared\Auth\DeviceKeyResolverInterface
;

/**
 * The devices of the API device channel (ADR-057, ADR-058): the cookie credential, its
 * resolution, revocation. The pattern of module-member's DeviceKeys (sha256 of a 32-byte
 * secret, hash_equals, rolling validity, a cap, a user-agent label), not its class — that one
 * hangs on MemberAccount.
 *
 * A device is born from a code sign-in of an APP USER ({@see ApiAppLogin}, ADR-058 — pairing
 * is gone): {@see issue()} writes the row with `app_user_id` and answers the cookie value
 * `id.secret`; the row keeps sha256(secret).
 *
 * resolve() is the guard's {@see DeviceKeyResolverInterface}, asked on EVERY device request:
 * unknown, revoked, idle past IDLE_SECONDS, a row without an app user (ADR-057 pairing), the
 * app user inactive or gone, the linked person gone → null (401, one answer). The principal
 * carries the app user's scopes FILTERED LIVE by the linked person's roles
 * ({@see ApiDeviceScopes::filter()}). `last_used_at` is written at most once per TOUCH_SECONDS,
 * so a busy phone does not rewrite devices.json on every request.
 *
 * Declared as `apiDeviceResolver` in module-api's config; the guard creates it with `new` (no
 * arguments) — hence the DI fallbacks in the constructor.
 */
final class ApiDevices implements DeviceKeyResolverInterface
{
    /** A device unused for this long is dead (rolling validity): 180 days. */
    public const IDLE_SECONDS = 15552000;

    /** `last_used_at` is written at most this often. */
    public const TOUCH_SECONDS = 3600;

    /** Cookie lifetime: 400 days, the cap current browsers allow. */
    public const COOKIE_MAX_AGE = 34560000;

    /**
     * Live devices per app user. A device whose cookie is gone (deleted, browser reinstalled)
     * can never be recognised again; the cap revokes the one unused for longest — exactly that
     * orphan.
     */
    public const MAX_DEVICES_PER_APP_USER = 10;

    /** `receipt.capture`, `content.edit`, `mail.send` — area.verb, lower case. */
    public const SCOPE_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/';

    public const LABEL_LENGTH = 80;

    private UnifiedEntityManager $uem;
    private ?ApiDeviceScopes $scopes;
    private ?ApiAppUsers $appUsers = null;

    public function __construct(?UnifiedEntityManager $uem = null, ?ApiDeviceScopes $scopes = null)
    {
        $this->uem    = $uem ?? DI::getUnifiedEntityManager();
        $this->scopes = $scopes;
    }

    // ── sign-in ──────────────────────────────────────────────────────────────

    /**
     * A new device for an app user that has just proven its code ({@see ApiAppLogin::verify()}):
     * the row and the cookie value. Named after the browser; the app user's cap applies.
     *
     * @return array{device: ApiDevice, cookie: string}
     */
    public function issue(ApiAppUser $appUser, ?string $userAgent, ?int $now = null): array
    {
        $now    = $now ?? time();
        $secret = bin2hex(random_bytes(32));
        $agent  = self::label($userAgent);

        $device = new ApiDevice();
        $device->setAppUserId((int) $appUser->getId());
        // owner_id keeps its ADR-057 meaning: the backend user the device belongs to.
        $device->setOwnerId($appUser->getLinkedRealm() === ApiAppUser::REALM_BACKEND ? (int) $appUser->getLinkedId() : 0);
        $device->setLabel(mb_substr($agent, 0, self::LABEL_LENGTH));
        $device->setAgent(mb_substr($agent, 0, self::LABEL_LENGTH));
        $device->setScopes([]);
        $device->setKeyHash(hash('sha256', $secret));
        $device->setCreatedAt(date(DATE_ATOM, $now));
        $device->setLastUsedAt(date(DATE_ATOM, $now));
        $this->save($device);

        $this->cap((int) $appUser->getId(), $now);

        return ['device' => $device, 'cookie' => $device->getId() . '.' . $secret];
    }

    // ── the credential ───────────────────────────────────────────────────────

    /** The guard's resolver: cookie value → device principal, or null. */
    public function resolve(string $cookieValue, ?int $now = null): ?ApiPrincipal
    {
        $now    = $now ?? time();
        $device = $this->findByCookie($cookieValue, $now);
        if ($device === null) {
            return null;
        }

        $appUser = $this->appUsers()->find($device->getAppUserId());
        $person  = $appUser !== null && $appUser->isActive() ? $this->appUsers()->linkedPerson($appUser) : null;
        if ($person === null) {
            return null;
        }

        if (self::stamp($device->getLastUsedAt()) + self::TOUCH_SECONDS <= $now) {
            $device->setLastUsedAt(date(DATE_ATOM, $now));
            $this->save($device);
        }

        return ApiPrincipal::device(
            (string) $device->getId(),
            $device->getLabel(),
            (string) $appUser->getId(),
            $appUser->getLinkedRealm(),
            $appUser->getLinkedId(),
            $person['name'],
            $this->appUsers()->liveScopes($appUser, $person)
        );
    }

    /**
     * The live device a cookie value names — secret right, not revoked, not idle, signed in as
     * an app user (a row of the ADR-057 pairing has none). Null otherwise. Says nothing about
     * the app user's state: {@see resolve()} asks that.
     */
    public function findByCookie(string $cookieValue, ?int $now = null): ?ApiDevice
    {
        $now = $now ?? time();
        if (preg_match('/^(\d{1,10})\.([0-9a-f]{64})$/', $cookieValue, $m) !== 1) {
            return null;
        }

        $device = $this->find((int) $m[1]);
        if ($device === null
            || $device->isRevoked()
            || $device->getAppUserId() <= 0
            || !hash_equals($device->getKeyHash(), hash('sha256', $m[2]))
            || self::stamp($device->getLastUsedAt()) + self::IDLE_SECONDS < $now
        ) {
            return null;
        }

        return $device;
    }

    /** «Gerät sperren»: the next request of that device is a 401. True when it was live. */
    public function revoke(int $deviceId, ?int $now = null): bool
    {
        $device = $this->find($deviceId);
        if ($device === null || $device->isRevoked()) {
            return false;
        }
        $device->setRevokedAt(date(DATE_ATOM, $now ?? time()));
        $this->save($device);
        return true;
    }

    /** Every live device of an app user is revoked (deactivation, ADR-058). Returns how many. */
    public function revokeAllFor(int $appUserId, ?int $now = null): int
    {
        $count = 0;
        foreach ($this->listForAppUser($appUserId) as $device) {
            if (!$device->isRevoked()) {
                $device->setRevokedAt(date(DATE_ATOM, $now ?? time()));
                $this->save($device);
                $count++;
            }
        }

        return $count;
    }

    /**
     * The app user's devices, revoked ones included, newest use first.
     *
     * @return list<ApiDevice>
     */
    public function listForAppUser(int $appUserId): array
    {
        return array_values(array_filter(
            $this->listAll(),
            static fn(ApiDevice $d): bool => $appUserId > 0 && $d->getAppUserId() === $appUserId
        ));
    }

    /**
     * Every device of the installation, revoked ones included, newest use first — the
     * backend list of whoever governs the users.
     *
     * @return list<ApiDevice>
     */
    public function listAll(): array
    {
        $devices = $this->all();
        usort($devices, static fn(ApiDevice $a, ApiDevice $b): int
            => self::stamp($b->getLastUsedAt()) <=> self::stamp($a->getLastUsedAt()));
        return $devices;
    }

    public function find(int $deviceId): ?ApiDevice
    {
        $device = $this->uem->getRepository(ApiDevice::class)->find($deviceId);
        return $device instanceof ApiDevice ? $device : null;
    }

    /**
     * The `Set-Cookie` value carrying a device credential: HttpOnly,
     * SameSite=Strict, path `/api` — JS never sees it, only the API receives
     * it. Secure on HTTPS (the session cookie's rule; production is HTTPS-only).
     */
    public static function cookieHeader(string $value, bool $secure, ?int $now = null): string
    {
        $expires = gmdate('D, d M Y H:i:s', ($now ?? time()) + self::COOKIE_MAX_AGE) . ' GMT';

        return ApiKeyGuard::DEVICE_COOKIE . '=' . $value
            . '; Expires=' . $expires
            . '; Max-Age=' . self::COOKIE_MAX_AGE
            . '; Path=/api'
            . ($secure ? '; Secure' : '')
            . '; HttpOnly; SameSite=Strict';
    }

    /** The `Set-Cookie` value that removes the device cookie (sign-out, ADR-058). */
    public static function clearCookieHeader(bool $secure): string
    {
        return ApiKeyGuard::DEVICE_COOKIE . '='
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT'
            . '; Max-Age=0'
            . '; Path=/api'
            . ($secure ? '; Secure' : '')
            . '; HttpOnly; SameSite=Strict';
    }

    /** Browser and system for the device list — a hint, never an identity. */
    public static function label(?string $userAgent): string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return 'Device';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')                              => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera')  => 'Opera',
            str_contains($ua, 'Firefox/')                          => 'Firefox',
            str_contains($ua, 'Chrome/')                           => 'Chrome',
            str_contains($ua, 'Safari/')                           => 'Safari',
            default                                                => 'Browser',
        };

        $system = match (true) {
            str_contains($ua, 'iPhone')                                   => 'iPhone',
            str_contains($ua, 'iPad')                                     => 'iPad',
            str_contains($ua, 'Android')                                  => 'Android',
            str_contains($ua, 'Windows')                                  => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux')                                    => 'Linux',
            default                                                       => '',
        };

        return $system === '' ? $browser : $browser . ' · ' . $system;
    }

    /**
     * @param array<mixed> $scopes
     * @return list<string> unique, validated
     * @throws \InvalidArgumentException a scope that is not `area.verb`
     */
    public static function normalizeScopes(array $scopes): array
    {
        $clean = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match(self::SCOPE_PATTERN, $scope) !== 1) {
                throw new \InvalidArgumentException('Invalid scope: ' . var_export($scope, true));
            }
            $clean[$scope] = true;
        }
        return array_keys($clean);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Revokes the app user's least recently used live devices beyond the cap. */
    private function cap(int $appUserId, int $now): void
    {
        $live = array_values(array_filter(
            $this->listForAppUser($appUserId),
            static fn(ApiDevice $d): bool => !$d->isRevoked()
        ));
        foreach (array_slice($live, self::MAX_DEVICES_PER_APP_USER) as $orphan) {
            $orphan->setRevokedAt(date(DATE_ATOM, $now));
            $this->save($orphan);
        }
    }

    /** @return list<ApiDevice> */
    private function all(): array
    {
        return array_values(array_filter(
            $this->uem->getRepository(ApiDevice::class)->findAll(),
            static fn($d): bool => $d instanceof ApiDevice
        ));
    }

    private function appUsers(): ApiAppUsers
    {
        return $this->appUsers ??= new ApiAppUsers($this->uem, $this->scopes, $this);
    }

    private function save(ApiDevice $device): void
    {
        $this->uem->persist($device);
        $this->uem->flush();
    }

    private static function stamp(string $iso): int
    {
        $stamp = strtotime($iso);
        return $stamp === false ? 0 : $stamp;
    }
}
