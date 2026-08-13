<?php

namespace Z77\Module\Member\Services;

/**
 * Per-address throttle for registration and resend (B7 spec default:
 * 5 attempts per e-mail address and hour). File-based, one counter file per
 * key hash and window — self-expiring, no cleanup needed beyond the window
 * files themselves being tiny. Complements FormGuard, which throttles the
 * session: this one holds even when the attacker rotates sessions but hammers
 * one address.
 *
 * Two buckets share the mechanism (B7 v1.1.0):
 *
 *   allow()        e-mail address, hour  — registration, resend, login request
 *   allowTenant()  project reference, DAY — invitations
 *
 * ⚠️ Invitations are counted per REFERENCE, not per address, and that is the
 * point: an address counter would let a taken-over master account send
 * invitations by the dozen, one each to a different recipient.
 */
final class MemberThrottle
{
    /** B7 spec default — also the session limit the login form runs on. */
    public const MAX_PER_HOUR = 5;

    /** B7 v1.1.0 spec default: invitations per project reference and day. */
    public const MAX_INVITES_PER_DAY = 10;

    public function __construct(
        private string $dir,
        private int $limit = self::MAX_PER_HOUR,
        private int $windowSeconds = 3600,
    ) {
    }

    /** True while the address stays under the limit for the current window; counts the attempt. */
    public function allow(string $email, ?int $now = null): bool
    {
        return $this->count('addr:' . mb_strtolower(trim($email)), $this->limit, $this->windowSeconds, $now);
    }

    /**
     * True while the project reference stays under its DAILY invitation limit;
     * counts the attempt. The limit is passed in because it comes from the
     * module config, which this service deliberately does not read.
     */
    public function allowTenant(string $tenantRef, int $limit = self::MAX_INVITES_PER_DAY, ?int $now = null): bool
    {
        return $this->count('tenant:' . $tenantRef, $limit, 86400, $now);
    }

    /**
     * One counter file per key and window. The key is namespaced before
     * hashing so the two buckets can never land on the same file — a project
     * reference that happens to look like an address would otherwise share the
     * registration counter.
     */
    private function count(string $key, int $limit, int $windowSeconds, ?int $now): bool
    {
        $now ??= time();
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }

        $window = intdiv($now, $windowSeconds);
        $file   = $this->dir . '/' . hash('sha256', $key) . '-' . $window;

        $count = is_file($file) ? (int)file_get_contents($file) : 0;
        if ($count >= $limit) {
            return false;
        }

        file_put_contents($file, (string)($count + 1), LOCK_EX);

        return true;
    }
}
