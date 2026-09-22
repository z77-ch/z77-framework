<?php

/**
 * Installer publication-record harness (CLI) — INST-ASSET-DIFF-001 / INST-ASSET-ENTRY-001.
 *
 * What is load-bearing here:
 *
 *   - a deployed file that is still byte-identical to the copy the installer published is
 *     refreshed WITHOUT a prompt, in a non-interactive run too — the defect: a stale
 *     `public/assets/backend/css/base.css` survived every `composer install` because the
 *     per-file prompt defaults to No and a non-interactive run answers No;
 *   - a locally edited file is NEVER written silently (the INST-ASSET-002 footgun stays
 *     closed) and is named exactly once in the output;
 *   - a record entry that is WRONG heals: in sync ⇒ ours, whatever the record said before.
 *     Without that, one aborted run or one hand copy would freeze a file as "edited"
 *     forever;
 *   - the record survives an abort mid-write (try/finally) and is written atomically;
 *   - absent in public/: no record ⇒ genuinely new ⇒ published unattended; a record ⇒ WE
 *     published it and the project deleted it ⇒ reported as removed, never re-created;
 *   - entry files: `index.php` / `.htaccess` are in the record, the branding files are not.
 *
 * Run: php tests/installer-asset-publish.php
 */

// ── Composer stubs ─────────────────────────────────────────────────────────
// composer/composer is not a dependency of the framework (the installer runs
// INSIDE Composer), so the three types Install.php declares are stubbed here.
// Nothing autoloads the real ones in a bare CLI run.
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

/** Composer IO double: records output, serves queued answers, refuses to be asked
 *  anything in a non-interactive run (that is the property under test). */
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

    /** How many output LINES name this file — F3: never twice. */
    public function mentions(string $needle): int
    {
        return count(array_filter($this->lines, static fn(string $l): bool => str_contains($l, $needle)));
    }
}

// ── fixture helpers ────────────────────────────────────────────────────────

$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-asset-publish-' . getmypid();

const VENDOR_REL = 'vendor/z77/module-backend';
const PUBLIC_REL = 'public/assets/backend/css/base.css';
const DISPLAY    = 'backend/css/base.css';

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

/**
 * Builds a project tree: one shipped asset in vendor, optionally a deployed copy
 * in public/, optionally a publication record in var/state/.
 */
function project(string $tmp, string $shipped, ?string $deployed, ?array $record): void
{
    rmTree($tmp);
    put($tmp . '/' . VENDOR_REL . '/res/assets/css/base.css', $shipped);
    if ($deployed !== null) {
        put($tmp . '/' . PUBLIC_REL, $deployed);
    } else {
        @mkdir($tmp . '/public/assets/backend/css', 0777, true);
    }
    if ($record !== null) {
        put($tmp . '/var/state/published-assets.json', json_encode($record));
    }
}

function installer(string $tmp, FakeIo $io): Install
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
    $set('publicAssetPaths', ['Z77\\Module\\Backend' => ['vendor' => [VENDOR_REL]]]);

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

