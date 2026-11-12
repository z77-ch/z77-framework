<?php

namespace Z77\Module\Api\Services;

use Z77\Core\DI,
    Z77\Core\Services\ApiKeyGuard,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Persistence\Resolver\UnifiedEntityManager,
    Z77\Shared\Auth\ApiPrincipal,
    Z77\Shared\Auth\DeviceKeyResolverInterface,
    Z77\Shared\Entities\BackendUser
;

/**
 * The devices of the API device channel (ADR-057): pairing, the cookie
 * credential, revocation. The pattern of module-member's DeviceKeys (sha256 of
 * a 32-byte secret, hash_equals, rolling validity, a cap, a user-agent label),
 * not its class — that one hangs on MemberAccount.
 *
 * Pairing, in two steps:
 *
 *   1. backend (session, the owner signed in): createPairingCode(owner, label,
 *      scopes) → a one-time code, shown as text and QR. Stored HASHED under
 *      var/lib/api/pairing, valid PAIRING_TTL_SECONDS — disposable by
 *      definition: wiping var/lib only means «create a new code».
 *   2. device (stateless, `POST /api/v1/devices/pair`, DevicesService):
 *      redeemPairingCode(code) → the device row + the cookie value. A code
 *      works once: it is claimed by an atomic rename before it is read.
 *
 * The cookie value is `id.secret`; the row keeps sha256(secret). resolve() is
 * the guard's {@see DeviceKeyResolverInterface}: unknown, revoked, idle past
 * IDLE_SECONDS or owner gone → null (401, one answer). `last_used_at` is
 * written at most once per TOUCH_SECONDS, so a busy phone does not rewrite
 * devices.json on every request.
 *
 * Declared as `apiDeviceResolver` in module-api's config; the guard creates it
 * with `new` (no arguments) — hence the DI fallbacks in the constructor.
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
     * Live devices per owner. A device whose cookie is gone (deleted, browser
     * reinstalled) can never be recognised again; the cap revokes the one
     * unused for longest — exactly that orphan.
     */
    public const MAX_DEVICES_PER_OWNER = 10;

    public const PAIRING_TTL_SECONDS = 600;

    /** `receipt.capture`, `content.edit`, `mail.send` — area.verb, lower case. */
    public const SCOPE_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/';

    public const LABEL_LENGTH = 80;

    /** No 0/O, 1/I/L: the code is also typed by hand. 31 symbols × 10 ≈ 49 bits. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH   = 10;

    private UnifiedEntityManager $uem;
    private string $pairingDir;

    public function __construct(?UnifiedEntityManager $uem = null, ?string $pairingDir = null)
    {
        $this->uem        = $uem ?? DI::getUnifiedEntityManager();
        $this->pairingDir = $pairingDir
            ?? rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/api/pairing';
    }

    // ── pairing ──────────────────────────────────────────────────────────────

    /**
     * A one-time pairing code for a device of $ownerId (`ABCDE-FGHJK`). Shown
     * once to the owner; only its hash is stored.
     *
     * @param list<string> $scopes what the device may reach — narrow (ADR-057)
     * @throws \InvalidArgumentException unknown owner, bad scope name
     */
    public function createPairingCode(int $ownerId, string $label, array $scopes, ?int $now = null): string
    {
        $now = $now ?? time();
        if ($this->owner($ownerId) === null) {
            throw new \InvalidArgumentException("Unknown backend user {$ownerId}.");
        }
        $scopes = self::normalizeScopes($scopes);

        $this->ensurePairingDir();
        $this->sweepPairingCodes($now);

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        $record = json_encode([
            'owner_id'   => $ownerId,
            'label'      => mb_substr(trim($label), 0, self::LABEL_LENGTH),
            'scopes'     => $scopes,
            'expires_at' => $now + self::PAIRING_TTL_SECONDS,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $file = $this->pairingFile($code);
        $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $record) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('ApiDevices: could not store the pairing code.');
        }

        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /**
     * Redeems a pairing code: a new device row and its cookie value, or null
     * (unknown, used, expired — one answer). Spaces, dashes and case are
     * forgiven; the code dies on the first attempt that names it.
     *
     * @return array{device: ApiDevice, cookie: string}|null
     */
    public function redeemPairingCode(string $code, ?string $userAgent = null, ?int $now = null): ?array
    {
        $now  = $now ?? time();
        $code = self::plainCode($code);
        if ($code === null) {
            return null;
        }

        // Claim first, read after: of two parallel redemptions exactly one rename wins.
        $file    = $this->pairingFile($code);
        $claimed = $file . '.claimed-' . bin2hex(random_bytes(4));
        if (!is_file($file) || !@rename($file, $claimed)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($claimed), true);
        @unlink($claimed);

        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < $now) {
            return null;
        }
        $ownerId = (int) ($record['owner_id'] ?? 0);
        if ($this->owner($ownerId) === null) {
            return null;
        }

        $label = trim((string) ($record['label'] ?? ''));
        $agent = self::label($userAgent);

        $paired = $this->issue(
            $ownerId,
            $label !== '' ? $label : $agent,
            $agent,
            self::normalizeScopes((array) ($record['scopes'] ?? [])),
            $now
        );
        $this->markPaired($file, (int) $paired['device']->getId());

        return $paired;
    }

    /**
     * Where a code stands, for the backend window that shows it (it asks every few seconds):
     *
     *   - `waiting` — stored and not expired: nobody has redeemed it yet;
     *   - `paired`  — redeemed; `deviceId` names the new device (the marker lives as long as
     *                 an unredeemed code would, then the sweep takes it);
     *   - `gone`    — expired, withdrawn, swept — or being redeemed in this very moment (the
     *                 claim has renamed the file, the marker is not written yet). The caller
     *                 knows when it created the code; before that time is up it asks again.
     *
     * @return array{state: 'waiting'|'paired'|'gone', deviceId: ?int}
     */
    public function pairingState(string $code, ?int $now = null): array
    {
        $now  = $now ?? time();
        $code = self::plainCode($code);
        if ($code === null) {
            return ['state' => 'gone', 'deviceId' => null];
        }

        $file   = $this->pairingFile($code);
        $marker = is_file(self::markerFile($file)) ? (string) file_get_contents(self::markerFile($file)) : '';
        if (preg_match('/^\d{1,10}$/', $marker) === 1) {
            return ['state' => 'paired', 'deviceId' => (int) $marker];
        }

        $record = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($record) && (int) ($record['expires_at'] ?? 0) >= $now) {
            return ['state' => 'waiting', 'deviceId' => null];
        }

        return ['state' => 'gone', 'deviceId' => null];
    }

    /**
     * «Neuer Code» in the backend: the code shown before dies at once instead of living out its
     * ten minutes. True when an unredeemed code was withdrawn.
     */
    public function cancelPairingCode(string $code): bool
    {
        $code = self::plainCode($code);
        if ($code === null) {
            return false;
        }
        $file = $this->pairingFile($code);

        return is_file($file) && @unlink($file);
    }

    // ── the credential ───────────────────────────────────────────────────────

    /** The guard's resolver: cookie value → device principal, or null. */
    public function resolve(string $cookieValue, ?int $now = null): ?ApiPrincipal
    {
        $now = $now ?? time();
        if (preg_match('/^(\d{1,10})\.([0-9a-f]{64})$/', $cookieValue, $m) !== 1) {
            return null;
        }

        $device = $this->find((int) $m[1]);
        if ($device === null
            || $device->isRevoked()
            || !hash_equals($device->getKeyHash(), hash('sha256', $m[2]))
            || self::stamp($device->getLastUsedAt()) + self::IDLE_SECONDS < $now
        ) {
            return null;
        }

        $owner = $this->owner($device->getOwnerId());
        if ($owner === null) {
            return null;
        }

        if (self::stamp($device->getLastUsedAt()) + self::TOUCH_SECONDS <= $now) {
            $device->setLastUsedAt(date(DATE_ATOM, $now));
            $this->save($device);
        }

        return ApiPrincipal::device(
            (string) $device->getId(),
            (string) $owner->getId(),
            $owner->getName() !== '' ? $owner->getName() : $owner->getUsername(),
            $device->getScopes(),
            $device->getLabel()
        );
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

    /**
     * The backend's device window: name and scopes of a LIVE device. A revoked one stays as it
     * was (E13 — blocking is final, a new pairing is the way back); an empty name falls back to
     * what the browser said at pairing.
     *
     * @param list<string> $scopes
     * @return ApiDevice|null null: unknown or revoked
     * @throws \InvalidArgumentException bad scope name
     */
    public function update(int $deviceId, string $label, array $scopes): ?ApiDevice
    {
        $scopes = self::normalizeScopes($scopes);
        $device = $this->find($deviceId);
        if ($device === null || $device->isRevoked()) {
            return null;
        }

        $label = mb_substr(trim($label), 0, self::LABEL_LENGTH);
        $device->setLabel($label !== '' ? $label : ($device->getAgent() !== '' ? $device->getAgent() : 'Device'));
        $device->setScopes($scopes);
        $this->save($device);

        return $device;
    }

    /**
     * The owner's devices, revoked ones included, newest use first.
     *
     * @return list<ApiDevice>
     */
    public function listFor(int $ownerId): array
    {
        return array_values(array_filter(
            $this->listAll(),
            static fn(ApiDevice $d): bool => $d->getOwnerId() === $ownerId
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

    /**
     * @param list<string> $scopes
     * @return array{device: ApiDevice, cookie: string}
     */
    private function issue(int $ownerId, string $label, string $agent, array $scopes, int $now): array
    {
        $secret = bin2hex(random_bytes(32));

        $device = new ApiDevice();
        $device->setOwnerId($ownerId);
        $device->setLabel(mb_substr($label, 0, self::LABEL_LENGTH));
        $device->setAgent(mb_substr($agent, 0, self::LABEL_LENGTH));
        $device->setScopes($scopes);
        $device->setKeyHash(hash('sha256', $secret));
        $device->setCreatedAt(date(DATE_ATOM, $now));
        $device->setLastUsedAt(date(DATE_ATOM, $now));
        $this->save($device);

        $this->cap($ownerId, $now);

        return ['device' => $device, 'cookie' => $device->getId() . '.' . $secret];
    }

    /** Revokes the owner's least recently used live devices beyond the cap. */
    private function cap(int $ownerId, int $now): void
    {
        $live = array_values(array_filter(
            $this->listFor($ownerId),
            static fn(ApiDevice $d): bool => !$d->isRevoked()
        ));
        foreach (array_slice($live, self::MAX_DEVICES_PER_OWNER) as $orphan) {
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

    private function owner(int $ownerId): ?BackendUser
    {
        if ($ownerId <= 0) {
            return null;
        }
        $user = $this->uem->getRepository(BackendUser::class)->find($ownerId);
        return $user instanceof BackendUser ? $user : null;
    }

    private function save(ApiDevice $device): void
    {
        $this->uem->persist($device);
        $this->uem->flush();
    }

    private function pairingFile(string $code): string
    {
        return $this->pairingDir . '/' . hash('sha256', $code) . '.json';
    }

    /** `<hash>.paired` beside the code's file: the device id, for {@see pairingState()}. */
    private static function markerFile(string $pairingFile): string
    {
        return substr($pairingFile, 0, -strlen('.json')) . '.paired';
    }

    /** Not a `.json`, so the sweep takes it once it is older than a code may live. */
    private function markPaired(string $pairingFile, int $deviceId): void
    {
        $marker = self::markerFile($pairingFile);
        $tmp    = $marker . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, (string) $deviceId) === false || !@rename($tmp, $marker)) {
            @unlink($tmp);   // the window then waits out the code's time — the device is paired regardless
        }
    }

    /** The ten code symbols, case, spaces and dashes forgiven; null when it cannot be a code. */
    private static function plainCode(string $code): ?string
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '';

        return strlen($code) === self::CODE_LENGTH ? $code : null;
    }

    private function ensurePairingDir(): void
    {
        if (!is_dir($this->pairingDir) && !mkdir($this->pairingDir, 0755, true) && !is_dir($this->pairingDir)) {
            throw new \RuntimeException("ApiDevices: could not create {$this->pairingDir}");
        }
    }

    /** Drops expired codes nobody redeemed — and leftovers of an interrupted claim. */
    private function sweepPairingCodes(int $now): void
    {
        foreach (glob($this->pairingDir . '/*') ?: [] as $file) {
            if (!str_ends_with($file, '.json')) {
                if (filemtime($file) < $now - self::PAIRING_TTL_SECONDS) {
                    @unlink($file);
                }
                continue;
            }
            $record = json_decode((string) @file_get_contents($file), true);
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < $now) {
                @unlink($file);
            }
        }
    }

    private static function stamp(string $iso): int
    {
        $stamp = strtotime($iso);
        return $stamp === false ? 0 : $stamp;
    }
}
