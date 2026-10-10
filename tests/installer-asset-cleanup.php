<?php

/**
 * Installer asset-cleanup harness (CLI) — INST-ASSET-002 (second incident, 2026-10-10).
 *
 * The defect: the asset publish ADDS and REFRESHES, it never REMOVED. When a package
 * dropped a file, its published copy stayed in `public/assets/` and kept being served —
 * measured on z77.ch, where the DMS deleted `documents/upload.js` + `.min.js` and
 * `public/assets/dms/js/documents/` still served both after `composer update "z77/*"`.
 *
 * What is load-bearing here:
 *
 *   - a published file the packages no longer ship is deleted ONLY while it is still
 *     byte-identical to the copy the publish last wrote (publication record) — identical
 *     means never hand-edited, so the delete loses nothing;
 *   - a file that differs from the record, or that the record does not know at all, STAYS
 *     and is not even named: it may be the project's own file in its own public/ directory;
 *   - the delete never leaves the asset tree it was matched against — not for a record key
 *     pointing elsewhere, not for a hand-edited key with `..` in it, not for the entry
 *     files, not for another module's tree;
 *   - a package whose vendor asset tree is absent this run (uninstalled, half-installed,
 *     renamed) is not walked, so NOTHING of it is deleted;
 *   - the record entry goes with the file. Left behind it would read as "the project
 *     deleted it" and block the same name from ever being published again;
 *   - the loop closes: after a delete, a package that ships the file again publishes it as
 *     new, unattended;
 *   - a failing unlink() throws, and the record on disk still describes what was already
 *     deleted (try/finally).
 *
 * Run: php tests/installer-asset-cleanup.php
 */

// ── Composer stubs ─────────────────────────────────────────────────────────
// composer/composer is not a dependency of the framework (the installer runs
// INSIDE Composer), so the three types Install.php declares are stubbed here.
namespace Composer {
    class Composer {}
}
namespace Composer\Script {
    class Event {}
}
namespace Composer\IO {
    interface IOInterface
    {
        public function write($messages, $newline = true, $verbosity = 0);
        public function writeError($messages, $newline = true, $verbosity = 0);
        public function isInteractive();
        public function askConfirmation($question, $default = true);
        public function askAndHideAnswer($question);
    }
}

namespace {

use Composer\IO\IOInterface;
use Z77\Core\Installer\Install;

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));

require $root . '/packages/kernel/core/src/Installer/Install.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Composer IO double: records output, refuses to be asked anything in a
 *  non-interactive run (a delete must never need a question at all). */
final class FakeIo implements IOInterface
{
    public array $lines   = [];
    public array $asked   = [];
    public array $answers = [];

    public function __construct(private bool $interactive) {}

    public function write($messages, $newline = true, $verbosity = 0)
    {
        foreach ((array) $messages as $message) {
            $this->lines[] = (string) $message;
        }
    }
    public function writeError($messages, $newline = true, $verbosity = 0)
    {
        $this->write($messages);
    }
    public function isInteractive() { return $this->interactive; }
    public function askConfirmation($question, $default = true)
    {
        if (!$this->interactive) {
            throw new RuntimeException('askConfirmation() in a non-interactive run: ' . $question);
        }
        $this->asked[] = (string) $question;
        return array_shift($this->answers) ?? $default;
    }
    public function askAndHideAnswer($question) { return ''; }

    public function out(): string { return implode("\n", $this->lines); }
}

// ── fixture ────────────────────────────────────────────────────────────────

$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-asset-cleanup-' . getmypid();

const VENDOR_REL = 'vendor/z77/module-dms';
const ASSET_ROOT = 'public/assets/dms';

// The measured case: the DMS dropped js/documents/upload.js while js/core.js stayed.
const KEPT_REL    = ASSET_ROOT . '/js/core.js';
const DROPPED_REL = ASSET_ROOT . '/js/documents/upload.js';
const DROPPED_DIR = ASSET_ROOT . '/js/documents';

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $path = $dir . '/' . $item;
        is_dir($path) ? rmTree($path) : @unlink($path);
    }
    @rmdir($dir);
}

function put(string $file, string $content): void
{
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $content);
}

function installer(string $tmp, FakeIo $io, array $vendorPaths = [VENDOR_REL]): Install
{
    $install = (new ReflectionClass(Install::class))->newInstanceWithoutConstructor();

    $set = static function (string $name, mixed $value) use ($install): void {
        (new ReflectionProperty(Install::class, $name))->setValue($install, $value);
    };
    $set('io', $io);
    $set('baseDir', $tmp);
    $set('bootstrapConfig', ['htmlRoot' => 'public', 'assetDir' => 'assets']);
    $set('frameworkPrefix', 'Z77');
    $set('modulePrefix', 'Module');
    $set('publicAssetPaths', ['Z77\\Module\\Dms' => ['vendor' => $vendorPaths]]);

    return $install;
}

