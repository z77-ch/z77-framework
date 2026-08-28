<?php

/**
 * Country blocklist harness (CLI) — the gate's read side and the backend's
 * write side against a real file store.
 *
 * What is load-bearing here is the FAIL-OPEN promise: `CountryBlocklist::codes()`
 * sits in the path of a public registration form, so every broken state — no
 * data directory, an unreadable file, a corrupt line — has to answer «no
 * countries blocked» rather than throw or, worse, block everyone. The other
 * half is that the write side does NOT swallow: a backend save that fails must
 * be visible.
 *
 * Run: php tests/country-blocklist.php
 * Uses a throwaway data directory in the system temp; removed on success.
 */

$work = sys_get_temp_dir() . '/z77-country-blocklist-' . getmypid();
@mkdir($work . '/data/framework/forms', 0777, true);
define('ABS_BASE_PATH', $work);

// Minimal PSR-4 autoloader over the packages — the harnesses run without a
// composer install (see tests/file-storage-atomicity.php).
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'          => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'        => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\'   => __DIR__ . '/../packages/kernel/persistence/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Shared\Entities\BlockedCountry;
use Z77\Shared\Forms\CountryBlocklist;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

// The file entity manager asks the container for the cache; nothing else in
// this harness touches DI.
DI::getInstance()->set('CacheManager', new CacheManager(), true);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

function blocklist(): CountryBlocklist
{
    return new CountryBlocklist(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])));
}

$file = $work . '/data/framework/forms/blocked-countries.json';

echo "1. code normalization (the entity owns it, so every path compares equal)\n";
check("'ru' becomes 'RU'",            BlockedCountry::normalizeCode('ru') === 'RU');
check("' cn ' becomes 'CN'",          BlockedCountry::normalizeCode(' cn ') === 'CN');
check('three letters are refused',    BlockedCountry::normalizeCode('CHE') === '');
check('a digit pair is refused',      BlockedCountry::normalizeCode('12') === '');
check('empty stays empty',            BlockedCountry::normalizeCode('') === '');

echo "2. the rule is OFF on a fresh installation\n";
check('no file yet → no codes', CountryBlocklist::codes() === []);
check('no file yet → empty list', blocklist()->all() === []);

echo "3. blocking\n";
$entry = blocklist()->block('ru', '340 Versuche, davon 0 angenommen.', 'peter');
check('block() returns the entry',        $entry instanceof BlockedCountry);
check('the code was normalized',          $entry?->getCode() === 'RU');
check('the reason was kept',              $entry?->getReason() === '340 Versuche, davon 0 angenommen.');
check('the operator was recorded',        $entry?->getAddedBy() === 'peter');
check('addedAt is an ATOM timestamp',     (bool) strtotime($entry?->getAddedAt() ?? ''));
check('the store file exists',            is_file($file));
check('codes() sees it',                  CountryBlocklist::codes() === ['RU']);
check('has() sees it, case-insensitively', blocklist()->has('ru'));

echo "4. no second entry for the same country (else «aufheben» is ambiguous)\n";
check('a duplicate is refused',   blocklist()->block('RU', 'noch ein Grund') === null);
check('a lower-case duplicate too', blocklist()->block('ru', 'noch ein Grund') === null);
check('still exactly one entry',  count(blocklist()->all()) === 1);

echo "5. a non-code is never written\n";
check('three letters are refused', blocklist()->block('CHE', 'Grund') === null);
check('empty is refused',          blocklist()->block('', 'Grund') === null);
check('still exactly one entry',   count(blocklist()->all()) === 1);

echo "6. a second country, and the list order a human scans\n";
blocklist()->block('CN', 'Zweiter Grund.');
check('both codes are read back', CountryBlocklist::codes() === ['CN', 'RU'] || CountryBlocklist::codes() === ['RU', 'CN']);
check('all() is sorted by code',  array_map(
    static fn(BlockedCountry $e): string => $e->getCode(),
    blocklist()->all(),
) === ['CN', 'RU']);

echo "7. unblocking\n";
check('unblock() reports the removal', blocklist()->unblock('ru') === true);
check('the entry is gone',             blocklist()->has('RU') === false);
check('the other one survived',        CountryBlocklist::codes() === ['CN']);
check('a second unblock reports false', blocklist()->unblock('RU') === false);
check('an unknown code reports false',  blocklist()->unblock('ZZ') === false);
check('a non-code reports false',       blocklist()->unblock('nonsense') === false);

echo "8. ⚠️ fail OPEN — a broken store must not bar a single customer\n";
file_put_contents($file, 'das ist kein JSON');
check('a corrupt file answers «nothing blocked»', CountryBlocklist::codes() === []);

file_put_contents($file, '');
check('an empty file answers «nothing blocked»', CountryBlocklist::codes() === []);

// The whole data directory gone is the case of an installation that never had
// one — the gate has to behave as if the rule were simply switched off.
// (The store leaves a lock file beside the data, so the directory is emptied
// rather than just the one file.)
array_map('unlink', glob($work . '/data/framework/forms/*') ?: []);
check('directory emptied for the next check', rmdir($work . '/data/framework/forms'));
check('no directory answers «nothing blocked»', CountryBlocklist::codes() === []);

echo "\n";
echo $fail === 0
    ? "PASS — {$pass} checks\n"
    : "FAIL — {$fail} of " . ($pass + $fail) . " checks failed\n";

if ($fail === 0) {
    // Only on success: a failed run leaves the evidence in place.
    array_map('unlink', glob($work . '/data/framework/forms/*') ?: []);
    @rmdir($work . '/data/framework/forms');
    @rmdir($work . '/data/framework');
    @rmdir($work . '/data');
    @rmdir($work);
} else {
    echo "Arbeitsverzeichnis bleibt stehen: {$work}\n";
}

exit($fail === 0 ? 0 : 1);
