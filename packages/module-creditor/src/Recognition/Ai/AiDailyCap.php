<?php

namespace Z77\Module\Creditor\Recognition\Ai;

/**
 * The per-day cap of AI calls — one JSON file `{day, count}` under kibot's `paths.rateDir`,
 * counted under an exclusive lock like kibot's IP rate limiter. The day is the server's
 * calendar day; a new day starts at zero.
 *
 * Unlike kibot's limiter it FAILS CLOSED: when the counter cannot be read or written (no
 * directory, no permission), no call is made — the cap protects money, and the invoice stays
 * usable by hand.
 */
final class AiDailyCap
{
    public const FILE = 'creditor-ai-day.json';

    public function __construct(
        private readonly string $dir,
        private readonly ?\Closure $clock = null,
    ) {}

    /** Counts one call when the day's count is below $max; false = cap reached or no counter. */
    public function tryAcquire(int $max): bool
    {
        if ($max <= 0 || $this->dir === '') {
            return false;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }
        $fh = @fopen($this->dir . '/' . self::FILE, 'c+');
        if ($fh === false) {
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }
            $data  = json_decode((string) stream_get_contents($fh), true);
            $today = date('Y-m-d', $this->now());
            $count = is_array($data) && ($data['day'] ?? null) === $today ? (int) ($data['count'] ?? 0) : 0;
            if ($count >= $max) {
                return false;
            }
            ftruncate($fh, 0);
            rewind($fh);
            if (fwrite($fh, (string) json_encode(['day' => $today, 'count' => $count + 1])) === false) {
                return false;
            }
            fflush($fh);

            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Today's count (0 without a file) — for a status line. */
    public function count(): int
    {
        $data = json_decode((string) @file_get_contents($this->dir . '/' . self::FILE), true);

        return is_array($data) && ($data['day'] ?? null) === date('Y-m-d', $this->now()) ? (int) ($data['count'] ?? 0) : 0;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
