<?php
/**
 * Uploads ONE release to the server and sets its signposts into shared/ —
 * the whole "upload" step of the deploy sequence as one command, so nothing
 * in it is typed by hand: not the target directory, not the exclusions, not
 * the nine `ln -s` lines, not the check that shared/ holds every store.
 *
 *   php .releases/deploy.php <release>              e.g. 2026-08-30
 *   php .releases/deploy.php <release> --replace    re-upload into a release
 *                                                   no door points at
 *
 * What it does, in order:
 *   1. refuses: a name outside target.release_name; vendor/ still on links,
 *      without stamp or with dev deps (run vendor-deploy.php first); a
 *      missing root .htaccess with link_target=public
 *   2. packs target.release_dirs + composer.json/.lock + .htaccess into a tar
 *      (PharData, one pass, no external tool) — every target.shared entry excluded, so
 *      an upload can never carry a shared name (rule 6)
 *   3. streams the tar over `ssh <target.host>` into releases/<name>/ —
 *      refuses if that directory exists (a second upload into a RUNNING
 *      release is the thing to prevent); --replace removes it first, and
 *      only if neither door points at it (rule 9)
 *   4. on the server: mkdir -p shared/<store> for every store, the deny file
 *      into each that lacks one (never into a store served through public/ —
 *      public/media IS shared/media), then one relative symlink per target.shared
 *      entry (`data -> ../../shared/data`, `public/media -> ../../../shared/media`)
 *   5. compares config/fileFinder.inc.php with shared/config/ — the one file
 *      `composer install` regenerates and no upload carries (CHECKLIST 1)
 *
 * Never touches current or next: that is switch.php. Inside target.root only.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$projectRoot = dirname(__DIR__);
$target      = releases_target(__DIR__);

$release = (string) ($argv[1] ?? '');
$replace = in_array('--replace', $argv, true);
if ($release === '' || str_starts_with($release, '--')) {
    fwrite(STDERR, "Usage: php .releases/deploy.php <release> [--replace]\n");
    exit(1);
}
if (!preg_match('~' . $target['release_name'] . '~', $release) || str_contains($release, '/') || str_contains($release, '..')) {
    fwrite(STDERR, "release name '$release' does not match target.release_name '{$target['release_name']}'\n");
    exit(1);
}

$root = $target['root'];
$host = $target['host'];
echo "Deploy $release -> $host:$root/releases/$release\n";

// --- 1. local state --------------------------------------------------------------
$bad = [];
foreach (releases_pathRepos($projectRoot) as $repo) {
    $dir = $projectRoot . '/vendor/' . $repo['name'];
    if (releases_isLink($dir)) {
        $bad[] = "vendor/{$repo['name']} is a link — run vendor-deploy.php";
    } elseif (!is_dir($dir)) {
        $bad[] = "vendor/{$repo['name']} missing — run vendor-deploy.php";
    }
}
if (!is_file($projectRoot . '/vendor/z77/build.json')) {
    $bad[] = 'vendor/z77/build.json missing — run vendor-deploy.php';
}
$installed = json_decode((string) @file_get_contents($projectRoot . '/vendor/composer/installed.json'), true);
if (is_array($installed) && ($installed['dev'] ?? null) === true) {
    $bad[] = 'vendor/ holds dev dependencies — run vendor-deploy.php';
}
$rootHtaccess = $projectRoot . '/.htaccess';
$rootDenies   = is_file($rootHtaccess) && str_contains((string) file_get_contents($rootHtaccess), 'Require all denied');
if ($target['link_target'] === 'public' && !$rootDenies) {
    $bad[] = '.htaccess in the project root missing or not the deny file — copy .releases/htaccess-deny there';
}
if ($target['link_target'] === 'release' && $rootDenies) {
    $bad[] = '.htaccess in the project root denies everything — with link_target=release that closes the whole site';
}
foreach ($target['release_dirs'] as $dir) {
    if (!is_dir("$projectRoot/$dir")) {
        $bad[] = "release_dirs: '$dir' does not exist locally";
    }
}
if ($bad !== []) {
    fwrite(STDERR, "STOP — not deployable:\n");
    foreach ($bad as $b) {
        fwrite(STDERR, "  - $b\n");
    }
    exit(1);
}

// --- 2. pack ---------------------------------------------------------------------
$shared   = $target['shared'];
$excluded = static function (string $rel) use ($shared): bool {
    foreach ($shared as $entry) {
        $entry = trim($entry, '/');
        if ($rel === $entry || str_starts_with($rel, $entry . '/')) {
            return true;
        }
    }
    return false;
};

$tarFile = sys_get_temp_dir() . '/z77-release-' . $release . '-' . getmypid() . '.tar';
@unlink($tarFile);
echo "Packing: " . implode(', ', $target['release_dirs']) . ", composer.json, composer.lock, .htaccess\n";
echo "Excluding every shared name: " . implode(', ', $shared) . "\n";

// Collect first, build once. PharData::addFile() rewrites the whole archive
// on every call — quadratic in the file count, and a virus scanner on the
// temp file turns 1167 files into minutes (measured 2026-08-30: two runs
// aborted at 4 MB and 2.9 MB). buildFromIterator() is a single pass.
$files = [];   // rel => abs
$bytes = 0;
foreach ($target['release_dirs'] as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator("$projectRoot/$dir", FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f) use ($projectRoot, $excluded): bool {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($projectRoot) + 1));
                return !$excluded($rel) && !in_array($f->getFilename(), ['.git', 'node_modules', '.claude', '.vscode'], true);
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($projectRoot) + 1));
        $files[$rel] = $f->getPathname();
        $bytes += (int) $f->getSize();
    }
}
foreach (['composer.json', 'composer.lock', '.htaccess'] as $file) {
    if (is_file("$projectRoot/$file")) {
        $files[$file] = "$projectRoot/$file";
        $bytes += (int) filesize("$projectRoot/$file");
    }
}
printf("  %d files, %.1f MB — building tar ...", count($files), $bytes / 1048576);
$t0  = microtime(true);
$tar = new PharData($tarFile);
$tar->buildFromIterator(new ArrayIterator($files));
unset($tar);
printf(" %.1f MB in %.0f s\n", filesize($tarFile) / 1048576, microtime(true) - $t0);

// --- 3. upload ---------------------------------------------------------------------
$q = static fn(string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";

$remote = "set -eu\n"
    . 'ROOT=' . $q($root) . "\n"
    . 'REL='  . $q($release) . "\n"
    . 'cd "$ROOT"' . "\n"
    . 'test -d shared || { echo "STOP: $ROOT/shared does not exist — first-install layout is a manual step (rule 10)"; exit 2; }' . "\n"
    . 'for door in current next; do' . "\n"
    . '  if [ -L "$door" ] && [ "$(readlink "$door" | cut -d/ -f1-2)" = "releases/$REL" ]; then' . "\n"
    . '    echo "STOP: $door points at releases/$REL — never upload into a running release (rule 7)"; exit 3' . "\n"
    . '  fi' . "\n"
    . 'done' . "\n"
    . 'if [ -e "releases/$REL" ]; then' . "\n"
    . ($replace
        ? '  echo "  --replace: removing releases/$REL"; rm -rf "releases/$REL"' . "\n"
        : '  echo "STOP: releases/$REL already exists — a second upload into it is the mistake this script prevents; pass --replace to remove and re-upload (no door points at it)"; exit 3' . "\n")
    . 'fi' . "\n"
    . 'mkdir -p "releases/$REL"' . "\n"
    . 'tar -xf - -C "releases/$REL"' . "\n"
    . 'echo "  extracted: $(find "releases/$REL" -type f | wc -l) files"' . "\n";

echo "Uploading over ssh $host ...\n";
$out = releases_sshStdin($host, $remote, $tarFile);
@unlink($tarFile);
echo $out;
if (str_contains($out, 'STOP:')) {
    exit(3);
}

// --- 4. signposts + 5. config compare ---------------------------------------------
$deny = (string) file_get_contents(__DIR__ . '/htaccess-deny');

// LEGACY layout only (target.shared still carries `config` as a whole): the
// installer-regenerated files live in shared and no upload carries them, so
// they must be hand-compared — BOTH of them, not just fileFinder: moduleManager
// is the module register, and a module deployed without its register entry is
// a silent 404 on every route of that module. Under the ADR-036 split
// (`config/client` shared, `config/vendor` rides with the release) this whole
// compare is obsolete and skipped.
$legacyConfigLayout = in_array('config', array_map(static fn($e) => trim((string) $e, '/'), $shared), true);
$generatedConfigs   = [];
if ($legacyConfigLayout) {
    foreach (['fileFinder.inc.php', 'moduleManager.inc.php', 'bootstrap.inc.php'] as $cfg) {
        // The local file may already live in the split location.
        foreach (["config/vendor/$cfg", "config/$cfg"] as $localRel) {
            if (is_file("$projectRoot/$localRel")) {
                $generatedConfigs[$cfg] = ['md5' => md5_file("$projectRoot/$localRel"), 'localRel' => $localRel];
                break;
            }
        }
    }
}

$links = '';
foreach ($shared as $entry) {
    $entry = trim($entry, '/');
    $store = releases_sharedStoreName($entry);
    $up    = str_repeat('../', substr_count($entry, '/') + 2);
    // A store reached THROUGH public/ (public/media -> shared/media) is the
    // same directory as its link: a deny file inside it would deny the
    // images. Measured 2026-08-30 on axo3.ch — every image 403 on BOTH
    // doors, because shared/ is common to all releases.
    $served = str_starts_with($entry, 'public/') ? '1' : '0';
    $links .= 'link ' . $q($entry) . ' ' . $q($up . 'shared/' . $store) . ' ' . $q($store) . ' ' . $served . "\n";
}

$remote2 = "set -eu\n"
    . 'ROOT=' . $q($root) . "\n"
    . 'REL='  . $q($release) . "\n"
    . 'cd "$ROOT"' . "\n"
    . "DENY=\$(cat <<'Z77_DENY_EOF'\n$deny\nZ77_DENY_EOF\n)\n"
    . 'link() { # <entry in release> <relative target> <store name> <served through public/: 1|0>' . "\n"
    . '  mkdir -p "shared/$3"' . "\n"
    . '  if [ "$4" = 1 ]; then' . "\n"
    . '    if [ -f "shared/$3/.htaccess" ] && grep -q "Require all denied" "shared/$3/.htaccess"; then rm "shared/$3/.htaccess"; echo "  removed deny file from shared/$3 (served through public/)"; fi' . "\n"
    . '  elif [ ! -f "shared/$3/.htaccess" ]; then printf "%s\n" "$DENY" > "shared/$3/.htaccess"; echo "  wrote shared/$3/.htaccess"; fi' . "\n"
    . '  p="releases/$REL/$1"' . "\n"
    // The parent must exist before ln. For `public/media` it always did — public/
    // arrives with the upload. `var/lib` (ADR-035) has no parent in the artifact:
    // var/ is runtime state, so nothing uploads it and `ln -s` would fail with
    // "No such file or directory".
    . '  mkdir -p "$(dirname "$p")"' . "\n"
    . '  if [ -L "$p" ]; then rm "$p"' . "\n"
    . '  elif [ -d "$p" ]; then rmdir "$p" 2>/dev/null || { echo "STOP: $p is a real, non-empty directory — the upload carried a shared name (rule 6)"; exit 4; }' . "\n"
    . '  elif [ -e "$p" ]; then echo "STOP: $p exists and is not a directory"; exit 4; fi' . "\n"
    . '  ln -s "$2" "$p"' . "\n"
    . '  printf "  %-22s -> %s\n" "$1" "$(readlink "$p")"' . "\n"
    . '}' . "\n"
    . $links
    // Release-local runtime dirs (ADR-035). They create themselves on first write,
    // but making them here means the very first request does not have to — and, more
    // to the point, it makes the layout visible on the server: `var/cache` and
    // `var/state` are real directories inside the release, `var/lib` is a signpost.
    // Anyone reading `ls -la releases/<name>/var` sees which is which.
    . 'mkdir -p "releases/$REL/var/cache" "releases/$REL/var/state"' . "\n"
    . 'printf "  %-22s -> %s\n" "var/cache" "(release-local)"' . "\n"
    . 'printf "  %-22s -> %s\n" "var/state" "(release-local, DEBUG / noindex)"' . "\n"
    . 'echo' . "\n";
foreach ($generatedConfigs as $cfg => $info) {
    $remote2 .= 'if [ -f shared/config/' . $cfg . ' ]; then' . "\n"
        . '  if [ "$(md5sum < shared/config/' . $cfg . ' | cut -d" " -f1)" = ' . $q($info['md5']) . ' ]; then' . "\n"
        . '    echo "  shared/config/' . $cfg . ': same as local"' . "\n"
        . '  else' . "\n"
        . '    echo "  ATTENTION shared/config/' . $cfg . ' DIFFERS from local ' . $info['localRel'] . ' — composer install regenerated it; copy by hand (CHECKLIST 1):"' . "\n"
        . '    echo "    scp ' . $info['localRel'] . ' ' . $host . ':' . $root . '/shared/config/' . $cfg . '"' . "\n"
        . '  fi' . "\n"
        . 'fi' . "\n";
}

echo "Signposts:\n";
$out = releases_ssh($host, $remote2);
echo $out;
if (str_contains($out, 'STOP:')) {
    exit(4);
}

echo "\nOK: releases/$release is complete. No door points at it yet.\n";
echo "Next: php .releases/switch.php $release next\n";