function call(Install $install, string $method, array $args = []): mixed
{
    return (new ReflectionMethod(Install::class, $method))->invokeArgs($install, $args);
}

function prop(Install $install, string $name): mixed
{
    return (new ReflectionProperty(Install::class, $name))->getValue($install);
}

/** The update path of execute(): load record → classify → write the undisputed →
 *  take back what is no longer shipped → report. */
function runUpdate(string $tmp, FakeIo $io, array $vendorPaths = [VENDOR_REL]): Install
{
    $install = installer($tmp, $io, $vendorPaths);
    call($install, 'loadPublishedAssets');
    call($install, 'reportAssetDrift');
    call($install, 'deployUndisputedAssets');
    call($install, 'unpublishDroppedAssets');
    call($install, 'renderAssetWriteNotice');
    call($install, 'renderAssetDriftNotice');
    call($install, 'promptAssetDeploy');
    call($install, 'savePublishedAssets');
    return $install;
}

function readRecord(string $tmp): array
{
    $file = $tmp . '/var/state/published-assets.json';
    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

$core      = "// dms core\n";
$published = "// upload v1, as the installer wrote it\n";
$edited    = "// upload v1, with a project tweak\n";

/**
 * Builds the measured tree: the package still ships js/core.js, the deployed
 * public/ additionally holds js/documents/upload.js from an earlier publish.
 *
 * @param string|null $deployed  content of the dropped file in public/, null = absent
 * @param string|null $recorded  content the record claims we published, null = no entry
 */
function project(string $tmp, ?string $deployed, ?string $recorded, string $core): void
{
    rmTree($tmp);
    put($tmp . '/' . VENDOR_REL . '/res/assets/js/core.js', $core);
    put($tmp . '/' . KEPT_REL, $core);
    if ($deployed !== null) {
        put($tmp . '/' . DROPPED_REL, $deployed);
    }

    $record = [KEPT_REL => sha1($core)];
    if ($recorded !== null) {
        $record[DROPPED_REL] = sha1($recorded);
    }
    put($tmp . '/var/state/published-assets.json', json_encode($record));
}

// ── 1. dropped + untouched since publication → deleted, no question ────────
echo "Dropped by the package, untouched since publication (non-interactive)\n";
project($tmp, $published, $published, $core);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the leftover copy is deleted', !file_exists($tmp . '/' . DROPPED_REL));
check('nothing was asked', $io->asked === []);
check('the delete is reported by name',
    str_contains($io->out(), '✖ unpublished: dms/js/documents/upload.js'), $io->out());
check('the record entry goes with the file',
    !array_key_exists(DROPPED_REL, readRecord($tmp)), json_encode(readRecord($tmp)));
check('the still-shipped file is untouched',
    file_get_contents($tmp . '/' . KEPT_REL) === $core);
check('its record entry stays', readRecord($tmp)[KEPT_REL] === sha1($core));
check('the emptied directory is gone too', !is_dir($tmp . '/' . DROPPED_DIR));
check('the asset root itself survives', is_dir($tmp . '/' . ASSET_ROOT . '/js'));
check('no temp record left behind', !is_file($tmp . '/var/state/published-assets.json.tmp'));

// ── 2. same tree again → nothing left to say ───────────────────────────────
echo "Second run over the cleaned tree\n";
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('no delete, no write, no asset output', !str_contains($io->out(), 'Asset'), $io->out());

// ── 3. the loop closes: the package ships it again → published as new ──────
echo "The package ships the dropped file again\n";
put($tmp . '/' . VENDOR_REL . '/res/assets/js/documents/upload.js', "// upload v2\n");
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('published unattended, not treated as "removed here"',
    is_file($tmp . '/' . DROPPED_REL)
    && file_get_contents($tmp . '/' . DROPPED_REL) === "// upload v2\n", $io->out());
check('named as published, never as removed here',
    str_contains($io->out(), '+ published: dms/js/documents/upload.js')
    && !str_contains($io->out(), '− removed here'), $io->out());
check('and recorded again', readRecord($tmp)[DROPPED_REL] === sha1("// upload v2\n"));

// ── 4. dropped but EDITED here → stays, and is not even named ──────────────
echo "Dropped by the package, edited in this project\n";
project($tmp, $edited, $published, $core);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the edit survives', file_get_contents($tmp . '/' . DROPPED_REL) === $edited);
check('the record entry stays (we did not delete the file)',
    readRecord($tmp)[DROPPED_REL] === sha1($published), json_encode(readRecord($tmp)));
check('not named — it may be the project\'s own file, and naming it would repeat forever',
    !str_contains($io->out(), 'upload.js'), $io->out());
check('nothing was asked', $io->asked === []);

// ── 5. dropped and UNRECORDED → the project's own file, never touched ──────
echo "A file in the asset tree the record never knew\n";
project($tmp, $edited, null, $core);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('kept', file_get_contents($tmp . '/' . DROPPED_REL) === $edited);
check('no entry invented for it', !array_key_exists(DROPPED_REL, readRecord($tmp)));
check('not named', !str_contains($io->out(), 'upload.js'), $io->out());

// ── 6. recorded, dropped, already gone from disk → entry only ──────────────
echo "Recorded, dropped, already deleted by hand\n";
project($tmp, null, $published, $core);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('nothing is re-created', !file_exists($tmp . '/' . DROPPED_REL));
check('the dead entry is dropped so the name is not blocked forever',
    !array_key_exists(DROPPED_REL, readRecord($tmp)), json_encode(readRecord($tmp)));
check('not reported as a delete — there was nothing to delete',
    !str_contains($io->out(), 'unpublished'), $io->out());
check('and not reported as "removed here" either',
    !str_contains($io->out(), '− removed here'), $io->out());

// ── 7. the package's asset tree is absent this run → delete NOTHING ────────
echo "Vendor asset tree missing (uninstalled / half-installed / renamed)\n";
project($tmp, $published, $published, $core);
rmTree($tmp . '/' . VENDOR_REL);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the dropped copy stays — an unwalked tree says nothing about what is shipped',
    file_get_contents($tmp . '/' . DROPPED_REL) === $published);
