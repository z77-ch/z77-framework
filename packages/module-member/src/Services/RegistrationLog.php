<?php

namespace Z77\Module\Member\Services;

use Z77\Shared\GeoIp\CountryLookup;

/**
 * One line per registration attempt — the answer to «where do these come
 * from?», written where an operator can read it.
 *
 * This is bookkeeping, NOT a gate. It decides nothing and blocks nobody; it
 * records what the gates already decided so a human can look afterwards and
 * judge whether anything systematic is happening. Blocking rules that are
 * built on top read this file's evidence, never the other way round.
 *
 * ⚠️ Nothing here throws. It sits behind a public form; a full disk or a
 * read-only directory must cost a log line, never a registration. Every
 * failure path returns silently.
 *
 * Storage: `logs/registration-YYYY-MM.jsonl`, one JSON object per line,
 * appended with LOCK_EX. Monthly files because that makes expiry a file
 * deletion instead of a rewrite — a log that has to be rewritten to prune is
 * a log that corrupts under concurrency.
 *
 * ⚠️ PERSONAL DATA. A line carries the e-mail address, the IP and the
 * country. That is a different purpose from the abuse counters (which store
 * only a hash, and only for their counting window), so it needs its own
 * sentence in the privacy policy and its own retention — see
 * {@see self::sweep()}. Do not widen the record without widening that text.
 */
final class RegistrationLog
{
    /** Relative to ABS_BASE_PATH. */
    public const DIR = 'logs';

    /** Files older than this are removed by {@see self::sweep()}. */
    public const RETENTION_DAYS = 90;

    /** Which form the attempt came through. */
    public const FORM_REGISTER = 'register';
    public const FORM_INVITE   = 'invite';
    public const FORM_LOGIN    = 'login';

    /** Set by the flow during a submit, consumed by the next write(). */
    private static ?string $note = null;

    /**
     * A detail only the FLOW knows — `new`, `known`, `throttled`. The form
     * handler cannot tell these apart: it sees one bool, because the page
     * must answer identically whether or not the address has an account
     * (anti-oracle). Server-side the difference is exactly what tells a
     * probe from a customer, so it is recorded.
     *
     * ⚠️ Ordering: the flow runs inside the handler's dispatch, and the
     * observer is called AFTER it — so a note set here is picked up by the
     * write() belonging to the same submit. It is cleared on consumption, so
     * a note without a following write cannot leak into a later line.
     */
    public static function note(string $detail): void
    {
        self::$note = $detail;
    }

    /**
     * Records one attempt.
     *
     * @param string      $form    one of self::FORM_*
     * @param string      $outcome the PublicFormHandler outcome, or a flow
     *                             detail like `new` / `known` / `throttled`
     * @param string|null $email   the address that was typed, if the submit
     *                             got far enough for one to exist
     * @param array<string, scalar|null> $extra additional named facts
     */
    public static function write(
        string $form,
        string $outcome,
        ?string $email = null,
        array $extra = [],
    ): void {
        try {
            $ip = self::clientIp();

            $row = [
                'at'      => date('c'),
                'form'    => $form,
                'outcome' => $outcome,
                'ip'      => $ip,
                // Resolved here, once, at the moment of the attempt: the
                // country map changes over time, so an IP looked up next month
                // may answer differently than it did today.
                'country' => $ip === null ? null : CountryLookup::of($ip),
                'detail'  => self::$note,
                'email'   => $email !== null && $email !== '' ? $email : null,
                'ua'      => self::userAgent(),
            ];
            self::$note = null;

            foreach ($extra as $key => $value) {
                if (is_string($key) && $key !== '' && !isset($row[$key])) {
                    $row[$key] = is_scalar($value) || $value === null ? $value : null;
                }
            }

            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }

            $file = self::file();
            if ($file === null) {
                return;
            }

            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // See the class docblock: a log is never worth a failed sign-up.
        }
    }

    /**
     * Deletes monthly files older than the retention. Called by the cleanup
     * job; safe to call at any time.
     *
     * @return int how many files were removed
     */
    public static function sweep(?int $now = null): int
    {
        $now ??= time();
        $dir = self::dir();
        if ($dir === null) {
            return 0;
        }

        $removed = 0;
        $cutoff  = $now - self::RETENTION_DAYS * 86400;

        foreach (glob($dir . '/registration-*.jsonl') ?: [] as $file) {
            // The FILE's month decides, not its mtime: an untouched month must
            // still expire, and a late append must not extend a whole month's
            // life. The cutoff is the END of that month.
            if (!preg_match('/registration-(\d{4})-(\d{2})\.jsonl$/', $file, $m)) {
                continue;
            }
            $monthEnd = mktime(23, 59, 59, (int)$m[2] + 1, 0, (int)$m[1]);
            if ($monthEnd < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** @return list<array<string, mixed>> newest first — the backend view reads this */
    public static function recent(int $limit = 200): array
    {
        $dir = self::dir();
        if ($dir === null) {
            return [];
        }

        $files = glob($dir . '/registration-*.jsonl') ?: [];
        rsort($files); // newest month first by name, which sorts chronologically

        $rows = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $rows[] = $row;
                }
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * The visitor's address, from REMOTE_ADDR.
     *
     * ⚠️ NEVER X-Forwarded-For or Client-IP. Those are set by whoever sends
     * the request: a country recorded from them is whatever the sender wants
     * it to be, and a block built on them is bypassed by one header. This
     * installation is not behind a reverse proxy; if that ever changes, the
     * proxy's own trusted-header handling belongs here, deliberately, not a
     * blanket trust of the header.
     */
    private static function clientIp(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return $ip !== '' && @inet_pton($ip) !== false ? $ip : null;
    }

    /** Trimmed to a sane length — a user agent is a free-text field from outside. */
    private static function userAgent(): ?string
    {
        $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') {
            return null;
        }

        return mb_substr($ua, 0, 250);
    }

    private static function file(): ?string
    {
        $dir = self::dir();
        if ($dir === null) {
            return null;
        }

        return $dir . '/registration-' . date('Y-m') . '.jsonl';
    }

    private static function dir(): ?string
    {
        if (!defined('ABS_BASE_PATH')) {
            return null;
        }

        $dir = ABS_BASE_PATH . '/' . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir;
    }
}
