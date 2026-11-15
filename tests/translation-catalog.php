<?php

/**
 * TranslationCatalog writes under one exclusive catalog lock (CLI, offline, temp ABS_BASE_PATH) —
 * translation.md TRANS-CATALOG-LOCK-001. What is load-bearing:
 *
 *   (A) a save is still a plain upsert: default language keeps the key, an empty non-default
 *       value drops it, an add onto an existing key is refused, a rename moves the key;
 *   (B) real parallel saves of DIFFERENT keys by several processes all survive — without the
 *       lock two processes read the same {lang}.json and the later write dropped the other key;
 *   (C) the lock is FileStorage's own (`data/framework/i18n/catalog.lock`), not a new mechanism.
 *
 * Run: php tests/translation-catalog.php
 */

use Z77\Core\Libraries\Cache\PageCache;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Services\I18n;
use Z77\Core\Services\TranslationCatalog;
use Z77\Persistence\File\Storage\FileStorage;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "translation-catalog: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$worker = ($argv[1] ?? '') === '--worker';
$base   = $worker ? $argv[2] : str_replace('\\', '/', sys_get_temp_dir()) . '/z77-translation-catalog-' . bin2hex(random_bytes(4));
if (!$worker) {
    mkdir($base . '/data', 0777, true);
}
define('ABS_BASE_PATH', $base);
require $autoload;

/** I18n without a ConfigManager: two languages, de default. */
final class CatalogTestI18n extends I18n
{
    public function __construct() {}
    public function getDefaultLanguage(): string { return 'de'; }
    public function getLanguages(): array { return ['de', 'fr']; }
}

/** The page cache is not under test — clearing it is a no-op. */
final class CatalogTestCache extends CacheManager
{
    public function __construct() {}
    public function page(): PageCache
    {
        return new class extends PageCache {
            public function clearAll(): void {}
        };
    }
}

function catalog(): TranslationCatalog
{
    return new TranslationCatalog(new FileStorage(), new CatalogTestI18n(), new CatalogTestCache());
}

// Worker: wait for the common start, then save its keys one by one.
if ($worker) {
    $catalog = catalog();
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    for ($n = 0; $n < 8; $n++) {
        $catalog->saveUiEntry("race.w{$argv[3]}k{$n}", ['de' => "W{$argv[3]} {$n}", 'fr' => "F{$argv[3]} {$n}"]);
    }
    exit(0);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — got: {$got}" : '') . "\n"; }
}

$storage = new FileStorage();
$catalog = catalog();

// ── (A) upsert semantics ─────────────────────────────────────────────────────
echo "(A) upsert\n";
check('save a new key', $catalog->saveUiEntry('nav.home', ['de' => 'Start', 'fr' => 'Accueil']) === []);
check('…in every language', $catalog->uiEntry('nav.home') === ['de' => 'Start', 'fr' => 'Accueil']);
check('add onto an existing key is refused', $catalog->saveUiEntry('nav.home', ['de' => 'X']) !== []
    && $catalog->uiEntry('nav.home')['de'] === 'Start');
check('empty non-default value drops the key there', $catalog->saveUiEntry('nav.home', ['de' => 'Start', 'fr' => ''], 'nav.home') === []
    && !array_key_exists('nav.home', $storage->load('framework/i18n/fr.json'))
    && $storage->load('framework/i18n/de.json')['nav.home'] === 'Start');
check('rename moves the key', $catalog->saveUiEntry('nav.start', ['de' => 'Start'], 'nav.home') === []
    && !array_key_exists('nav.home', $storage->load('framework/i18n/de.json'))
    && $storage->load('framework/i18n/de.json')['nav.start'] === 'Start');
$catalog->deleteUiEntry('nav.start');
check('delete removes it', !array_key_exists('nav.start', $storage->load('framework/i18n/de.json')));
check('(C) the lock file is FileStorage\'s', is_file($base . '/data/framework/i18n/catalog.lock'));

// ── (B) real parallel saves ──────────────────────────────────────────────────
echo "(B) parallel\n";
$workers = 4;
$start   = microtime(true) + 1.0;
$procs   = [];
$pipes   = [];
for ($i = 0; $i < $workers; $i++) {
    $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--worker', $base, (string) $i, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$errors = '';
foreach ($procs as $i => $proc) {
    stream_get_contents($pipes[$i][1]);
    $errors .= trim((string) stream_get_contents($pipes[$i][2]));
    proc_close($proc);
}
check('workers ran without error', $errors === '', $errors);
$de      = $storage->load('framework/i18n/de.json');
$fr      = $storage->load('framework/i18n/fr.json');
$missing = [];
for ($i = 0; $i < $workers; $i++) {
    for ($n = 0; $n < 8; $n++) {
        if (($de["race.w{$i}k{$n}"] ?? null) !== "W{$i} {$n}" || ($fr["race.w{$i}k{$n}"] ?? null) !== "F{$i} {$n}") {
            $missing[] = "w{$i}k{$n}";
        }
    }
}
check("all {$workers} × 8 parallel saves survive in both languages", $missing === [], implode(',', $missing));

// ── cleanup ──────────────────────────────────────────────────────────────────
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($base);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