check('the still-published file stays too',
    file_get_contents($tmp . '/' . KEPT_REL) === $core);
check('the record is untouched', readRecord($tmp) === [
    KEPT_REL    => sha1($core),
    DROPPED_REL => sha1($published),
], json_encode(readRecord($tmp)));
check('nothing reported', !str_contains($io->out(), 'Asset'), $io->out());

// ── 8. the delete never leaves the walked asset tree ───────────────────────
echo "Record keys outside the walked tree\n";
project($tmp, $published, $published, $core);
// framework-owned entry files + another module's tree + a project file outside public/
put($tmp . '/public/index.php', "<?php // entry\n");
put($tmp . '/public/.htaccess', "# rules\n");
put($tmp . '/public/assets/backend/css/base.css', "/* backend */\n");
put($tmp . '/override/own.js', "// project\n");
$record = readRecord($tmp) + [
    'public/index.php'                   => sha1("<?php // entry\n"),
    'public/.htaccess'                   => sha1("# rules\n"),
    'public/assets/backend/css/base.css' => sha1("/* backend */\n"),
    'override/own.js'                    => sha1("// project\n"),
];
put($tmp . '/var/state/published-assets.json', json_encode($record));
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the dropped dms file IS deleted', !file_exists($tmp . '/' . DROPPED_REL));
check('public/index.php survives', is_file($tmp . '/public/index.php'));
check('public/.htaccess survives', is_file($tmp . '/public/.htaccess'));
check('another module\'s asset tree survives (never walked this run)',
    is_file($tmp . '/public/assets/backend/css/base.css'));
check('a recorded path outside public/ survives', is_file($tmp . '/override/own.js'));
$after = readRecord($tmp);
check('their record entries all survive',
    isset($after['public/index.php'], $after['public/.htaccess'],
          $after['public/assets/backend/css/base.css'], $after['override/own.js']),
    json_encode($after));

// ── 8b. a hand-edited record key cannot aim the delete out of the tree ─────
echo "Unsafe record keys\n";
project($tmp, $published, $published, $core);
put($tmp . '/outside.txt', "keep me\n");
$escape  = ASSET_ROOT . '/js/../../../../outside.txt';
$record  = readRecord($tmp) + [$escape => sha1("keep me\n")];
put($tmp . '/var/state/published-assets.json', json_encode($record));
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the traversing key is ignored, the file outside survives',
    is_file($tmp . '/outside.txt'));
check('and its entry is left alone (nothing was proven about it)',
    array_key_exists($escape, readRecord($tmp)), json_encode(readRecord($tmp)));
check('the legitimate delete still happened', !file_exists($tmp . '/' . DROPPED_REL));

// ── 9. a deeper nesting is pruned up to the first directory still in use ───
echo "Pruning empty directories\n";
$deepRel = ASSET_ROOT . '/js/documents/legacy/upload.js';
project($tmp, null, null, $core);
put($tmp . '/' . $deepRel, $published);
put($tmp . '/var/state/published-assets.json', json_encode([
    KEPT_REL => sha1($core),
    $deepRel => sha1($published),
]));
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the whole emptied chain is gone',
    !is_dir($tmp . '/' . ASSET_ROOT . '/js/documents'), $io->out());
