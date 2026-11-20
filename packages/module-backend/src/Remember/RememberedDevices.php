<?php

namespace Z77\Module\Backend\Remember;

use Z77\Persistence\File\Storage\FileStorage;
use Z77\Shared\Entities\BackendUser;

/**
 * The store behind «Auf diesem Gerät angemeldet bleiben» of the backend login (login.md
 * LOGIN-REMEMBER-001), in `data/framework/auth/backendDevices.json`.
 *
 *   cookie value = selector.validator      — plaintext ONLY on the device
 *   row          = selector, sha256(validator), user id, the user's session stamp, times, label
 *
 * - The selector (16 random bytes) finds the row, the validator (32 random bytes) proves the
 *   device; only the validator's SHA-256 is stored and compared in constant time.
 * - Every use ROTATES the validator. A device that presents an older one is a copy: the row is
 *   removed and the caller is told ({@see DeviceRedeem::REUSED}). The validator replaced last
 *   stays good for {@see GRACE_SECONDS}: a browser that starts with several tabs sends the same
 *   cookie from each of them before the first answer arrives.
 * - A row carries the user's `auth_epoch` of the moment it was issued. Whatever raises that
 *   stamp (password change or reset, role change, 2FA switched on or reset by an admin) ends
 *   every remembered device with it, without a hook at each of those places — the caller
 *   compares ({@see DeviceBridge}).
 * - The expiry is fixed at issue and does NOT roll: `rememberDeviceDays` after the last full
 *   login the password is asked again.
 *
 * ⚠️ Plain rows through {@see FileStorage}, deliberately not an entity, for the reason given at
 * PasswordResetTokens: compare and rotate must happen inside ONE lock, or two requests with the
 * same cookie would both rotate. The file appears with the first remembered device; a missing
 * file reads as empty, so there is no installer step.
 */
final class RememberedDevices
{
    public const PATH          = 'framework/auth/backendDevices.json';
    public const COOKIE        = 'z77_backend_device';
    public const GRACE_SECONDS = 60;

    /** Per user. One more drops the device unused for longest (an orphan whose cookie is gone). */
    public const MAX_PER_USER = 10;

    private FileStorage $storage;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    /**
     * Remembers a device for this user: returns the cookie value — hand it to the device and
     * forget it. Call only at the end of a COMPLETE login (password, and the code where 2FA is on).
     */
    public function issue(BackendUser $user, string $label, int $ttlSeconds, ?int $now = null): string
    {
        $now ??= time();
        $selector  = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $userId    = (int) $user->getId();
        $row       = [
            'selector'       => $selector,
            'validator_hash' => hash('sha256', $validator),
            'previous_hash'  => null,
            'rotated_at'     => null,
            'user_id'        => $userId,
            'auth_epoch'     => $user->getAuthEpoch(),
            'label'          => mb_substr($label, 0, 80),
            'created_at'     => $now,
            'last_used_at'   => $now,
            'expires_at'     => $now + $ttlSeconds,
        ];

        $this->storage->withExclusiveLock(self::PATH, function () use ($row, $userId, $now): void {
            $rows   = self::live($this->load(), $now);
            $rows[] = $row;
            $this->storage->save(self::PATH, self::capped($rows, $userId));
        });

        return $selector . '.' . $validator;
    }

    /** Compares and rotates inside one lock; see the class comment and {@see DeviceRedeem}. */
    public function redeem(string $cookieValue, ?int $now = null): DeviceRedeem
    {
        $now ??= time();
        $parts = self::parse($cookieValue);
        if ($parts === null) {
            return new DeviceRedeem(DeviceRedeem::UNKNOWN);
        }
        [$selector, $validator] = $parts;

        return $this->storage->withExclusiveLock(self::PATH, function () use ($selector, $validator, $now): DeviceRedeem {
            $rows  = $this->load();
            $index = self::indexOf($rows, $selector);
            if ($index === null) {
                return new DeviceRedeem(DeviceRedeem::UNKNOWN);
            }

            $row    = $rows[$index];
            $userId = (int) ($row['user_id'] ?? 0);
            $epoch  = (int) ($row['auth_epoch'] ?? 0);
            $hash   = hash('sha256', $validator);

            if ((int) ($row['expires_at'] ?? 0) <= $now) {
                unset($rows[$index]);
                $this->storage->save(self::PATH, array_values($rows));
                return new DeviceRedeem(DeviceRedeem::UNKNOWN);
            }

            if (hash_equals((string) ($row['validator_hash'] ?? ''), $hash)) {
                $next = bin2hex(random_bytes(32));
                $rows[$index]['previous_hash']  = $hash;
                $rows[$index]['rotated_at']     = $now;
                $rows[$index]['validator_hash'] = hash('sha256', $next);
                $rows[$index]['last_used_at']   = $now;
                $this->storage->save(self::PATH, $rows);

                return new DeviceRedeem(DeviceRedeem::ROTATED, $userId, $epoch, $selector . '.' . $next, (int) $row['expires_at']);
            }

            $previous = (string) ($row['previous_hash'] ?? '');
            if ($previous !== '' && hash_equals($previous, $hash) && $now - (int) ($row['rotated_at'] ?? 0) <= self::GRACE_SECONDS) {
                return new DeviceRedeem(DeviceRedeem::RECENT, $userId, $epoch);
            }

            // A known device with a token that is not its current one: somebody holds a copy.
            unset($rows[$index]);
            $this->storage->save(self::PATH, array_values($rows));

            return new DeviceRedeem(DeviceRedeem::REUSED, $userId, $epoch);
        });
    }

