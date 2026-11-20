<?php

/**
 * Installer admin-prompt harness (CLI) — INST-PROMPT-EOF-001.
 *
 * The defect (dry run 2026-11-19): `composer dev --no-interaction` from an agent's Git Bash
 * ran the script `@composer install` WITHOUT `--no-interaction`; Composer counts every
 * MSYSTEM=MINGW* shell as a TTY, so the nested install was "interactive" with stdin at EOF.
 * On Windows the hidden prompt (hiddeninput.exe) reads EOF as an EMPTY answer, and
 * promptNewPassword() re-asked for ever: «Choose a password: / Password must not be empty.»
 * 2,174 times, the server never started.
 *
 * What is load-bearing here:
 *
 *   - stdin closed / not a terminal → provisionAdmin() asks NOTHING and defers to the
 *     SETUP_TOKEN (run in a CHILD process whose stdin is a closed pipe — the real EOF);
 *   - an existing SETUP_TOKEN is kept (the dry run had one);
 *   - the prompt itself is bounded: empty answers, mismatches, too-weak answers end after
 *     MAX_PASSWORD_ATTEMPTS rounds; a null answer or MissingInputException ends at once;
 *   - nothing is written when the prompt gives up; a real password still creates the admin;
 *   - confirm(): MissingInputException = "no answer", any other exception still propagates;
 *   - the closing notice (INST-SETUP-HINT-001): while a SETUP_TOKEN exists and no user store,
 *     the run ENDS with the token file, the setup URL and the login-page link — never the
 *     token itself; nothing once a user exists or the token is gone.
 *
 * Run: php tests/installer-admin-prompt.php
 */

// ── Composer / Symfony stubs ───────────────────────────────────────────────
// composer/composer is not a dependency of the framework (the installer runs INSIDE
// Composer), so the types Install.php names are stubbed here.
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
namespace Symfony\Component\Console\Exception {
    class MissingInputException extends \RuntimeException {}
}

namespace {

use Composer\IO\IOInterface;
use Symfony\Component\Console\Exception\MissingInputException;
use Z77\Core\Installer\Install;

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));

require $root . '/packages/kernel/shared/src/Auth/PasswordTier.php';
require $root . '/packages/kernel/shared/src/Auth/PasswordPolicy.php';
require $root . '/packages/kernel/shared/src/Auth/FirstAdminSetup.php';
require $root . '/packages/kernel/core/src/Installer/Install.php';

/** Interactive Composer IO double. Hidden answers come from a callable (n = 1, 2, …);
 *  it refuses a 20th question so a regression to an endless loop fails instead of hanging. */
final class FakeIo implements IOInterface
{
    public array $lines = [];
    public int   $asked = 0;

    /** @param \Closure(int): mixed $hidden  @param \Closure(): mixed|null $confirm */
    public function __construct(private \Closure $hidden, private ?\Closure $confirm = null) {}

    public function write($messages, $newline = true, $verbosity = 0)
    {
        foreach ((array) $messages as $message) {
            $this->lines[] = (string) $message;
        }
    }
    public function writeError($messages, $newline = true, $verbosity = 0) { $this->write($messages); }
    public function isInteractive() { return true; }
    public function askConfirmation($question, $default = true) { return ($this->confirm)(); }
    public function askAndHideAnswer($question)
    {
        if (++$this->asked >= 20) {
            throw new LogicException('asked 20 times — the prompt does not terminate');
        }
        return ($this->hidden)($this->asked);
    }
    public function count(string $needle): int
    {
        return count(array_filter($this->lines, static fn(string $l): bool => str_contains($l, $needle)));
    }
}

function installer(string $dir, FakeIo $io, array $authConfig = []): Install
{
    $install = (new ReflectionClass(Install::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Install::class, 'io'))->setValue($install, $io);
    (new ReflectionProperty(Install::class, 'baseDir'))->setValue($install, $dir);
    (new ReflectionProperty(Install::class, 'authConfig'))->setValue($install, $authConfig);
    return $install;
}

function call(Install $install, string $method, array $args = []): mixed
{
    return (new ReflectionMethod(Install::class, $method))->invokeArgs($install, $args);
}

const TOKEN = '/data/framework/auth/SETUP_TOKEN';
const USERS = '/data/framework/auth/backendUsers.json';