check('the directory that still holds a shipped file stays',
    is_file($tmp . '/' . KEPT_REL) && is_dir($tmp . '/' . ASSET_ROOT . '/js'));
check('the asset root is NOT removed', is_dir($tmp . '/' . ASSET_ROOT));
echo "Pruning stops at a non-empty directory\n";
project($tmp, $published, $published, $core);
put($tmp . '/' . DROPPED_DIR . '/project-note.txt', "mine\n");
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the dropped file is deleted', !file_exists($tmp . '/' . DROPPED_REL));
check('its directory stays because the project keeps a file there',
    is_file($tmp . '/' . DROPPED_DIR . '/project-note.txt'));

// ── 10. a failing unlink throws, and the record still describes the truth ──
echo "Abort inside the delete loop\n";
project($tmp, $published, $published, $core);
$io      = new FakeIo(false);
$install = installer($tmp, $io);
call($install, 'loadPublishedAssets');
call($install, 'reportAssetDrift');
check('one leftover is queued for deletion', count(prop($install, 'assetUnpublished')) === 1,
    json_encode(prop($install, 'assetUnpublished')));
// A second, impossible delete appended after the real one: the file does not exist,
// so unlink() must fail on every platform.
$queued   = prop($install, 'assetUnpublished');
$queued[] = [
    'display' => 'dms/js/documents/gone.js',
    'dst'     => $tmp . '/' . DROPPED_DIR . '/gone.js',
    'key'     => DROPPED_DIR . '/gone.js',
    'root'    => $tmp . '/' . ASSET_ROOT,
];
(new ReflectionProperty(Install::class, 'assetUnpublished'))->setValue($install, $queued);
$threw = false;
set_error_handler(static fn(): bool => true);   // the failing unlink() warns before it throws
try { call($install, 'unpublishDroppedAssets'); } catch (RuntimeException) { $threw = true; }
restore_error_handler();
check('the failure is not swallowed', $threw);
check('the file deleted before the abort is gone', !file_exists($tmp . '/' . DROPPED_REL));
check('and the record on disk already knows it',
    !array_key_exists(DROPPED_REL, readRecord($tmp)), json_encode(readRecord($tmp)));
check('the untouched entry is still there', readRecord($tmp)[KEPT_REL] === sha1($core));

// ── 11. an interactive run deletes exactly the same, without a prompt ──────
echo "Dropped by the package, untouched since publication (interactive)\n";
project($tmp, $published, $published, $core);
$io = new FakeIo(true);
runUpdate($tmp, $io);
check('deleted', !file_exists($tmp . '/' . DROPPED_REL));
check('no prompt — our own untouched copy is not a decision', $io->asked === []);

// ── 11b. two namespaces deriving the same asset dir queue ONE delete ───────
echo "Two namespaces, same derived asset dir\n";
project($tmp, $published, $published, $core);
$io      = new FakeIo(false);
$install = installer($tmp, $io);
// Z77\Module\Dms and Z77\Dms both derive 'dms' → the same target tree is walked twice.
(new ReflectionProperty(Install::class, 'publicAssetPaths'))->setValue($install, [
    'Z77\\Module\\Dms' => ['vendor' => [VENDOR_REL]],
    'Z77\\Dms'         => ['vendor' => [VENDOR_REL]],
]);
call($install, 'loadPublishedAssets');
call($install, 'reportAssetDrift');
check('the delete is queued once, not twice', count(prop($install, 'assetUnpublished')) === 1,
    json_encode(array_keys(prop($install, 'assetUnpublished'))));
call($install, 'unpublishDroppedAssets');
call($install, 'savePublishedAssets');
check('and it ran without a second, failing unlink', !file_exists($tmp . '/' . DROPPED_REL));

// ── 12. a first install never deletes (there is nothing published yet) ─────
echo "First install\n";
rmTree($tmp);
put($tmp . '/' . VENDOR_REL . '/res/assets/js/core.js', $core);
$io      = new FakeIo(false);
$install = installer($tmp, $io);
call($install, 'copyFiles', [
    $tmp . '/' . VENDOR_REL . '/res/assets',
    $tmp . '/' . ASSET_ROOT,
    true,
]);
call($install, 'unpublishDroppedAssets');
call($install, 'savePublishedAssets');
check('the seeded file is there', file_get_contents($tmp . '/' . KEPT_REL) === $core);
check('and recorded', readRecord($tmp) === [KEPT_REL => sha1($core)], json_encode(readRecord($tmp)));
check('no removal reported', !str_contains($io->out(), 'unpublished'), $io->out());

rmTree($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
