<?php

/**
 * APCu cross-process invalidation harness (CLI) — CACHE-CLI-001.
 *
 * APCu is per process tree: a cron job's apcu_delete() never reaches the
 * FPM pool. The stamp file (var/cache/apcu.stamp) is the only thing both
 * sides see. This harness plays the real scenario, not a simulation: the
 * "web" is this process, the "cron" is a CHILD php process with its own
 * APCu pool that writes (clearAllApcu) and dies.
 *
 * Needs APCu in CLI: php -d apc.enable_cli=1 tests/apcu-stamp.php
 */

require __DIR__ . '/../packages/kernel/core/src/Libraries/Cache/DataCache.php';

use Z77\Core\Libraries\Cache\DataCache;

if (!function_exists('apcu_enabled') || !apcu_enabled()) {
    fwrite(STDERR, "APCu not enabled in CLI — run with: php -d apc.enable_cli=1 tests/apcu-stamp.php\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

$dir   = sys_get_temp_dir() . '/z77-apcu-stamp-' . getmypid();
$stamp = $dir . '/apcu.stamp';
$base  = '/installation/under/test';
mkdir($dir, 0700, true);

/** A fresh web request: new DataCache, boot-time stamp sync. */
function request(string $base, string $stamp): DataCache
{
    $c = new DataCache($base);
    $c->setStampPath($stamp);
    return $c;
}

/** The cron: a separate php process with its own APCu pool. */
function cronWrite(string $base, string $stamp): void
{
    $code = sprintf(
        'require %s; $c = new Z77\Core\Libraries\Cache\DataCache(%s); $c->setStampPath(%s); $c->clearAllApcu();',
        var_export(__DIR__ . '/../packages/kernel/core/src/Libraries/Cache/DataCache.php', true),
        var_export($base, true),
        var_export($stamp, true)
    );
    $cmd = sprintf('%s -d apc.enable_cli=1 -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($code));
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        throw new RuntimeException("cron child failed: " . implode("\n", $out));
    }
}

echo "1. warm pool survives a request boundary without any write\n";
$web = request($base, $stamp);
$web->set('NavigationService', ['all'], ['home', 'about'], cachePersist: true);
$web->flush();
$web2 = request($base, $stamp);
check('value visible in next request', $web2->get('NavigationService', ['all']) === ['home', 'about']);

echo "2. a CLI write in another process invalidates the web pool\n";
cronWrite($base, $stamp);
$web3 = request($base, $stamp);
check('stale index gone after cron write', $web3->get('NavigationService', ['all']) === null);

echo "3. same-second sequence: fill, cron write, read — all within one second\n";
$web4 = request($base, $stamp);
$web4->set('NavigationService', ['all'], ['v2'], cachePersist: true);
$web4->flush();
$before = filemtime($stamp);
cronWrite($base, $stamp);
clearstatcache();
check('stamp advanced strictly (monotonic bump)', filemtime($stamp) > $before);
$web5 = request($base, $stamp);
check('stale index gone despite same-second write', $web5->get('NavigationService', ['all']) === null);

echo "4. web-side write keeps its own pool in sync (no double wipe next request)\n";
$web6 = request($base, $stamp);
$web6->clearAllApcu();
$web6->set('NavigationService', ['all'], ['v3'], cachePersist: true);
$web6->flush();
$web7 = request($base, $stamp);
check('value written after own clear survives', $web7->get('NavigationService', ['all']) === ['v3']);

echo "5. a sibling installation is untouched by the cron write\n";
$other = new DataCache('/some/other/installation');
$other->set('NavigationService', ['all'], ['theirs'], cachePersist: true);
$other->flush();
cronWrite($base, $stamp);
check('other pool prefix keeps its value', (new DataCache('/some/other/installation'))->get('NavigationService', ['all']) === ['theirs']);

// cleanup
(new DataCache($base))->clearAllApcu();
(new DataCache('/some/other/installation'))->clearAllApcu();
@unlink($stamp);
@rmdir($dir);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
