<?php
/**
 * Bends one door (`next` or `current`) on the server at a release — the ONE
 * way to switch, so the two things a hand-typed `ln` forgets cannot be
 * forgotten:
 *
 *   - the `/public` at the end of the link target (target.link_target).
 *     With `link_target: public` a link that stops at `releases/<name>`
 *     makes the release ROOT the document root — vendor/, composer.json and
 *     every signpost into shared/ (credentials, personal data) become URLs.
 *   - the proof after the switch. OPcache binds the door path
 *     (`next/index.php`, handed over unresolved by Apache) to ONE compiled
 *     copy and, with opcache.revalidate_path=0, never calls realpath()
 *     again on a cache hit — the binding has NO TTL, bending the symlink is
 *     invisible to it (measured on cyon 2026-08-30, mechanism proven
 *     2026-08-31: the door on release N served PHP from release N-1, hits
 *     climbing, for an hour). Since 2026-08-31 index.php is a trampoline
 *     (runtime realpath(DOCUMENT_ROOT)): even a stale bound copy boots the
 *     release the door points at, within the realpath cache TTL (~120 s
 *     per worker). The script therefore does NOT reset OPcache — the
 *     account shares ONE cache across ALL its sites, a reset would flush
 *     them all — it reads `X-Z77-Release` off the door until it names the
 *     release, retrying across the TTL. If that never happens, the bound
 *     index.php predates the trampoline: push the current public/index.php
 *     into the RUNNING release once (the mtime change recompiles the
 *     binding in ~2 s), or reset OPcache once by hand.
 *
 * Runs locally, talks to the server over `ssh <target.host>` (the alias from
 * ~/.ssh/config — no password here, ever). Inside target.root only.
 *
 * What it does, in order:
 *   1. refuses a name that does not match target.release_name, a door that is
 *      not next|current, a release without public/index.php
 *   2. writes .releases/htaccess-deny into every shared store that has none —
 *      except the stores served THROUGH public/ (public/media IS shared/media:
 *      a deny file there denies the images, measured 2026-08-30); from those
 *      it REMOVES a deny file, so every switch heals that mistake
 *   3. ln -sfn, then reads the link back and compares (no `touch` — proven
 *      useless 2026-08-30, removed 2026-08-31)
 *   4. `X-Z77-Release` on `/` must name the release (retried across the
 *      realpath-cache TTL; no OPcache reset — trampoline, see above)
 *   5. probes the door's hostname (target.hosts.<door>) from outside: the
 *      site must answer, composer.json and every top-level shared store must
 *      NOT. Steps 4 and 5 are the ones that measure instead of assuming.
 *
 * Usage: php .releases/switch.php <release> <next|current>
 *        php .releases/switch.php 2026-08-30 next
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

$projectRoot = dirname(__DIR__);
$target      = releases_target(__DIR__);

[$release, $door] = releases_switchArgs($argv, $target);

$root       = rtrim((string) $target['root'], '/');
$host       = (string) $target['host'];
$linkTarget = $target['link_target'] === 'public' ? "releases/$release/public" : "releases/$release";
$stores     = []; // every store; the deny file goes only into $closed
$closed     = []; // stores NOT reached through public/ — public/media IS shared/media, a deny file there denies the images
foreach ((array) $target['shared'] as $entry) {
    $store    = releases_sharedStoreName((string) $entry);
    $stores[] = $store;
    if (!str_starts_with(trim((string) $entry, '/'), 'public/')) {
        $closed[] = $store;
    }
}
$stores = array_values(array_unique($stores));
$closed = array_values(array_unique($closed));

$deny = file_get_contents(__DIR__ . '/htaccess-deny');
if ($deny === false || !str_contains($deny, 'Require all denied')) {
    fwrite(STDERR, ".releases/htaccess-deny missing or not the framework file\n");
    exit(1);
}

echo "Switch $door -> $linkTarget on $host:$root\n";

// --- the remote script -------------------------------------------------------
// Sent to `bash -s` over stdin: no shell quoting of our own, every value is
// a bash variable assigned from a single-quoted literal.
$q = static fn(string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";

$script = "set -eu\n"
    . 'ROOT=' . $q($root) . "\n"
    . 'REL='  . $q($release) . "\n"
    . 'DOOR=' . $q($door) . "\n"
    . 'LINK=' . $q($linkTarget) . "\n"
    . 'cd "$ROOT"' . "\n"
    . 'test -f "releases/$REL/public/index.php" || { echo "STOP: releases/$REL/public/index.php not found"; exit 2; }' . "\n"
    . 'test -d shared || { echo "STOP: shared/ not found in $ROOT"; exit 2; }' . "\n"
    . "DENY=\$(cat <<'Z77_DENY_EOF'\n$deny\nZ77_DENY_EOF\n)\n"
    . 'for d in ' . implode(' ', array_map($q, $closed)) . '; do' . "\n"
    . '  if [ -d "shared/$d" ] && [ ! -f "shared/$d/.htaccess" ]; then' . "\n"
    . '    printf "%s\n" "$DENY" > "shared/$d/.htaccess" && echo "  wrote shared/$d/.htaccess"' . "\n"
    . '  fi' . "\n"
    . 'done' . "\n"
    . 'for d in ' . implode(' ', array_map($q, array_values(array_diff($stores, $closed)))) . '; do' . "\n"
    . '  if [ -f "shared/$d/.htaccess" ] && grep -q "Require all denied" "shared/$d/.htaccess"; then' . "\n"
    . '    rm "shared/$d/.htaccess" && echo "  removed deny file from shared/$d (served through public/)"' . "\n"
    . '  fi' . "\n"
    . 'done' . "\n"
    . 'ln -sfn "$LINK" "$DOOR"' . "\n"
    . 'echo "LINK_NOW=$(readlink "$DOOR")"' . "\n";

$out = releases_ssh($host, $script);
echo $out;

if (!preg_match('~^LINK_NOW=(.*)$~m', $out, $m) || trim($m[1]) !== $linkTarget) {
    fwrite(STDERR, "\nSTOP: $door does not read back as '$linkTarget' — check on the server: ls -l $root/$door\n");
    exit(1);
}
echo "  $door -> $linkTarget (verified)\n";

// --- probes from outside -------------------------------------------------------
$hostname = (string) ($target['hosts'][$door] ?? '');
if ($hostname === '') {
    echo "\nNo target.hosts.$door — skipping the outside probes. Add it; they are the only step that measures.\n";
    exit(0);
}

// --- proof: the door must answer from the release --------------------------------
// No OPcache reset: the account shares ONE OPcache across ALL its sites
// (measured 2026-08-31), a reset would flush them all. The trampoline
// index.php resolves the release at runtime, so the switch propagates via
// the realpath cache — up to ~120 s per worker. The loop just watches.
echo "\nProof on https://$hostname (no reset — realpath cache TTL, up to ~2 min)\n";
$proved   = false;
$deadline = time() + 150;
for ($round = 1; ; $round++) {
    $token = bin2hex(random_bytes(8));
    [$status, $headers] = releases_httpHead("https://$hostname/?z77-switch=$token");
    $answered = $headers['x-z77-release'] ?? '(no X-Z77-Release header)';
    printf("  round %d: / answers from %s\n", $round, $answered);
    if ($answered === $release) {
        $proved = true;
        break;
    }
    if (!isset($headers['x-z77-release']) && $round >= 3) {
        fwrite(STDERR, "\nSTOP: no X-Z77-Release header — either an OLD release still answers (its framework predates the header) or this release's framework has no HtmlResponse header. Cannot prove the switch; check the backend panel's hover (Verzeichnis: ...).\n");
        break;
    }
    if (time() >= $deadline) {
        break;
    }
    sleep(10);
}
if (!$proved) {
    fwrite(STDERR, "\nSTOP: $door still does not answer from releases/$release after the realpath TTL.\n"
        . "Most likely the bound index.php predates the trampoline (see the header of this\n"
        . "script). Fix once — copy the trampoline into the release that is still answering:\n"
        . "  scp public/index.php $host:$root/releases/<answering>/public/index.php\n"
        . "then re-run, or bend back:  php .releases/switch.php <previous> $door\n");
    exit(1);
}
echo "  proved: https://$hostname answers from releases/$release\n";

echo "\nProbing https://$hostname\n";

$failed = false;
$probe = static function (string $path, array $expect, string $why) use ($hostname, &$failed): void {
    $code = releases_httpStatus("https://$hostname$path");
    $ok   = in_array($code, $expect, true);
    printf("  %-4s %-28s %s%s\n", $ok ? 'ok' : 'FAIL', $path, $code === 0 ? 'no answer' : $code, $ok ? '' : "  <- $why");
    if (!$ok) {
        $failed = true;
    }
};

$probe('/', [200, 301, 302, 303, 307, 308], 'the site itself does not answer');
$probe('/composer.json', [403, 404], 'the release ROOT is being served');
$probe('/vendor/z77/build.json', [403, 404], 'the release ROOT is being served');
foreach ($closed as $store) {
    // every store that is a signpost in the release root must be unreachable
    $probe("/$store/", [403, 404], 'a shared store is reachable');
}

if ($failed) {
    $prev = '<previous>';
    fwrite(STDERR, "\nSTOP: a probe failed. The door is bent — bend it back before anything else:\n"
        . "  php .releases/switch.php $prev $door\n");
    exit(1);
}
echo "\nOK: $door serves releases/$release, the root and the stores are closed.\n";
echo "Next: backend on $hostname -> «Cache leeren» (see CHECKLIST.md point 4).\n";
