<?php

/**
 * What a project's whole-file config override lacks (CLI, a temp project) — `.releases/check.php`,
 * `lib.php` `releases_overrideWarnings`, packaging.md PKG-OVERRIDE-001. What is load-bearing: a
 * missing top-level key, `forms` entry and `jobs` entry is named; nothing else is; neither file is
 * executed; the script warns and its exit code does not change; nothing is written; the parser
 * reads every real framework config exactly as PHP does.
 *
 * Run: php tests/releases-override-check.php
 */

$repo = str_replace('\\', '/', dirname(__DIR__));
require $repo . '/vendor/autoload.php';
require $repo . '/.releases/lib.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-override-check-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0777, true);
register_shutdown_function(static function () use ($base): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});
$put = static function (string $file, string $body): void {
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $body);
};

$p = $base . '/project';
// The framework side, in the project's vendor.
$put($p . '/vendor/z77/module-member/src/App/Config/memberConfig.inc.php', <<<'PHP'
<?php
use Z77\Core\Config\AuthRole;
// 'commented' => 1,
return [
    'defaultGroup'  => 'main',
    'loginUrl'      => '/member/main/login',
    'totpIssuer'    => '',
    'jobs' => [
        'member-cleanup' => ['class' => \Foo::class, 'label' => 'Bereinigung', 'runAs' => AuthRole::CRON_JOB],
        'member-digest'  => ['class' => \Bar::class],
    ],
    'controllers' => ['main' => ['LoginController' => ['actions' => ['indexAction' => AuthRole::GUEST]]]],
];
PHP);
$put($p . '/vendor/z77/kernel/shared/src/Config/emailConfig.inc.php', <<<'PHP'
<?php
return [
    'forms' => [
        'contactForm'     => ['to' => ''],
        'backendCodeLock' => ['to' => '', 'recipientsOnly' => true],
    ],
];
PHP);
$put($p . '/vendor/z77/module-frontend/src/Ui/Config/layoutConfig.inc.php', "<?php\nreturn ['documentTpl' => [], 'javascripts' => []];\n");
$put($p . '/vendor/z77/module-frontend/src/App/Config/frontendConfig.inc.php', "<?php\nreturn ['site' => [], 'cache' => []];\n");
$put($p . '/vendor/z77/module-dms/src/App/Config/dmsConfig.inc.php', "<?php\n\$x = 1;\nreturn array('a' => 1, 'b' => array('c' => 2));\n");
$put($p . '/vendor/z77/module-backend/src/App/Config/backendConfig.inc.php', "<?php\nreturn ['a' => 1, 'b' => 2];\n");

// The project side.
$put($p . '/override/z77/module/member/src/App/Config/memberConfig.inc.php', <<<'PHP'
<?php
// A trap: reading this file must not run it.
file_put_contents(__DIR__ . '/SIDE-EFFECT', 'ran');
use Z77\Module\Unknown\Thing;
return [
    'defaultGroup' => 'main',
    'loginUrl'     => Thing::URL,
    'jobs' => [
        'member-cleanup' => ['class' => Thing::class, 'totpIssuer' => 'not the top level'],
    ],
    'controllers' => ['main' => []],
    'projectOwn'  => static function (): array { return ['jobs' => 1]; },
];
PHP);
$put($p . '/override/z77/shared/src/Config/emailConfig.inc.php', "<?php\nreturn ['forms' => ['contactForm' => ['to' => 'x@example.ch']]];\n");
$put($p . '/override/z77/module/frontend/src/Ui/Config/layoutConfig.inc.php', "<?php\nreturn ['documentTpl' => [], 'javascripts' => []];\n");
$put($p . '/override/z77/module/frontend/src/App/Config/frontendConfig.inc.php', "<?php\nreturn array_merge(require 'x.php', ['site' => []]);\n");
$put($p . '/override/z77/module/dms/src/App/Config/dmsConfig.inc.php', "<?php\nreturn array('a' => 2, 'b' => array('c' => 3));\n");
$put($p . '/override/z77/module/backend/src/App/Config/backendConfig.inc.php', "<?php\n\$more = ['b' => 2];\nreturn ['a' => 1] + \$more;\n");
// A controller's config one level down, and the project's own config: not compared.
$put($p . '/override/z77/module/member/src/Ui/Config/Main/fooControllerConfig.inc.php', "<?php\nreturn [];\n");
$put($p . '/override/z77/module/member/src/Ui/Config/Main/Config/nothing.inc.php', "<?php\nreturn [];\n");
$put($p . '/override/z77/emonitor/src/Config/ownConfig.inc.php', "<?php\nreturn [];\n");

