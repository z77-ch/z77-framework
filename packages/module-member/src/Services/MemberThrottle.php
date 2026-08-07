<?php

namespace Z77\Module\Member\Services;

/**
 * Per-address throttle for registration and resend (B7 spec default:
 * 5 attempts per e-mail address and hour). File-based, one counter file per
 * address hash and hour window — self-expiring, no cleanup needed beyond the
 * window files themselves being tiny. Complements FormGuard, which throttles
 * the session: this one holds even when the attacker rotates sessions but
 * hammers one address.
 */
final class MemberThrottle
{
    /** B7 spec default — also the session limit the login form runs on. */
    public const MAX_PER_HOUR = 5;

    public function __construct(
        private string $dir,
        private int $limit = self::MAX_PER_HOUR,
        private int $windowSeconds = 3600,
    ) {
    }

    /** True while the address stays under the limit for the current window; counts the attempt. */
    public function allow(string $email, ?int $now = null): bool
    {
        $now ??= time();
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }

        $window = intdiv($now, $this->windowSeconds);
        $file   = $this->dir . '/' . hash('sha256', mb_strtolower(trim($email))) . '-' . $window;

        $count = is_file($file) ? (int)file_get_contents($file) : 0;
        if ($count >= $this->limit) {
            return false;
        }

        file_put_contents($file, (string)($count + 1), LOCK_EX);

        return true;
    }
}
