<?php

use Doctrine\DBAL\Connection;

/**
 * A section checkpoint for the long DB harnesses (owner decision 2026-10-23): the state a
 * section starts from — the throwaway MariaDB schema, the temp installation `$base` and the
 * scalar PHP variables the section reads — saved by a full run at the section's boundary and
 * restored by `php tests/<harness>.php --from=<SECTION>`, which then runs ONLY that section.
 *
 * Where: `sys_get_temp_dir()/z77-harness-checkpoints/<harness>/<SECTION>/` — never in the
 * repository. `state.ser` holds the tables (CREATE TABLE + rows), the variables and what the
 * saving run was (base path, schema name, repository root, the fingerprint); `base/` is the copy
 * of the installation without `var/cache` (a section rebuilds its cache).
 *
 * The restore is exact up to three strings the saving run left in files and rows: its base
 * path, its schema name and its repository root are replaced by the restoring run's.
 *
 * The fingerprint is the md5 of the harness source BEFORE the section's label: when the code of
 * the sections before it changed, the restore warns (the state may no longer be what a full run
 * would leave) — a full run refreshes it. Changed PACKAGE code is not detected; rerun the full
 * harness when the sections before the checkpoint depend on what you changed.
 *
 * A harness uses it in three places: `requested()` before it creates anything (an unknown
 * section exits early), `save()` at the section boundary of a full run, and `restore()` + a
 * `goto section_<SECTION>;` right after its shared helpers in a `--from` run.
 */
final class HarnessCheckpoint
{
    /** @param list<string> $sections the sections this harness can start from */
    public function __construct(private string $harness, private string $sourceFile, private array $sections)
    {
    }

    /** The section `--from=<SECTION>` names, null for a full run; an unknown one ends the run (exit 2). */
    public function requested(array $argv): ?string
    {
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--from=')) {
                $section = substr($arg, 7);
                if (!in_array($section, $this->sections, true)) {
                    fwrite(STDERR, "{$this->harness}: no checkpoint section «{$section}» — known: " . implode(', ', $this->sections) . "\n");
                    exit(2);
                }

                return $section;
            }
        }

        return null;
    }

    public function dir(string $section): string
    {
        return str_replace('\\', '/', sys_get_temp_dir()) . "/z77-harness-checkpoints/{$this->harness}/{$section}";
    }

    /** @param array<string, mixed> $vars scalars and arrays of scalars only (no entities, no closures) */
    public function save(string $section, Connection $db, string $base, array $vars): void
    {
        $started = microtime(true);
        $dir     = $this->dir($section);
        self::remove($dir);
        @mkdir($dir . '/base', 0777, true);
        self::copy($base, $dir . '/base', ['var/cache']);

        $tables = [];
        $names  = $db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        foreach ($names as $table) {
            $columns = $db->fetchFirstColumn("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION", [$table]);
            $list    = implode(', ', array_map(fn(string $c) => "`{$c}`", $columns));
            $tables[$table] = [
                'create'  => $db->fetchAssociative("SHOW CREATE TABLE `{$table}`")['Create Table'],
                'columns' => $columns,
                'rows'    => $db->fetchAllNumeric("SELECT {$list} FROM `{$table}`"),
            ];
        }
        file_put_contents($dir . '/state.ser', serialize([
            'fingerprint' => $this->fingerprint($section),
            'saved'       => date('Y-m-d H:i:s'),
            'base'        => $base,
            'schema'      => (string) $db->fetchOne('SELECT DATABASE()'),
            'root'        => self::root(),
            'vars'        => $vars,
            'tables'      => $tables,
        ]));
        printf("  (checkpoint %s saved in %.1f s: %s — `php tests/%s.php --from=%s` runs that section alone)\n", $section, microtime(true) - $started, $dir, $this->harness, $section);
    }

    /**
     * Restores the checkpoint into the EMPTY schema of $db and the fresh installation $base
     * (its files replaced); returns the saved variables. Missing → message and exit 2.
     *
     * @return array<string, mixed>
     */
    public function restore(string $section, Connection $db, string $base): array
    {
        $dir = $this->dir($section);
        if (!is_file($dir . '/state.ser')) {
            fwrite(STDERR, "{$this->harness}: no checkpoint {$section} in {$dir} — run the full harness once (`php tests/{$this->harness}.php`), it saves the checkpoint at the section boundary.\n");
            exit(2);
        }
        $started = microtime(true);
        $state   = unserialize((string) file_get_contents($dir . '/state.ser'));
        if ($state['fingerprint'] !== $this->fingerprint($section)) {
            fwrite(STDERR, "{$this->harness}: WARNING — checkpoint {$section} ({$state['saved']}) was saved by another version of the sections before {$section}; run the full harness to refresh it.\n");
        }
        $root    = self::root();
        $replace = [
            $state['base']                          => $base,
            $state['schema']                        => (string) $db->fetchOne('SELECT DATABASE()'),
            $state['root']                          => $root,
            str_replace('/', '\\', $state['root'])  => str_replace('/', '\\', $root),
        ];
        $swap = static fn(mixed $v): mixed => is_string($v) ? strtr($v, $replace) : $v;

        self::remove($base);
        @mkdir($base, 0777, true);
        self::copy($dir . '/base', $base, [], $swap);

        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($state['tables'] as $table => $t) {
            $db->executeStatement($t['create']);
            $list = implode(', ', array_map(fn(string $c) => "`{$c}`", $t['columns']));
            $one  = '(' . implode(', ', array_fill(0, count($t['columns']), '?')) . ')';
            foreach (array_chunk($t['rows'], 200) as $chunk) {
                $params = [];
                foreach ($chunk as $row) {
                    array_push($params, ...array_map($swap, $row));
                }
                $db->executeStatement("INSERT INTO `{$table}` ({$list}) VALUES " . implode(', ', array_fill(0, count($chunk), $one)), $params);
            }
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $vars = $state['vars'];
        array_walk_recursive($vars, static function (mixed &$v) use ($swap): void { $v = $swap($v); });
        printf("  (checkpoint %s of %s restored in %.1f s: %d tables)\n", $section, $state['saved'], microtime(true) - $started, count($state['tables']));

        return $vars;
    }

    /** The harness source before `section_<SECTION>:` — what the saved state was produced by. */
    private function fingerprint(string $section): string
    {
        $source = (string) file_get_contents($this->sourceFile);
        $pos    = strpos($source, "\nsection_{$section}:");

        return md5($pos === false ? $source : substr($source, 0, $pos));
    }

    private static function root(): string
    {
        return str_replace('\\', '/', (string) realpath(__DIR__ . '/../..'));
    }

    /** @param list<string> $skip paths relative to $from */
    private static function copy(string $from, string $to, array $skip = [], ?callable $swap = null, string $rel = ''): void
    {
        foreach (scandir($from . ($rel === '' ? '' : '/' . $rel)) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $rel === '' ? $name : $rel . '/' . $name;
            if (in_array($path, $skip, true)) {
                continue;
            }
            if (is_dir($from . '/' . $path)) {
                @mkdir($to . '/' . $path, 0777, true);
                self::copy($from, $to, $skip, $swap, $path);
            } elseif ($swap === null) {
                copy($from . '/' . $path, $to . '/' . $path);
            } else {
                file_put_contents($to . '/' . $path, $swap((string) file_get_contents($from . '/' . $path)));
            }
        }
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($dir . '/' . $name) && !is_link($dir . '/' . $name) ? self::remove($dir . '/' . $name) : @unlink($dir . '/' . $name);
        }
        @rmdir($dir);
    }
}