$warnings = releases_overrideWarnings($p);
$all      = implode("\n", $warnings);
echo "(A) the findings\n";
$member = 'override/z77/module/member/src/App/Config/memberConfig.inc.php';
check('a missing top-level key is named', str_contains($all, $member . ' replaces the framework file whole and lacks: totpIssuer'), $all);
check('...a key nested deeper does not count as present', !preg_match('/lacks: .*jobs/', $all));
check('a missing job is named', str_contains($all, $member . " lacks these 'jobs' entries: member-digest"), $all);
check('a missing mail form is named', str_contains($all, "override/z77/shared/src/Config/emailConfig.inc.php lacks these 'forms' entries: backendCodeLock"), $all);
check('a complete override says nothing', !str_contains($all, 'layoutConfig'), $all);
check('array() syntax is read', !str_contains($all, 'dmsConfig'), $all);
check('array_merge: not compared, and it says so', str_contains($all, "frontendConfig.inc.php: its return is not a plain array, not compared"), $all);
check('[...] + $x: not compared, and it says so', str_contains($all, "backendConfig.inc.php: its return is not a plain array, not compared"), $all);
check('a controller config one level down is left alone', !str_contains($all, 'fooControllerConfig') && !str_contains($all, 'nothing.inc'), $all);
check('the project\'s own package is skipped', !str_contains($all, 'ownConfig'), $all);
check('the trap did not run: nothing executes the file', !is_file($p . '/override/z77/module/member/src/App/Config/SIDE-EFFECT'));
check('exactly the expected sentences', count($warnings) === 5, $all);

// A nested value that is not a literal: its entries are unknowable, so no false alarm.
$r = $base . '/computed';
$put($r . '/vendor/z77/kernel/shared/src/Config/emailConfig.inc.php', "<?php return ['from' => 'x', 'forms' => ['contactForm' => [], 'backendCodeLock' => []]];");
$put($r . '/vendor/z77/module-member/src/App/Config/memberConfig.inc.php', "<?php return ['jobs' => ['one' => [], 'two' => []], 'a' => 1];");
$put($r . '/override/z77/shared/src/Config/emailConfig.inc.php', "<?php\n\$fw = ['forms' => []];\nreturn ['from' => 'y', 'forms' => \$fw['forms'] + ['eigenes' => []]];\n");
$put($r . '/override/z77/module/member/src/App/Config/memberConfig.inc.php', "<?php\nreturn ['a' => 1, 'jobs' => [...\$more, 'eigener' => []]];\n");
$c = implode("\n", releases_overrideWarnings($r));
check("'forms' => \$fw['forms'] + […]: no missing forms claimed", !str_contains($c, "lacks these 'forms'"), $c);
check('...it says it did not compare them', str_contains($c, "emailConfig.inc.php: its 'forms' is not a plain array, not compared"), $c);
check("'jobs' => [...\$more, …]: no missing jobs claimed", !str_contains($c, "lacks these 'jobs'"), $c);
check('...it says it did not compare them', str_contains($c, "memberConfig.inc.php: its 'jobs' is not a plain array, not compared"), $c);
$put($r . '/override/z77/shared/src/Config/emailConfig.inc.php', "<?php\nreturn ['from' => 'y', 'forms' => ['contactForm' => []] + \$more];\n");
$put($r . '/override/z77/module/member/src/App/Config/memberConfig.inc.php', "<?php\nreturn ['a' => 1, 'jobs' => array_merge(\$a, \$b)];\n");
$c = implode("\n", releases_overrideWarnings($r));
check('a literal with something added behind it, array_merge: not compared either', str_contains($c, "'forms' is not a plain array") && str_contains($c, "'jobs' is not a plain array") && !str_contains($c, 'lacks these'), $c);
$put($r . '/override/z77/shared/src/Config/emailConfig.inc.php', "<?php\nreturn ['from' => 'y', 'forms' => ['contactForm' => []]];\n");
$c = implode("\n", releases_overrideWarnings($r));
check('a plain list is still compared', str_contains($c, "lacks these 'forms' entries: backendCodeLock"), $c);

// ── (B) the framework from a path repository ───────────────────────────────
echo "(B) a path repository\n";
$q = $base . '/repo-project';
$put($q . '/override/z77/module/member/src/App/Config/memberConfig.inc.php', "<?php\nreturn ['jobs' => []];\n");
$put($base . '/source/packages/module-member/composer.json', '{"name":"z77/module-member"}');
$put($base . '/source/packages/module-member/src/App/Config/memberConfig.inc.php', "<?php\nreturn ['jobs' => ['one' => []], 'other' => 1];\n");
$put($q . '/composer.json', json_encode(['repositories' => [['type' => 'path', 'url' => '../source/packages/*']]]));
$rw = implode("\n", releases_overrideWarnings($q));
check('no vendor: read from the path repository', str_contains($rw, 'lacks: other') && str_contains($rw, "'jobs' entries: one"), $rw);
check('no override directory: no findings', releases_overrideWarnings($base . '/source') === []);

