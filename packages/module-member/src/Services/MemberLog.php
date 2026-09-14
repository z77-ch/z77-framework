<?php

namespace Z77\Module\Member\Services;

/**
 * The account log: one line per thing that happened TO or THROUGH a member
 * account — sign-in, a change to the person's data, a change to what she may
 * do, and the end of the account. The answer to «when did this person last
 * sign in, who paused her, when was the account deleted» — questions a data
 * subject or an operator asks AFTER the fact, when nothing else remembers.
 *
 * Bookkeeping, not a gate (like {@see \Z77\Shared\Forms\FormLog}, whose shape
 * this follows): it decides nothing. ⚠️ Nothing here throws — a full disk
 * costs a log line, never a sign-in.
 *
 * Storage: `logs/member-YYYY-MM.jsonl`, appended with LOCK_EX. Monthly files,
 * so expiry is a file deletion ({@see self::sweep()}, {@see self::RETENTION_DAYS}).
 *
 * ⚠️ PERSONAL DATA, and a different purpose from the form log: this file
 * says what a KNOWN person did. Deliberately NO e-mail address and NO name
 * in a line — the member id is enough while the account exists, and after
 * the account is deleted the line keeps saying «this id was deleted on that
 * day» without saying who that was. The IP is kept because «was that me?»
 * is the question a person asks about a sign-in. Retention and content are
 * named in the installation's privacy text; do not widen the record without
 * widening that text.
 *
 * Who did it (`actor`): `self` by default — the person, in her own session.
 * A controller acting for someone else sets it for the request
 * ({@see self::actor()}): `operator` (the backend), `owner:<memberId>` (the
 * tenant's owner, at the project's tenant), `system` (a job, a hook).
 *
 * Events are dotted lower-case names. The module writes: `login`, `logout`,
 * `logout.all`, `profile.update`, `totp.on`, `totp.off`, `device.remove`,
 * `account.delete`. A project adds its own (memberships, tenant changes)
 * through the same method — one file, one shape.
 */
final class MemberLog
{
    /** Relative to ABS_BASE_PATH. */
    public const DIR = 'logs';
    /** Twelve months — the horizon of «when did that happen» questions. */
    public const RETENTION_DAYS = 365;

    private static ?string $actor = null;

    /** Who acts in this request when it is not the person herself. Null resets to `self`. */
    public static function actor(?string $actor): void
    {
        $actor       = trim((string)$actor);
        self::$actor = $actor === '' ? null : $actor;
    }

    /**
     * Records one event.
     *
     * @param string  $event    dotted lower-case name, see the class doc
     * @param ?string $memberId the account concerned (never its address)
     * @param array{tenant?: int|string|null, detail?: ?string, actor?: ?string} $ctx
     *        `tenant` the project reference concerned, `detail` one short
     *        technical fact («redeem», «name,company», «owner: tenant 4
     *        orphaned»), `actor` overrides the request's actor for this line
     */
    public static function write(string $event, ?string $memberId, array $ctx = []): void
    {
        try {
            $tenant = $ctx['tenant'] ?? null;
            $row    = [
                'at'     => date('c'),
                'event'  => trim($event),
                'member' => $memberId !== null && trim($memberId) !== '' ? trim($memberId) : null,
                'tenant' => $tenant === null || $tenant === '' ? null : (string)$tenant,
                'actor'  => trim((string)($ctx['actor'] ?? self::$actor ?? 'self')) ?: 'self',
                'detail' => isset($ctx['detail']) && trim((string)$ctx['detail']) !== '' ? mb_substr(trim((string)$ctx['detail']), 0, 250) : null,
                'ip'     => self::ip(),
            ];
            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $file = self::file();
            if ($line === false || $file === null) {
                return;
            }
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // See the class doc: a log is never worth a failed request.
        }
    }

    /**
     * Deletes monthly files older than the retention. Called by the cleanup
     * job. The FILE's month decides, not its mtime.
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
        foreach (glob($dir . '/member-*.jsonl') ?: [] as $file) {
            if (!preg_match('/member-(\d{4})-(\d{2})\.jsonl$/', $file, $m)) {
                continue;
            }
            $monthEnd = mktime(23, 59, 59, (int)$m[2] + 1, 0, (int)$m[1]);
            if ($monthEnd < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Every line about one member, oldest first — what an operator reads
     * when the person asks. Walks the monthly files; the log is small.
     *
     * @return list<array<string, mixed>>
     */
    public static function linesFor(string $memberId): array
    {
        $dir = self::dir();
        if ($dir === null || trim($memberId) === '') {
            return [];
        }
        $files = glob($dir . '/member-*.jsonl') ?: [];
        sort($files);
        $rows = [];
        foreach ($files as $file) {
            foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && ($row['member'] ?? null) === trim($memberId)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /** REMOTE_ADDR and nothing else — the same rule as the form log. */
    private static function ip(): ?string
    {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

        return $ip !== '' ? $ip : null;
    }

    private static function file(): ?string
    {
        $dir = self::dir();

        return $dir === null ? null : $dir . '/member-' . date('Y-m') . '.jsonl';
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
