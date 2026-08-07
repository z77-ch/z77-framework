<?php

/**
 * Manual entry for the B7 cleanup — a thin wrapper around the job of the same
 * name (ADR-031). The rules live in MemberCleanupJob; this file only exists so
 * the sweep can be triggered by hand without going through the queue, and so
 * --dry-run answers straight away.
 *
 * Run from the PROJECT root (the installation whose data/ is cleaned):
 *
 *   php vendor/z77/module-member/bin/member-cleanup.php [--days=N] [--dry-run]
 *
 * For the scheduled run use the job instead — one cron line covers every job:
 *
 *   * * * * * cd /path/to/project && php vendor/bin/z77-run
 *
 * Exit codes: 0 = ran (also when nothing was due), 1 = it could not run.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "member-cleanup: CLI only.\n");
    exit(1);
}

$projectRoot = str_replace('\\', '/', (string) getcwd());
if (!is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run from the project root (vendor/autoload.php not found under {$projectRoot})\n");
    exit(1);
}

define('ABS_BASE_PATH', $projectRoot);
require $projectRoot . '/vendor/autoload.php';

use Z77\Core\Bootstrap;
use Z77\Core\Config\AuthRole;
use Z77\Module\Member\Jobs\MemberCleanupJob;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Jobs\JobContext;

$payload = [];
foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $payload['dryRun'] = true;
    } elseif (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $payload['days'] = (int) $m[1];
    }
}

try {
    (new Bootstrap())->pullUpServices();
} catch (\Throwable $e) {
    fwrite(STDERR, 'member-cleanup: boot failed — ' . $e->getMessage() . "\n");
    exit(1);
}

// A manual run has no cron pass to fit into, so the deadline is generous; the
// job does its three sweeps in one slice either way.
$context = new JobContext(
    'member-cleanup',
    $payload,
    null,
    1,
    time() + 300,
    new AuthUser([
        'id'        => 'cron',
        'user_name' => 'cli:member-cleanup',
        'roles'     => [AuthRole::CRON_JOB],
        'realm'     => AuthUser::REALM_CRON,
    ])
);

try {
    $result = (new MemberCleanupJob())->run($context);
} catch (\Throwable $e) {
    fwrite(STDERR, 'member-cleanup: FAILED — ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($context->getLog() as $line) {
    echo $line . "\n";
}
echo 'member-cleanup: ' . $result->getNote() . "\n";
exit($result->hasFailed() ? 1 : 0);
