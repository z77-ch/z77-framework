<?php

namespace Z77\Module\Backend\PasswordReset;

use Z77\Persistence\File\Storage\FileStorage;

/**
 * The store behind «Passwort vergessen» (login.md LOGIN-RESET-001): one-time links for backend
 * users, in `data/framework/auth/passwordResets.json`. {@see issue()} hands out the plaintext
 * exactly once, for the mail; only its SHA-256 is ever stored, next to the user id, the expiry
 * and the created time.
 *
 * ⚠️ Plain rows through {@see FileStorage}, deliberately not an entity: redeeming a link once
 * needs the check and the mark inside ONE lock (DATA-LOCK-001), and the entity manager takes that
 * same lock itself on flush, so it cannot run inside it. {@see consume()} is what lets exactly
 * one of two concurrent POSTs through.
 *
 * Lives in module-backend because only the backend login uses it. The file does not exist until
 * the first request: a missing file reads as empty and `save()` creates the directory, so a fresh
 * installation needs no installer step.
 */
final class PasswordResetTokens
{
    public const PATH        = 'framework/auth/passwordResets.json';
    public const TTL_SECONDS = 1800;

    private FileStorage $storage;

    public function __construct(?FileStorage $storage = null)
    {
        $this->storage = $storage ?? new FileStorage();
    }

    /**
     * A new link for this user: returns the PLAINTEXT — hand it to the mail and forget it. The
     * user's previous entries die now, so only the newest link works.
     */
    public function issue(int $userId, ?int $now = null): string
    {
        $now ??= time();
        $plain = bin2hex(random_bytes(32));

        $this->storage->withExclusiveLock(self::PATH, function () use ($plain, $userId, $now): void {
            $rows   = array_filter($this->load(), static fn(array $row): bool => (int) $row['user_id'] !== $userId);
            $rows[] = [
                'token_hash' => hash('sha256', $plain),
                'user_id'    => $userId,
                'created_at' => $now,
                'expires_at' => $now + self::TTL_SECONDS,
                'used_at'    => null,
            ];
            $this->storage->save(self::PATH, array_values($rows));
        });

        return $plain;
    }

    /**
     * The user id behind a live link, WITHOUT redeeming it: a mail scanner following the link
     * must not burn it. Null for anything that cannot be redeemed.
     */
    public function peek(string $plain, ?int $now = null): ?int
    {
        $rows  = $this->load();
        $index = $this->indexOf($rows, $plain, $now ?? time());

        return $index === null ? null : (int) $rows[$index]['user_id'];
    }

    /**
     * Redeems a link, once: returns the user id and marks the entry used, inside one lock. Of two
     * concurrent calls, the second sees the mark and gets null.
     */
    public function consume(string $plain, ?int $now = null): ?int
    {
        $now ??= time();

        return $this->storage->withExclusiveLock(self::PATH, function () use ($plain, $now): ?int {
            $rows  = $this->load();
            $index = $this->indexOf($rows, $plain, $now);
            if ($index === null) {
                return null;
            }

            $rows[$index]['used_at'] = $now;
            $this->storage->save(self::PATH, $rows);

            return (int) $rows[$index]['user_id'];
        });
    }

    /** Ends the user's open link (an admin set a new password or a new recovery address). */
    public function revokeFor(int $userId): void
    {
        $this->storage->withExclusiveLock(self::PATH, function () use ($userId): void {
            $rows = $this->load();
            $kept = array_filter($rows, static fn(array $row): bool => (int) $row['user_id'] !== $userId);
            if (count($kept) !== count($rows)) {
                $this->storage->save(self::PATH, array_values($kept));
            }
        });
    }

    /**
     * Removes every expired or used entry and returns how many. Runs on every forgot request and
     * in the job {@see \Z77\Module\Backend\Jobs\PasswordResetCleanupJob}.
     */
    public function purge(?int $now = null): int
    {
        $now ??= time();

        return $this->storage->withExclusiveLock(self::PATH, function () use ($now): int {
            $rows = $this->load();
            $live = array_filter($rows, static fn(array $row): bool => self::isLive($row, $now));
            $removed = count($rows) - count($live);
            if ($removed > 0) {
                $this->storage->save(self::PATH, array_values($live));
            }

            return $removed;
        });
    }

    /** @return list<array<string, mixed>> */
    private function load(): array
    {
        return array_values(array_filter($this->storage->load(self::PATH), 'is_array'));
    }

    /** Found by the hash of the presented value, compared in constant time. */
    private function indexOf(array $rows, string $plain, int $now): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $plain) !== 1) {
            return null;
        }

        $hash = hash('sha256', $plain);
        foreach ($rows as $index => $row) {
            if (hash_equals((string) ($row['token_hash'] ?? ''), $hash)) {
                return self::isLive($row, $now) ? $index : null;
            }
        }

        return null;
    }

    private static function isLive(array $row, int $now): bool
    {
        return ($row['used_at'] ?? null) === null && (int) ($row['expires_at'] ?? 0) > $now;
    }
}