    /**
     * Logout on this device: its row goes. Only for the cookie that proves it (the current
     * validator, or the one just rotated away) — a selector alone removes nothing.
     */
    public function forget(string $cookieValue): void
    {
        $parts = self::parse($cookieValue);
        if ($parts === null) {
            return;
        }
        [$selector, $validator] = $parts;

        $this->storage->withExclusiveLock(self::PATH, function () use ($selector, $validator): void {
            $rows  = $this->load();
            $index = self::indexOf($rows, $selector);
            if ($index === null) {
                return;
            }
            $hash     = hash('sha256', $validator);
            $previous = (string) ($rows[$index]['previous_hash'] ?? '');
            if (!hash_equals((string) ($rows[$index]['validator_hash'] ?? ''), $hash)
                && ($previous === '' || !hash_equals($previous, $hash))
            ) {
                return;
            }
            unset($rows[$index]);
            $this->storage->save(self::PATH, array_values($rows));
        });
    }

    /** «Alle Geräte abmelden» for one user: password changed, roles changed, account removed. */
    public function revokeAllFor(int $userId): void
    {
        $this->storage->withExclusiveLock(self::PATH, function () use ($userId): void {
            $rows = $this->load();
            $kept = array_filter($rows, static fn(array $row): bool => (int) ($row['user_id'] ?? 0) !== $userId);
            if (count($kept) !== count($rows)) {
                $this->storage->save(self::PATH, array_values($kept));
            }
        });
    }

    /**
     * The same after a change that already raised the user's session stamp — the stamp is what
     * ends the devices, the rows are only tidied. A store that cannot be written is logged.
     */
    public static function revokeQuietly(int $userId): void
    {
        try {
            (new self())->revokeAllFor($userId);
        } catch (\RuntimeException $e) {
            error_log('RememberedDevices: rows of user id ' . $userId . ' not removed (the raised session stamp ends them anyway) — ' . $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function load(): array
    {
        return array_values(array_filter($this->storage->load(self::PATH), 'is_array'));
    }

    /** @return ?array{0:string,1:string} selector, validator — both of the exact shape issue() makes */
    private static function parse(string $cookieValue): ?array
    {
        return preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D', $cookieValue, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    private static function indexOf(array $rows, string $selector): ?int
    {
        foreach ($rows as $index => $row) {
            if (hash_equals((string) ($row['selector'] ?? ''), $selector)) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function live(array $rows, int $now): array
    {
        return array_values(array_filter($rows, static fn(array $row): bool => (int) ($row['expires_at'] ?? 0) > $now));
    }

    /**
     * At most MAX_PER_USER rows of this user, the most recently used kept.
     *
     * @return list<array<string, mixed>>
     */
    private static function capped(array $rows, int $userId): array
    {
        $own = array_filter($rows, static fn(array $row): bool => (int) ($row['user_id'] ?? 0) === $userId);
        if (count($own) <= self::MAX_PER_USER) {
            return array_values($rows);
        }

        uasort($own, static fn(array $a, array $b): int => (int) ($b['last_used_at'] ?? 0) <=> (int) ($a['last_used_at'] ?? 0));
        $drop = array_flip(array_slice(array_keys($own), self::MAX_PER_USER));

        return array_values(array_diff_key($rows, $drop));
    }
}
