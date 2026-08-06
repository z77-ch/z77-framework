<?php

/**
 * CLI / cron entry for the B7 cleanup (spec: daily run). Deletes accounts
 * that were never confirmed within the grace period (memberConfig
 * `cleanupAfterDays`, default 30) and every token that can no longer redeem
 * (used, expired, orphaned). 'confirmed' accounts are NEVER touched — they
 * wait for the operator's activate/reject decision.
 *
 * Run from the PROJECT root (the installation whose data/ is cleaned):
 *
 *   php vendor/z77/module-member/bin/member-cleanup.php [--days=N] [--dry-run]
 *
 * Exit codes: 0 = ran (also when nothing was due), 1 = bootstrap failure.
 */

define('ABS_BASE_PATH', str_replace('\\', '/', getcwd()));

if (!is_file(ABS_BASE_PATH . '/vendor/autoload.php')) {
    fwrite(STDERR, "Run from the project root (vendor/autoload.php not found under " . ABS_BASE_PATH . ")\n");
    exit(1);
}
require ABS_BASE_PATH . '/vendor/autoload.php';

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Module\Member\Services\MemberAccounts;
use Z77\Module\Member\Services\TokenService;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;

$days = 30;
$dry  = in_array('--dry-run', $argv, true);
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int)$m[1];
    }
}

$cacheManager = new CacheManager();
$cacheManager->setCacheDir('lib/cache');
DI::getInstance()->set('CacheManager', $cacheManager, true);

$uem      = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
$accounts = new MemberAccounts($uem);
$tokens   = new TokenService($uem);

if ($dry) {
    $cutoff = time() - $days * 86400;
    $due    = 0;
    foreach ($accounts->all() as $account) {
        $created = strtotime((string)$account->getCreatedAt());
        if ($account->isRegistered() && $created !== false && $created < $cutoff) {
            $due++;
            echo "would delete: {$account->getEmail()} (registered {$account->getCreatedAt()})\n";
        }
    }
    echo "dry run — {$due} unconfirmed account(s) older than {$days} days, nothing deleted\n";
    exit(0);
}

$deletedAccounts = $accounts->cleanup($days);
$deletedTokens   = $tokens->purge(array_map(
    static fn($a) => (string)$a->getId(),
    $accounts->all()
));

echo "member-cleanup: {$deletedAccounts} account(s) removed (never confirmed within {$days} days), "
   . "{$deletedTokens} dead token(s) purged\n";
exit(0);
