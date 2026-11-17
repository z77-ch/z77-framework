<?php

namespace Z77\Tests\Runner;

/**
 * The working tree before and after a run: what a harness must never leave behind.
 *
 * Watched: every path under `packages/<pkg>/res/migrations/` (any git status — a stray `diff`
 * migration, a changed or deleted one) and every UNTRACKED file under `packages/` and `tests/`.
 * Each is keyed with its status, size and modification time, so a file that is new, changed or
 * gone after the run is named. The guard reports, it never deletes: the developer decides.
 *
 * Which harness wrote it is not certain when several run side by side; the runner names the ones
 * that were running at the file's modification time. A writer outside the runner (another agent
 * in the same tree) shows up here too — that is why parallel builders use their own worktree.
 */
final class TreeGuard
{
    /** @return array<string, string>|null path => "status|size|mtime"; null when git is not usable */
    public static function snapshot(string $root): ?array
    {
        $out = [];
        exec('git -C ' . escapeshellarg($root) . ' status --porcelain=v1 -z --untracked-files=all -- packages tests 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $out, $code);
        if ($code !== 0) {
            return null;
        }
        $entries = explode("\0", implode("\n", $out));
        $state   = [];
        for ($i = 0, $n = count($entries); $i < $n; $i++) {
            $entry = $entries[$i];
            if (strlen($entry) < 4) {
                continue;
            }
            $status = substr($entry, 0, 2);
            $path   = substr($entry, 3);
            if ($status[0] === 'R' || $status[0] === 'C') {
                $i++; // -z puts the rename's source as the next entry
            }
            $isMigration = (bool) preg_match('#^packages/(?:kernel/)?[^/]+/res/migrations/#', $path);
            if ($status !== '??' && !$isMigration) {
                continue;
            }
            $file         = $root . '/' . $path;
            $state[$path] = $status . '|' . (is_file($file) ? filesize($file) . '|' . filemtime($file) : '-|-');
        }

        return $state;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return list<array{path: string, change: string, mtime: ?int}>
     */
    public static function compare(array $before, array $after, string $root): array
    {
        $changes = [];
        foreach ($after as $path => $key) {
            if (!isset($before[$path])) {
                $changes[] = ['path' => $path, 'change' => 'new', 'mtime' => self::mtimeOf($key)];
            } elseif ($before[$path] !== $key) {
                $changes[] = ['path' => $path, 'change' => 'changed', 'mtime' => self::mtimeOf($key)];
            }
        }
        foreach (array_diff_key($before, $after) as $path => $key) {
            // Still on disk = it left the list because it was committed (or reverted to HEAD) meanwhile,
            // not because a harness removed it.
            if (!file_exists($root . '/' . $path)) {
                $changes[] = ['path' => $path, 'change' => 'gone', 'mtime' => null];
            }
        }

        return $changes;
    }

    private static function mtimeOf(string $key): ?int
    {
        $mtime = explode('|', $key)[2] ?? '-';

        return $mtime === '-' ? null : (int) $mtime;
    }
}
