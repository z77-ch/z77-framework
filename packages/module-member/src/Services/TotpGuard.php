<?php

namespace Z77\Module\Member\Services;

/**
 * Brute-force guard for TOTP codes (B8 spec: 5 failures → 15-minute lock
 * window; while locked, even the RIGHT code is refused). File-based per
 * account, like the address throttle; a successful code clears the slate.
 */
final class TotpGuard
{
    public const MAX_FAILURES  = 5;
    public const LOCK_SECONDS  = 900;

    public function __construct(private string $dir)
    {
    }

    /**
     * Under `lib/throttle`, next to the address throttle — same class of file:
     * a counter and a window, disposable. Deleting it frees whoever is locked
     * out, which is exactly what {@see reset()} does on a valid code.
     */
    public static function create(): self
    {
        return new self(
            rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/lib/throttle/totp-guard'
        );
    }

    public function isLocked(string $accountId, ?int $now = null): bool
    {
        $state = $this->read($accountId);

        return ($state['locked_until'] ?? 0) > ($now ?? time());
    }

    /** Counts a wrong code; the fifth one starts the lock window. */
    public function recordFailure(string $accountId, ?int $now = null): void
    {
        $now   = $now ?? time();
        $state = $this->read($accountId);

        $state['failures'] = ($state['failures'] ?? 0) + 1;
        if ($state['failures'] >= self::MAX_FAILURES) {
            $state['locked_until'] = $now + self::LOCK_SECONDS;
            $state['failures']     = 0; // the window IS the penalty; afterwards the count restarts
        }

        $this->write($accountId, $state);
    }

    /** A valid code clears failures and any expired lock remnants. */
    public function reset(string $accountId): void
    {
        @unlink($this->file($accountId));
    }

    private function read(string $accountId): array
    {
        $file = $this->file($accountId);

        return is_file($file) ? (array)json_decode((string)file_get_contents($file), true) : [];
    }

    private function write(string $accountId, array $state): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
        file_put_contents($this->file($accountId), json_encode($state), LOCK_EX);
    }

    private function file(string $accountId): string
    {
        return $this->dir . '/' . hash('sha256', $accountId) . '.json';
    }
}