// ── child: provisionAdmin() with the stdin the parent gave us (a closed pipe) ──
if (($argv[1] ?? '') === '--child') {
    $io = new FakeIo(static fn(int $n): string => '');   // what hiddeninput.exe returns at EOF
    $error = '';
    try {
        call(installer($argv[2], $io), 'provisionAdmin');
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    echo json_encode(['asked' => $io->asked, 'out' => implode("\n", $io->lines), 'error' => $error]);
    exit(0);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — {$got}" : '') . "\n"; }
}

$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-admin-prompt-' . getmypid();

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
register_shutdown_function(static fn() => rmTree($tmp));

/** Runs provisionAdmin() in a child whose stdin is a pipe closed at once (EOF, no terminal). */
function runChildAtEof(string $dir): array
{
    $proc = proc_open([PHP_BINARY, __FILE__, '--child', $dir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['exit' => -1, 'data' => null, 'raw' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'data' => json_decode((string) $stdout, true), 'raw' => trim($stdout . ' ' . $stderr)];
}

// ── A. stdin at EOF: no question, the SETUP_TOKEN path ─────────────────────
echo "A. provisionAdmin() with stdin closed (child process)\n";
rmTree($tmp);
@mkdir($tmp, 0777, true);
$r = runChildAtEof($tmp);
check('child ran to the end (exit 0, no exception)', $r['exit'] === 0 && is_array($r['data']) && $r['data']['error'] === '', $r['raw']);
check('no password question was asked', ($r['data']['asked'] ?? -1) === 0, (string) ($r['data']['asked'] ?? $r['raw']));
check('says why: stdin is not a terminal', str_contains($r['data']['out'] ?? '', 'stdin is not a terminal'), $r['data']['out'] ?? '');
check('SETUP_TOKEN written', is_file($tmp . TOKEN));
check('no backendUsers.json', !is_file($tmp . USERS));

echo "A2. the same with a SETUP_TOKEN already there (the dry run)\n";
file_put_contents($tmp . TOKEN, "existing\n");
$r = runChildAtEof($tmp);
check('child ran to the end, nothing asked', $r['exit'] === 0 && ($r['data']['asked'] ?? -1) === 0 && $r['data']['error'] === '', $r['raw']);
check('existing SETUP_TOKEN kept unchanged', (string) @file_get_contents($tmp . TOKEN) === "existing\n");

// ── B. the prompt is bounded ────────────────────────────────────────────────
echo "B. promptNewPassword() terminates\n";
$io = new FakeIo(static fn(int $n): string => '');
$got = call(installer($tmp, $io), 'promptNewPassword');
check('empty answers for ever → null after 3 rounds', $got === null && $io->asked === 3, "asked {$io->asked}");
check('«must not be empty» said 3 times, not 2,174', $io->count('must not be empty') === 3);

$io = new FakeIo(static fn(int $n): ?string => null);
check('null answer (EOF) → null at once', call(installer($tmp, $io), 'promptNewPassword') === null && $io->asked === 1, "asked {$io->asked}");

$io = new FakeIo(static function (int $n): string { throw new MissingInputException('Aborted.'); });
check('MissingInputException (EOF on Unix) → null at once', call(installer($tmp, $io), 'promptNewPassword') === null && $io->asked === 1, "asked {$io->asked}");

$io = new FakeIo(static fn(int $n): string => $n % 2 ? 'first-' . $n : 'other-' . $n);
check('mismatch for ever → null after 3 rounds (6 questions)', call(installer($tmp, $io), 'promptNewPassword') === null && $io->asked === 6, "asked {$io->asked}");

$io = new FakeIo(static fn(int $n): string => $n === 1 ? '' : 'Pferd-Batterie-Heftklammer-77');
check('one empty answer, then a match → the password', call(installer($tmp, $io), 'promptNewPassword') === 'Pferd-Batterie-Heftklammer-77' && $io->asked === 3, "asked {$io->asked}");

// ── C. provisionAdminInteractive(): give up = write nothing ─────────────────
echo "C. provisionAdminInteractive()\n";
rmTree($tmp);
@mkdir($tmp, 0777, true);
$io = new FakeIo(static fn(int $n): string => '');
$ok = call(installer($tmp, $io), 'provisionAdminInteractive', [$tmp . '/data/framework/auth', $tmp . USERS]);
check('no password → false, nothing written', $ok === false && !is_file($tmp . USERS) && !is_file($tmp . TOKEN));
check('says the setup page takes over', $io->count('created through the setup page') === 1);

$io = new FakeIo(static fn(int $n): string => 'admin');   // weak; veryStrong blocks it
$ok = call(installer($tmp, $io, ['passwordTier' => 'veryStrong']), 'provisionAdminInteractive', [$tmp . '/data/framework/auth', $tmp . USERS]);
check('veryStrong, weak for ever → false after 3 rounds', $ok === false && $io->asked === 6 && !is_file($tmp . USERS), "asked {$io->asked}");

$io = new FakeIo(static fn(int $n): string => 'Pferd-Batterie-Heftklammer-77');
$ok = call(installer($tmp, $io), 'provisionAdminInteractive', [$tmp . '/data/framework/auth', $tmp . USERS]);
$users = json_decode((string) @file_get_contents($tmp . USERS), true);
check('a real password still creates the superUser', $ok === true && ($users[0]['roles'] ?? null) === ['superUser']
    && password_verify('Pferd-Batterie-Heftklammer-77', $users[0]['password_hash'] ?? ''));

// ── D. confirm(): only a missing input is swallowed ─────────────────────────
echo "D. confirm()\n";
$io = new FakeIo(static fn(int $n): string => '', static function (): bool { throw new MissingInputException('Aborted.'); });
check('MissingInputException → null (no answer)', call(installer($tmp, $io), 'confirm', ['q? ', true]) === null);
$io = new FakeIo(static fn(int $n): string => '', static fn(): bool => false);
check('an answer passes through', call(installer($tmp, $io), 'confirm', ['q? ', true]) === false);
$io = new FakeIo(static fn(int $n): string => '', static function (): bool { throw new RuntimeException('other'); });
$thrown = false;
try { call(installer($tmp, $io), 'confirm', ['q? ', true]); } catch (RuntimeException) { $thrown = true; }
check('any other exception still propagates', $thrown);

// ── E. the closing notice while the first admin is pending ──────────────────
echo "E. reportPendingAdminSetup() (INST-SETUP-HINT-001)\n";
rmTree($tmp);
@mkdir($tmp . '/data/framework/auth', 0777, true);
$secret = bin2hex(random_bytes(32));
file_put_contents($tmp . TOKEN, $secret . "\n");
$io = new FakeIo(static fn(int $n): string => '');
call(installer($tmp, $io), 'reportPendingAdminSetup');
$out = implode("\n", $io->lines);
check('token, no user store → a notice', $io->count('create the first admin') === 1, $out);
check('…its headline is coloured', $io->count('<bg=yellow;fg=black> Next step: create the first admin') === 1, $out);
check('…names the token file', $io->count('data/framework/auth/SETUP_TOKEN') === 1, $out);
check('…names the setup URL and the login link', $io->count('/backend/system/setup/setup') === 1 && $io->count('login page links to it') === 1, $out);
check('…never the token itself', !str_contains($out, $secret));

file_put_contents($tmp . USERS, "[]\n");
$io = new FakeIo(static fn(int $n): string => '');
call(installer($tmp, $io), 'reportPendingAdminSetup');
check('a user store exists → no notice', $io->lines === [], implode(' | ', $io->lines));

@unlink($tmp . USERS);
@unlink($tmp . TOKEN);
$io = new FakeIo(static fn(int $n): string => '');
call(installer($tmp, $io), 'reportPendingAdminSetup');
check('no token → no notice', $io->lines === [], implode(' | ', $io->lines));

// The deferring run prints it too, as its last word: the call closes execute().
$src     = (string) file_get_contents($root . '/packages/kernel/core/src/Installer/Install.php');
$execute = substr($src, strpos($src, 'private function execute(): void'));
$execute = substr($execute, 0, strpos($execute, "\n    }\n"));
check('execute() ends with reportPendingAdminSetup(), after offerDocsInstall()',
    str_ends_with(rtrim($execute), '$this->reportPendingAdminSetup();')
    && strpos($execute, '$this->offerDocsInstall();') < strpos($execute, '$this->reportPendingAdminSetup();'));

// provisionSetupToken() says only one short line mid-run; the how-to is the closing notice.
rmTree($tmp);
@mkdir($tmp, 0777, true);
$io = new FakeIo(static fn(int $n): string => '');
call(installer($tmp, $io), 'provisionSetupToken');
$out = implode("\n", $io->lines);
check('mid-run: token written, one line naming the file, no token', is_file($tmp . TOKEN) && $io->count('SETUP_TOKEN') === 1
    && !str_contains($out, trim((string) file_get_contents($tmp . TOKEN))), $out);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

}