// ── (C) the script itself ───────────────────────────────────────────────────
echo "(C) .releases/check.php end to end\n";
$put($p . '/.releases/check.php', (string) file_get_contents($repo . '/.releases/check.php'));
$put($p . '/.releases/lib.php', (string) file_get_contents($repo . '/.releases/lib.php'));
$put($p . '/.releases/target.json', json_encode([
    'host' => 'example-host', 'root' => '/home/konto/public_html/example.ch', 'release_name' => '^\d{4}-\d{2}-\d{2}(-\d+)?$', 'link_target' => 'release',
    'hosts' => ['next' => 'next.example.ch', 'current' => 'example.ch'],
    'shared' => ['data', 'config/client', 'logs', 'var/lib'],
]));
$put($p . '/.vscode/sftp.json', json_encode([
    'host' => 'example-host', 'uploadOnSave' => false, 'remotePath' => '/home/konto/public_html/example.ch/releases/' . date('Y-m-d'),
    'ignore' => ['data/**', 'config/client/**', 'logs/**', 'var/**'],
]));
$put($p . '/composer.json', '{"name":"beispiel/projekt","repositories":[]}');
$put($p . '/vendor/z77/build.json', '{}');

$hashes = static function (string $dir): array {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) {
            $out[str_replace('\\', '/', $f->getPathname())] = sha1_file($f->getPathname());
        }
    }
    ksort($out);
    return $out;
};
$before = $hashes($p);
$run = static function () use ($p): array {
    $proc = proc_open([PHP_BINARY, $p . '/.releases/check.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out  = (string) stream_get_contents($pipes[1]);
    $err  = (string) stream_get_contents($pipes[2]);
    return [proc_close($proc), $out, $err];
};
[$code, $out, $err] = $run();
check('an otherwise clean project: exit 0 in spite of the warnings', $code === 0, "exit {$code}\n{$out}\n{$err}");
check('...the warnings are printed', str_contains($out, 'config override warning(s), they do not fail this check') && str_contains($out, 'lacks: totpIssuer'), $out);
check('...and the verdict stays OK', str_contains($out, 'OK: deploy setup conforms'), $out);
check('...nothing was written, the trap did not run', $hashes($p) === $before);

unlink($p . '/.releases/target.json');
[$code, $out] = $run();
check('a real violation still ends in exit 1, with the warnings in front of it', $code === 1 && str_contains($out, 'lacks: totpIssuer'), "exit {$code}\n{$out}");

$lib = (string) file_get_contents($repo . '/.releases/lib.php');
preg_match('/function releases_overrideWarnings.*?\n}\n/s', str_replace("\r\n", "\n", $lib), $m);
$fn = $m[0] ?? '';
check('the comparison reaches no server and runs nothing', $fn !== '' && !preg_match('/\b(ssh|exec|shell_exec|passthru|proc_open|popen|file_put_contents|unlink|rename|require|include)\b/', $fn), $fn === '' ? 'function not found' : '');

// ── (D) the parser against PHP itself, on every framework config ────────────
echo "(D) the parser against require\n";
$compared = 0;
foreach (glob($repo . '/packages/*/src/App/Config/*.inc.php') ?: [] as $file) {
    $parsed = releases_configKeys((string) file_get_contents($file));
    $real   = require $file;
    $name   = substr($file, strlen($repo) + 1);
    if ($parsed === null) {
        check("{$name}: read", false, 'not a plain array to the parser');
        continue;
    }
    $compared++;
    $stringKeys = array_values(array_filter(array_keys($real), 'is_string'));
    check("{$name}: the same top-level keys", array_keys($parsed) === $stringKeys, json_encode(array_diff($stringKeys, array_keys($parsed))) . json_encode(array_diff(array_keys($parsed), $stringKeys)));
    if (isset($real['jobs']) && is_array($real['jobs'])) {
        check("{$name}: the same jobs", ($parsed['jobs'] ?? []) === array_keys($real['jobs']));
    }
}
check('every module config was compared', $compared >= 10, (string) $compared);
$mail     = $repo . '/packages/kernel/shared/src/Config/emailConfig.inc.php';
$realMail = require $mail;
$parsed   = releases_configKeys((string) file_get_contents($mail));
check('emailConfig: the same forms', ($parsed['forms'] ?? null) === array_keys($realMail['forms']), json_encode($parsed['forms'] ?? null));

echo "\nreleases-override-check: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
