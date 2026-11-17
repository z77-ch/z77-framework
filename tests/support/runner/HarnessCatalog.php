<?php

namespace Z77\Tests\Runner;

/**
 * What the test runner (`tests/run.php`) knows about the harnesses: which exist, which group they
 * belong to (`tests/groups.php`), and which paths each one covers.
 *
 * A harness is every `tests/*.php` and `tests/*.mjs` except the runner's own files. Its coverage
 * is READ FROM ITS SOURCE, never typed twice: the `packages/<name>` paths it names, the namespaces
 * it uses (mapped to a package through the packages' composer.json PSR-4 prefixes), the
 * `tests/support/*.php` files it requires (and what those name in turn) and the `tests/fixtures/<dir>`
 * folders it reads. The map's `covers` adds what a source cannot show (a path built at runtime).
 * Coverage is a list of path prefixes relative to the repository root, so `--changed` is a plain
 * prefix match of the changed paths.
 */
final class HarnessCatalog
{
    /** Files under tests/ that are not harnesses. */
    private const NOT_HARNESSES = ['run.php', 'groups.php'];

    /** @var array<string, string> name => absolute file */
    private array $harnesses = [];

    /** @var array<string, string> namespace prefix (single backslashes, trailing \) => path prefix */
    private array $namespaces = [];

    /** @var array<string, list<string>> */
    private array $coverCache = [];

    /**
     * @param array{settings?: array<string, int>, groups: array<string, list<string>>, covers?: array<string, list<string>>} $map
     */
    public function __construct(private string $root, private array $map)
    {
        $files = array_merge(glob($root . '/tests/*.php') ?: [], glob($root . '/tests/*.mjs') ?: []);
        sort($files);
        foreach ($files as $file) {
            if (in_array(basename($file), self::NOT_HARNESSES, true)) {
                continue;
            }
            $this->harnesses[self::nameOf($file)] = str_replace('\\', '/', $file);
        }
        $this->namespaces = $this->readNamespaces();
    }

    public static function nameOf(string $file): string
    {
        return preg_replace('/\.(php|mjs)$/', '', basename($file));
    }

    /** @return array<string, string> name => absolute file */
    public function harnesses(): array
    {
        return $this->harnesses;
    }

    public function file(string $name): string
    {
        return $this->harnesses[$name];
    }

    /** @return array<string, list<string>> group => harness names (`all` included) */
    public function groups(): array
    {
        return $this->map['groups'] + ['all' => array_keys($this->harnesses)];
    }

    /** @return list<string> the groups a harness is in (without `all`) */
    public function groupsOf(string $name): array
    {
        $in = [];
        foreach ($this->map['groups'] as $group => $members) {
            if (in_array($name, $members, true)) {
                $in[] = $group;
            }
        }

        return $in;
    }

    /** @return list<string> harnesses in no group */
    public function orphans(): array
    {
        return array_values(array_filter(array_keys($this->harnesses), fn(string $n) => $this->groupsOf($n) === []));
    }

    /** @return list<string> "group: name" entries the map names but tests/ does not have */
    public function unknownMembers(): array
    {
        $unknown = [];
        foreach ($this->map['groups'] as $group => $members) {
            foreach ($members as $member) {
                if (!isset($this->harnesses[$member])) {
                    $unknown[] = "{$group}: {$member}";
                }
            }
        }
        foreach (array_keys($this->map['covers'] ?? []) as $member) {
            if (!isset($this->harnesses[$member])) {
                $unknown[] = "covers: {$member}";
            }
        }

        return $unknown;
    }