/** The update path of execute(): load record → classify → write the undisputed → report. */
function runUpdate(string $tmp, FakeIo $io, ?array $entryDirs = null): Install
{
    $install = installer($tmp, $io);
    call($install, 'loadPublishedAssets');
    call($install, 'reportAssetDrift');
    if ($entryDirs !== null) {
        call($install, 'reportEntryFileDrift', $entryDirs);
    }
    call($install, 'deployUndisputedAssets');
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

$shipped   = ".be-form__row { display: grid }\n";   // the package moved on (29cda26)
$published = ".be-form__row { display: block }\n";  // what the installer wrote back then
$edited    = ".be-form__row { display: flex }\n";   // what the project made of it

$recordOf = static fn(string $content): array => [PUBLIC_REL => sha1($content)];

// ── 1. unchanged since publication → refreshed, no question asked ──────────
echo "Unchanged since publication (non-interactive)\n";
project($tmp, $shipped, $published, $recordOf($published));
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the stale copy is refreshed to the shipped version',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped);
check('nothing was asked', $io->asked === []);
check('the refresh is reported by name',
    str_contains($io->out(), '↻ refreshed: ' . DISPLAY), $io->out());
check('not also reported as kept', !str_contains($io->out(), 'kept ('), $io->out());
check('the record now holds the new hash', readRecord($tmp)[PUBLIC_REL] === sha1($shipped));
check('no temp file left behind', !is_file($tmp . '/var/state/published-assets.json.tmp'));

// ── 2. same tree again → nothing at all ────────────────────────────────────
echo "Second run over the refreshed tree\n";
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('no drift, no write, no asset output',
    !str_contains($io->out(), 'Asset'), $io->out());

// ── 3. locally edited → kept, named ONCE, never silently overwritten ───────
echo "Locally edited copy (non-interactive)\n";
project($tmp, $shipped, $edited, $recordOf($published));
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the edit survives', file_get_contents($tmp . '/' . PUBLIC_REL) === $edited);
check('reported as kept, project-owned wording',
    str_contains($io->out(), 'kept (project-owned or unrecorded): ' . DISPLAY), $io->out());
check('named exactly once (F3: no double listing)', $io->mentions(DISPLAY) === 1,
    (string) $io->mentions(DISPLAY));
check('the reason names the publication record',
    str_contains($io->out(), 'differs from the copy we published'), $io->out());
check('the non-interactive run says it wrote nothing',
    str_contains($io->out(), 'Nothing was written'), $io->out());
check('the record keeps the old hash (we did not publish this file)',
    readRecord($tmp)[PUBLIC_REL] === sha1($published));

// ── 4. no record at all → today's behaviour, reported as unknown ───────────
echo "Missing publication record (non-interactive)\n";
project($tmp, $shipped, $published, null);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the deployed copy is kept', file_get_contents($tmp . '/' . PUBLIC_REL) === $published);
check('reported as kept', str_contains($io->out(), 'kept (project-owned or unrecorded)'), $io->out());
check('the reason says the provenance is unknown',
    str_contains($io->out(), 'no publication record'), $io->out());
check('no record file is invented', readRecord($tmp) === []);

// ── 5. malformed record → same fallback, no crash ──────────────────────────
echo "Malformed publication record\n";
project($tmp, $shipped, $published, null);
put($tmp . '/var/state/published-assets.json', 'not json at all');
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('falls back to "unknown provenance", nothing written',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $published
    && str_contains($io->out(), 'no publication record'), $io->out());

// ── 6. absent + unrecorded = genuinely new → published unattended ──────────
echo "New file, never published here (non-interactive)\n";
project($tmp, $shipped, null, null);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('published without asking', is_file($tmp . '/' . PUBLIC_REL)
    && file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped);
check('named as published', str_contains($io->out(), '+ published: ' . DISPLAY), $io->out());
check('and recorded', readRecord($tmp)[PUBLIC_REL] === sha1($shipped));

// ── 6b. absent + recorded = deleted here → never re-created ────────────────
echo "Published once, deleted in the project\n";
project($tmp, $shipped, null, $recordOf($published));
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('not re-created', !is_file($tmp . '/' . PUBLIC_REL));
check('reported as removed here, not as new',
    str_contains($io->out(), '− removed here: ' . DISPLAY)
    && !str_contains($io->out(), '+ published: ' . DISPLAY), $io->out());

// ── 7. interactive: the edited file is asked, default No keeps it ──────────
echo "Locally edited copy (interactive, answered No)\n";
project($tmp, $shipped, $edited, $recordOf($published));
$io = new FakeIo(true);
$io->answers = [false];
runUpdate($tmp, $io);
check('asked once', count($io->asked) === 1, implode(' | ', $io->asked));
check('the edit survives a No', file_get_contents($tmp . '/' . PUBLIC_REL) === $edited);
check('the run reports that nothing was written',
    str_contains($io->out(), 'nothing written on request'), $io->out());

// ── 8. interactive: yes overwrites AND records, so the next run is silent ──
echo "Locally edited copy (interactive, answered Yes)\n";
project($tmp, $shipped, $edited, $recordOf($published));
$io = new FakeIo(true);
$io->answers = [true];
runUpdate($tmp, $io);
check('overwritten on an explicit yes', file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped);
check('the record follows the write', readRecord($tmp)[PUBLIC_REL] === sha1($shipped));

// ── 9. interactive: an unchanged copy is refreshed without a prompt ────────
echo "Unchanged since publication (interactive)\n";
project($tmp, $shipped, $published, $recordOf($published));
$io = new FakeIo(true);
runUpdate($tmp, $io);
check('refreshed', file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped);
check('no prompt — an untouched copy is not a decision', $io->asked === []);

// ── 9b. an installation from before the record: in-sync files adopt one ────
echo "Existing installation, no record, public/ in sync\n";
project($tmp, $shipped, $shipped, null);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the agreed state is recorded', readRecord($tmp)[PUBLIC_REL] === sha1($shipped));
check('nothing is written into public/ for it',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped);
check('and it is not reported as anything', !str_contains($io->out(), 'Asset'), $io->out());

put($tmp . '/' . VENDOR_REL . '/res/assets/css/base.css', $published);  // framework moves on
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the very next framework change is refreshed silently',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $published && $io->asked === []);

echo "Existing installation, no record, public/ already differs\n";
project($tmp, $shipped, $edited, null);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('an out-of-sync file adopts NOTHING (provenance stays unknown)', readRecord($tmp) === []);
check('and is kept + named', file_get_contents($tmp . '/' . PUBLIC_REL) === $edited
    && str_contains($io->out(), 'no publication record'), $io->out());

// ── 9c. a WRONG record entry heals (F1) ────────────────────────────────────
echo "Stale record entry, public/ in sync\n";
project($tmp, $shipped, $shipped, [PUBLIC_REL => sha1('something we never wrote')]);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('the stale hash is corrected to the deployed one',
    readRecord($tmp)[PUBLIC_REL] === sha1($shipped), json_encode(readRecord($tmp)));
check('nothing written, nothing reported',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped && !str_contains($io->out(), 'Asset'));

put($tmp . '/' . VENDOR_REL . '/res/assets/css/base.css', $published);  // framework moves on
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('after healing, the next framework change refreshes silently',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $published && $io->asked === []
    && str_contains($io->out(), '↻ refreshed'), $io->out());

// ── 9d. an abort mid-write still leaves the record correct (F1) ────────────
echo "Abort inside the write loop\n";
project($tmp, $shipped, $published, $recordOf($published));
$io      = new FakeIo(false);
$install = installer($tmp, $io);
call($install, 'loadPublishedAssets');
call($install, 'reportAssetDrift');
check('one file is queued for refresh', count(prop($install, 'assetRefreshed')) === 1);
// A second, impossible target: its parent is a FILE, so mkdir() must fail.
put($tmp . '/public/assets/backend/blocked', 'not a directory');
(new ReflectionProperty(Install::class, 'assetPublishedNew'))->setValue($install, [[
    'display' => 'backend/blocked/x.css',
    'src'     => $tmp . '/' . VENDOR_REL . '/res/assets/css/base.css',
    'dst'     => $tmp . '/public/assets/backend/blocked/x.css',
]]);
$threw = false;
set_error_handler(static fn(): bool => true);   // the failing mkdir() warns before it throws
try { call($install, 'deployUndisputedAssets'); } catch (RuntimeException) { $threw = true; }
restore_error_handler();
check('the failure is not swallowed', $threw);
check('the file written before the abort IS in the record on disk',
    readRecord($tmp)[PUBLIC_REL] === sha1($shipped), json_encode(readRecord($tmp)));

// ── 10. first install writes the record it will later compare against ──────
echo "First install\n";
rmTree($tmp);
put($tmp . '/' . VENDOR_REL . '/res/assets/css/base.css', $published);
put($tmp . '/' . VENDOR_REL . '/res/assets/js/shell.js', "// shell\n");
$io      = new FakeIo(false);
$install = installer($tmp, $io);
call($install, 'copyFiles', [
    $tmp . '/' . VENDOR_REL . '/res/assets',
    $tmp . '/public/assets/backend',
    true,
]);
call($install, 'savePublishedAssets');
$record = readRecord($tmp);
check('the record is written to var/state/published-assets.json', $record !== []);
check('keys are project-relative', isset($record[PUBLIC_REL]), implode(', ', array_keys($record)));
check('every published file is in it', count($record) === 2, implode(', ', array_keys($record)));
check('the hash is the published content', $record[PUBLIC_REL] === sha1($published));

// the very next update then refreshes silently — the loop closes
put($tmp . '/' . VENDOR_REL . '/res/assets/css/base.css', $shipped);
$io = new FakeIo(false);
runUpdate($tmp, $io);
check('a first install makes the next update silent',
    file_get_contents($tmp . '/' . PUBLIC_REL) === $shipped && $io->asked === []);

// ── 11. entry files: index.php + .htaccess in, branding out ───────────────
echo "Entry files\n";
rmTree($tmp);
$entrySrc = $tmp . '/kernel-public';
put($entrySrc . '/index.php',  "<?php // entry v1\n");
put($entrySrc . '/.htaccess',  "# rules v1\n");
put($entrySrc . '/favicon.ico', "ICON-FRAMEWORK\n");
put($tmp . '/' . VENDOR_REL . '/res/assets/css/base.css', $shipped);
@mkdir($tmp . '/public/assets/backend/css', 0777, true);

$io      = new FakeIo(false);
$install = installer($tmp, $io);
call($install, 'copyFiles', [$entrySrc, $tmp . '/public', false]);
call($install, 'recordEntryFiles', [$tmp . '/public']);
call($install, 'savePublishedAssets');
$record = readRecord($tmp);
check('index.php is recorded', isset($record['public/index.php']));
check('.htaccess is recorded', isset($record['public/.htaccess']));
check('the favicon is NOT recorded (INST-ASSET-ENTRY-001)',
    !isset($record['public/favicon.ico']), implode(', ', array_keys($record)));

put($entrySrc . '/index.php', "<?php // entry v2\n");          // framework moves on
file_put_contents($tmp . '/public/favicon.ico', "ICON-PROJECT\n");  // project brands it
$io = new FakeIo(false);
runUpdate($tmp, $io, [$entrySrc, $tmp . '/public']);
check('an untouched index.php is refreshed silently',
    file_get_contents($tmp . '/public/index.php') === "<?php // entry v2\n"
    && $io->asked === [], $io->out());
check('the project favicon is neither touched nor mentioned',
    file_get_contents($tmp . '/public/favicon.ico') === "ICON-PROJECT\n"
    && !str_contains($io->out(), 'favicon'), $io->out());

file_put_contents($tmp . '/public/.htaccess', "# rules, edited here\n");
put($entrySrc . '/.htaccess', "# rules v2\n");
$io = new FakeIo(false);
runUpdate($tmp, $io, [$entrySrc, $tmp . '/public']);
check('an edited .htaccess is kept and named',
    file_get_contents($tmp . '/public/.htaccess') === "# rules, edited here\n"
    && str_contains($io->out(), 'kept (project-owned or unrecorded): .htaccess'), $io->out());

rmTree($tmp);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
