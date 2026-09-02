<?php

namespace Z77\Module\Member\Services;

use Z77\Shared\Throttle\FileThrottle;

/**
 * Per-address throttle for registration and resend (B7 spec default:
 * 5 attempts per e-mail address and hour). File-based, one counter file per
 * key hash and window — self-expiring, no cleanup needed beyond the window
 * files themselves being tiny. Complements FormGuard, which throttles the
 * session: this one holds even when the attacker rotates sessions but hammers
 * one address.
 *
 * Since 2026-09-02 the counter mechanism lives in the shared
 * {@see FileThrottle} (also used by the API rate limit); this class keeps the
 * member-specific buckets, keys, and limits.
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

    private FileThrottle $counter;

    public function __construct(
        string $dir,
        private int $limit = self::MAX_PER_HOUR,
        private int $windowSeconds = 3600,
    ) {
        $this->counter = new FileThrottle($dir);
    }

    /**
     * Where the counters live: `var/lib/throttle/member`, not `data/`.
     *
     * `data/` is the data area — what a restore is supposed to bring back.
     * A throttle counter is a time window and a number; deleting it starts the
     * running window at zero and nothing else happens. Everything below `var/`
     * is disposable by definition: wiping `var/` resets the installation's
     * runtime state without touching a single record (ADR-034, path per ADR-035).
     *
     * `var/lib` and not `var/cache`: in the release layout `var/lib` is the one
     * SHARED branch of `var/` (a signpost into `shared/`), because a lock-out
     * window must not restart just because a release was switched.
     *
     * One place, because three call sites (RegistrationFlow, LoginFlow,
     * InvitationFlow) all need the same directory.
     */
    public static function defaultDir(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/member';
    }

    /** True while the address stays under the limit for the current window; counts the attempt. */
    public function allow(string $email, ?int $now = null): bool
    {
        return $this->counter->allow('addr:' . mb_strtolower(trim($email)), $this->limit, $this->windowSeconds, $now);
    }

    /**
     * True while the project reference stays under its DAILY invitation limit;
     * counts the attempt. The limit is passed in because it comes from the
     * module config, which this service deliberately does not read.
     */
    public function allowTenant(string $tenantRef, int $limit = self::MAX_INVITES_PER_DAY, ?int $now = null): bool
    {
        return $this->counter->allow('tenant:' . $tenantRef, $limit, 86400, $now);
    }

    /**
     * True while the ORIGIN stays under its hourly limit; counts the attempt.
     *
     * The address throttle above holds an attacker who hammers ONE address.
     * This one holds the opposite move — a new invented address on every try,
     * which walks straight past a per-address counter. Both are needed; either
     * alone has an obvious way around it.
     *
     * IPv6 /64 normalization and the REMOTE_ADDR caveat: see
     * {@see FileThrottle::normalizeIp()}.
     */
    public function allowIp(string $ip, int $limit, ?int $now = null): bool
    {
        $normalised = self::normalizeIp($ip);
        if ($normalised === null) {
            // No usable address (CLI, a malformed REMOTE_ADDR): the other
            // gates still apply. Refusing here would lock out a caller for a
            // fault that is not theirs.
            return true;
        }

        return $this->counter->allow('ip:' . $normalised, $limit, $this->windowSeconds, $now);
    }

    /** IPv6 → its /64 prefix, IPv4 → itself, anything unparseable → null. */
    public static function normalizeIp(string $ip): ?string
    {
        return FileThrottle::normalizeIp($ip);
    }
}
