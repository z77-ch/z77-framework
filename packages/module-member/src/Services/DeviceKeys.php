<?php

namespace Z77\Module\Member\Services;

use Z77\Module\Member\Entities\MemberAccount;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «Angemeldet bleiben» (B8 stage C): a long-lived, revocable device key that
 * restores the member session without a new magic link.
 *
 *   cookie value = accountId.keyId.secret   — plaintext ONLY on the device
 *   account      = id, sha256(secret), label, created/lastUsed/validUntil
 *
 * The key is issued only at the end of a complete login (link, and the app
 * code when 2FA is on), so restoring it is not a second factor bypass — it is
 * the device remembering an authentication that already happened. Every use
 * rolls the key another 90 days forward (spec default); expired entries are
 * dropped whenever the account is touched.
 *
 * Revocation is the whole point: a single device from the profile list, all
 * of them with «alle Geräte abmelden», and this device on logout.
 */
final class DeviceKeys
{
    public const TTL_SECONDS = 7776000; // spec default: 90 days, rolling

    public function __construct(
        private MemberAccounts $accounts,
        private DeviceCookie $cookie,
    ) {
    }

    public static function create(): self
    {
        return new self(
            new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']))),
            new DeviceCookie(),
        );
    }

    /** Issues a key for THIS device: hash to the account, plaintext to the cookie. */
    public function issueFor(MemberAccount $account, ?string $userAgent = null, ?int $now = null): void
    {
        $now    = $now ?? time();
        $keyId  = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(32));

        $keys   = $this->live($account, $now);
        $keys[] = [
            'id'           => $keyId,
            'key_hash'     => hash('sha256', $secret),
            'label'        => self::label($userAgent ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            'created_at'   => date(DATE_ATOM, $now),
            'last_used_at' => date(DATE_ATOM, $now),
            'valid_until'  => date(DATE_ATOM, $now + self::TTL_SECONDS),
        ];

        $account->setDeviceKeys($keys);
        $this->accounts->save($account);

        $this->cookie->write($account->getId() . '.' . $keyId . '.' . $secret, self::TTL_SECONDS, $now);
    }

    /**
     * The account this device may resume as, or null — no cookie, unknown or
     * revoked key, expired key, account gone. A hit rolls the key (lastUsedAt
     * and validUntil move forward) and refreshes the cookie's own expiry.
     * Every miss clears the cookie: a dead key is not worth sending again.
     */
    public function restore(?int $now = null): ?MemberAccount
    {
        $now   = $now ?? time();
        $parts = self::parse($this->cookie->read());
        if ($parts === null) {
            return null;
        }
        [$accountId, $keyId, $secret] = $parts;

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            $this->cookie->clear();

            return null;
        }

        $keys  = $this->live($account, $now);
        $index = $this->indexOf($keys, $keyId, $secret);
        if ($index === null) {
            $this->cookie->clear();

            return null;
        }

        $keys[$index]['last_used_at'] = date(DATE_ATOM, $now);
        $keys[$index]['valid_until']  = date(DATE_ATOM, $now + self::TTL_SECONDS);
        $account->setDeviceKeys($keys);
        $this->accounts->save($account);

        $this->cookie->write($accountId . '.' . $keyId . '.' . $secret, self::TTL_SECONDS, $now);

        return $account;
    }

    /** Logout on this device: its key dies at the account, the cookie goes. */
    public function forgetCurrent(?int $now = null): void
    {
        $now   = $now ?? time();
        $parts = self::parse($this->cookie->read());
        $this->cookie->clear();
        if ($parts === null) {
            return;
        }
        [$accountId, $keyId, $secret] = $parts;

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return;
        }

        $keys  = $this->live($account, $now);
        $index = $this->indexOf($keys, $keyId, $secret);
        if ($index === null) {
            return;
        }

        unset($keys[$index]);
        $account->setDeviceKeys(array_values($keys));
        $this->accounts->save($account);
    }

    /** One device from the profile list. True when it existed. */
    public function revoke(MemberAccount $account, string $keyId, ?int $now = null): bool
    {
        $keys  = $this->live($account, $now ?? time());
        $found = false;

        foreach ($keys as $i => $key) {
            if (($key['id'] ?? '') === $keyId) {
                unset($keys[$i]);
                $found = true;
            }
        }

        $account->setDeviceKeys(array_values($keys));
        $this->accounts->save($account);

        if ($found && $this->isCurrent($account, $keyId)) {
            $this->cookie->clear();
        }

        return $found;
    }

    /** «Alle Geräte abmelden» — every key dies, including this device's. */
    public function revokeAll(MemberAccount $account): void
    {
        $account->setDeviceKeys([]);
        $this->accounts->save($account);
        $this->cookie->clear();
    }

    /**
     * The profile's device list: live keys, newest use first, each marked
     * whether it is the device looking at the page right now.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listFor(MemberAccount $account, ?int $now = null): array
    {
        $keys = $this->live($account, $now ?? time());

        foreach ($keys as $i => $key) {
            $keys[$i]['current'] = $this->isCurrent($account, (string)($key['id'] ?? ''));
        }

        usort($keys, static fn(array $a, array $b) => strcmp((string)$b['last_used_at'], (string)$a['last_used_at']));

        return $keys;
    }

    /** Browser and system for the profile list — a hint, never an identity. */
    public static function label(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return 'Unbekanntes Gerät';
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
            str_contains($ua, 'Windows')                                => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad')      => 'iOS',
            str_contains($ua, 'Android')                                => 'Android',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux')                                  => 'Linux',
            default                                                     => 'unbekanntem System',
        };

        return $browser . ' auf ' . $system;
    }

    /** @return ?array{0:string,1:string,2:string} accountId, keyId, secret */
    private static function parse(?string $cookieValue): ?array
    {
        if ($cookieValue === null) {
            return null;
        }

        $parts = explode('.', $cookieValue, 3);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return null;
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /** Index of the key the plaintext belongs to, or null. */
    private function indexOf(array $keys, string $keyId, string $secret): ?int
    {
        $hash = hash('sha256', $secret);

        foreach ($keys as $i => $key) {
            if (($key['id'] ?? '') === $keyId && hash_equals((string)($key['key_hash'] ?? ''), $hash)) {
                return (int)$i;
            }
        }

        return null;
    }

    /** Does the cookie of this request point at that key of that account? */
    private function isCurrent(MemberAccount $account, string $keyId): bool
    {
        $parts = self::parse($this->cookie->read());

        return $parts !== null && $parts[0] === (string)$account->getId() && $parts[1] === $keyId;
    }

    /**
     * The account's keys minus the expired ones — every path reads through
     * here, so a stale entry never survives a write.
     *
     * @return array<int,array<string,string>>
     */
    private function live(MemberAccount $account, int $now): array
    {
        $live = array_filter(
            $account->getDeviceKeys(),
            static function (array $key) use ($now): bool {
                $until = strtotime((string)($key['valid_until'] ?? ''));

                return $until !== false && $until > $now;
            }
        );

        return array_values($live);
    }
}