    /**
     * Resolves the command line's words (group names, harness names with or without `.php` /
     * `.mjs`, `tests/` prefix allowed) into harness names, in the order given, without duplicates.
     *
     * @param list<string> $words
     * @return list<string>
     * @throws \InvalidArgumentException for a word that is neither a group nor a harness
     */
    public function resolve(array $words): array
    {
        $groups   = $this->groups();
        $selected = [];
        foreach ($words as $word) {
            $name = self::nameOf(preg_replace('#^tests[/\\\\]#', '', $word));
            if (isset($groups[$word])) {
                array_push($selected, ...$groups[$word]);
            } elseif (isset($this->harnesses[$name])) {
                $selected[] = $name;
            } else {
                throw new \InvalidArgumentException("«{$word}» is neither a group (" . implode(', ', array_keys($groups)) . ') nor a harness in tests/');
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * The path prefixes a harness covers, read from its source (see the class comment).
     *
     * @return list<string>
     */
    public function covers(string $name): array
    {
        if (isset($this->coverCache[$name])) {
            return $this->coverCache[$name];
        }
        $file   = $this->harnesses[$name];
        $source = (string) file_get_contents($file);
        $paths  = ['tests/' . basename($file)];
        $paths  = array_merge($paths, $this->pathsNamedIn($source));

        preg_match_all('#support/([A-Za-z0-9_.-]+\.(?:php|mjs))#', $source, $m);
        foreach (array_unique($m[1]) as $support) {
            $supportFile = $this->root . '/tests/support/' . $support;
            if (is_file($supportFile)) {
                $paths[] = 'tests/support/' . $support;
                $paths   = array_merge($paths, $this->pathsNamedIn((string) file_get_contents($supportFile)));
            }
        }
        preg_match_all('#fixtures/([A-Za-z0-9_-]+)#', $source, $m);
        foreach (array_unique($m[1]) as $fixture) {
            if (is_dir($this->root . '/tests/fixtures/' . $fixture)) {
                $paths[] = 'tests/fixtures/' . $fixture;
            }
        }
        foreach ($this->map['covers'][$name] ?? [] as $extra) {
            $paths[] = $extra;
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $this->coverCache[$name] = $paths;
    }

    /**
     * The harnesses whose coverage contains one of the changed paths.
     *
     * @param list<string> $changed repository-relative paths, forward slashes
     * @return array{0: array<string, list<string>>, 1: list<string>} [name => the changed paths that picked it, changed paths no harness covers]
     */
    public function touchedBy(array $changed): array
    {
        $picked    = [];
        $uncovered = [];
        foreach ($changed as $path) {
            // A README or other Markdown in a package is documentation, not code a harness runs.
            if (str_ends_with(strtolower($path), '.md')) {
                $uncovered[] = $path;
                continue;
            }
            $hit = false;
            foreach (array_keys($this->harnesses) as $name) {
                foreach ($this->covers($name) as $prefix) {
                    if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                        $picked[$name][] = $path;
                        $hit             = true;
                        break;
                    }
                }
            }
            if (!$hit) {
                $uncovered[] = $path;
            }
        }

        return [$picked, $uncovered];
    }

    /** @return list<string> package path prefixes a source names by path or by namespace */
    private function pathsNamedIn(string $source): array
    {
        $paths = [];
        preg_match_all('#packages/(kernel/[a-z0-9_-]+|[a-z0-9][a-z0-9_-]*)#', $source, $m);
        foreach (array_unique($m[1]) as $unit) {
            if (is_dir($this->root . '/packages/' . $unit)) {
                $paths[] = 'packages/' . $unit;
            }
        }
        // Repository tooling outside packages/ that a harness requires or runs (deploy scripts, JS build).
        preg_match_all('#(?<![A-Za-z0-9_])(\.releases|scripts)/[A-Za-z0-9_.-]+\.(?:php|mjs|js|json)#', $source, $m);
        foreach (array_unique($m[0]) as $tool) {
            if (is_file($this->root . '/' . $tool)) {
                $paths[] = $tool;
            }
        }
        preg_match_all('#Z77(?:\\\\{1,2}[A-Za-z0-9_]+)+#', $source, $m);
        foreach (array_unique($m[0]) as $reference) {
            $class = str_replace('\\\\', '\\', $reference) . '\\';
            foreach ($this->namespaces as $prefix => $path) {
                if (str_starts_with($class, $prefix)) {
                    $paths[] = $path;
                    break;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Namespace prefix => package path, from every package's composer.json `autoload.psr-4`,
     * longest prefix first. `src/` maps to the package itself; `core/src/` in packages/kernel to
     * `packages/kernel/core`.
     *
     * @return array<string, string>
     */
    private function readNamespaces(): array
    {
        $map = [];
        foreach (glob($this->root . '/packages/*/composer.json') ?: [] as $composer) {
            $package = 'packages/' . basename(dirname($composer));
            $json    = json_decode((string) file_get_contents($composer), true);
            foreach ($json['autoload']['psr-4'] ?? [] as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    $first        = explode('/', trim($dir, '/'))[0];
                    $map[$prefix] = $first === 'src' || $first === '' ? $package : $package . '/' . $first;
                }
            }
        }
        uksort($map, fn(string $a, string $b) => strlen($b) <=> strlen($a));

        return $map;
    }
}
