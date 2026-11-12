<?php

namespace Z77\Module\Api\Services;

use Z77\Shared\Api\ApiResult;

/**
 * Idempotency for writing API requests (ADR-057): a phone on a bad connection
 * resends a POST it never saw answered; the second send must return the first
 * answer, not capture the receipt twice.
 *
 *   key  = the client's `Idempotency-Key` header, scoped to the principal
 *          (two devices may pick the same UUID without meeting)
 *   kept = the request's fingerprint (method, path, body, files) + the result,
 *          for TTL_SECONDS
 *
 * Same key + same fingerprint → the stored result, the service does not run.
 * Same key + different fingerprint → 409 `idempotency_conflict` (a client bug:
 * one key for two different requests). A 5xx is not stored — a retry may succeed.
 *
 * One small file per key under var/lib/api/idempotency (disposable, like the
 * throttle counters). The file is LOCKED while the service runs, so two
 * parallel sends of one key are serialised: the second waits, then replays.
 */
final class IdempotencyStore
{
    public const TTL_SECONDS = 86400;

    /** What a client may send: a UUID or any token of the same alphabet. */
    public const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,128}$/';

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param string                $principal  whose key it is (`device:3`)
     * @param callable(): ApiResult $execute    the service call, run only on the first send
     */
    public function run(string $principal, string $key, string $fingerprint, callable $execute, ?int $now = null): ApiResult
    {
        $now = $now ?? time();
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("IdempotencyStore: could not create {$this->dir}");
        }

        $file = $this->dir . '/' . hash('sha256', $principal . "\n" . $key) . '.json';
        $fh   = fopen($file, 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if ($fh !== false) {
                fclose($fh);
            }
            throw new \RuntimeException("IdempotencyStore: could not lock {$file}");
        }

        try {
            $stored = json_decode((string) stream_get_contents($fh), true);
            if (is_array($stored) && (int) ($stored['created_at'] ?? 0) + self::TTL_SECONDS > $now) {
                if (!hash_equals((string) ($stored['fingerprint'] ?? ''), $fingerprint)) {
                    return ApiResult::error(
                        'idempotency_conflict',
                        409,
                        'Idempotency-Key was already used for a different request.'
                    );
                }
                return self::restore((array) ($stored['result'] ?? []));
            }

            $result = $execute();

            if ($result->status < 500) {
                rewind($fh);
                ftruncate($fh, 0);
                fwrite($fh, json_encode([
                    'fingerprint' => $fingerprint,
                    'created_at'  => $now,
                    'result'      => self::export($result),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                fflush($fh);
            }

            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
            clearstatcache(true, $file);
            if (@filesize($file) === 0) {
                @unlink($file); // nothing stored (5xx, exception) — no empty husk
            }
            $this->sweep(time()); // file age is wall-clock, whatever $now a caller passed

        }
    }

    /** Removes records past the TTL — few files, cheap enough on every call. */
    private function sweep(int $now): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime + self::TTL_SECONDS < $now) {
                @unlink($file);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function export(ApiResult $result): array
    {
        return [
            'status'      => $result->status,
            'data'        => $result->data,
            'etag'        => $result->etag,
            'headers'     => $result->headers,
            'code'        => $result->errorCode,
            'message'     => $result->errorMessage,
            'retry_after' => $result->retryAfter,
            'fields'      => $result->fields,
        ];
    }

    /** @param array<string,mixed> $r */
    private static function restore(array $r): ApiResult
    {
        if ((string) ($r['code'] ?? '') !== '') {
            return ApiResult::error(
                (string) $r['code'],
                (int) ($r['status'] ?? 500),
                (string) ($r['message'] ?? ''),
                isset($r['retry_after']) ? (int) $r['retry_after'] : null,
                array_map('strval', (array) ($r['fields'] ?? []))
            );
        }
        if ((int) ($r['status'] ?? 200) === 202) {
            return ApiResult::accepted((array) ($r['data'] ?? []), (array) ($r['headers'] ?? []));
        }
        return ApiResult::payload(
            (array) ($r['data'] ?? []),
            isset($r['etag']) ? (string) $r['etag'] : null,
            (array) ($r['headers'] ?? [])
        );
    }
}
