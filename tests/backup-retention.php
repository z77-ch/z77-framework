<?php

/**
 * Retention harness (CLI) — the archive-keeping decision, pure and offline.
 *
 * What is load-bearing here is the LATE-DISCOVERY promise of the tiered form
 * (docs/topics/backup.md): change something on day 0, notice it on day 10 —
 * with «keep the newest N» every retained archive already carries the
 * mistake; with tiers a weekly/monthly state from BEFORE the change is still
 * there. The harness replays exactly that timeline.
 *
 * RetentionPolicy::drops() is a pure names-in/names-out function, so the
 * whole thing runs without a filesystem, a service or a zip.
 *
 * Run: php tests/backup-retention.php
 */

spl_autoload_register(static function (string $class): void {
    $map = ['Z77\\Shared\\' => __DIR__ . '/../packages/kernel/shared/src/'];
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

use Z77\Shared\Backup\RetentionPolicy;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Archive name for a date (daily runs at 03:00 in these timelines). */
function arc(string $date, string $time = '030000'): string
{
    return "{$date}_{$time}_data.zip";
}

/** @return list<string> daily archives, $days of them, ending at $end */
function dailyRun(string $end, int $days): array
{
    $names = [];
    $day   = new DateTimeImmutable($end);
    for ($i = 0; $i < $days; $i++) {
        $names[] = arc($day->format('Y-m-d'));
        $day     = $day->modify('-1 day');
    }
    return $names;
}

function kept(array $names, int|array $retention): array
{
    $dropped = RetentionPolicy::drops($names, $retention);
    $keep    = array_values(array_diff($names, $dropped));
    sort($keep);
    return $keep;
}

echo "1. the integer form behaves exactly as before\n";
$names = dailyRun('2026-08-28', 5);
check('keep newest 3 of 5', kept($names, 3) === [arc('2026-08-26'), arc('2026-08-27'), arc('2026-08-28')]);
check('0 keeps everything', RetentionPolicy::drops($names, 0) === []);
check('an empty tier map keeps everything (never «drop it all»)', RetentionPolicy::drops($names, []) === []);

echo "2. ⚠️ late discovery — the reason tiers exist\n";
// Daily backups for 40 days. The mistake happens on day 30 of the timeline
// (2026-08-18); it is noticed ten days later, on 2026-08-28.
$names  = dailyRun('2026-08-28', 40);
$scheme = ['daily' => 7, 'weekly' => 4, 'monthly' => 12];
$k      = kept($names, $scheme);
$preMistake = array_filter($k, static fn($n) => $n < arc('2026-08-18'));
check('with keep-newest-10, no archive predates the mistake',
    array_filter(kept($names, 10), static fn($n) => $n < arc('2026-08-18')) === []);
check('with tiers, clean states from before the mistake survive', count($preMistake) >= 2);
check('…and the last 7 days are all there',
    count(array_filter($k, static fn($n) => $n >= arc('2026-08-22'))) === 7);

echo "3. tier mechanics\n";
$names = dailyRun('2026-08-28', 40);
$k     = kept($names, ['daily' => 7]);
check('daily 7 keeps exactly the 7 newest days', count($k) === 7 && max($k) === arc('2026-08-28'));
$k = kept($names, ['weekly' => 4]);
check('weekly 4 keeps one archive per ISO week, 4 weeks', count($k) === 4);
check('…each being the newest of its week', in_array(arc('2026-08-28'), $k, true));
$k = kept(dailyRun('2026-01-03', 10), ['weekly' => 2]);
check('the turn of the year buckets by ISO week (Dec 31 + Jan 1 share one)', count($k) === 2);
$k = kept(dailyRun('2026-08-28', 400), ['yearly' => 0]);
check('yearly 0 = unlimited years, one archive per year', count($k) === 2);

echo "4. `last` protects the manual pre-change backup\n";
// 09:00 by hand before a risky change, 15:00 the scheduled run — same day.
$names = [arc('2026-08-28', '090000'), arc('2026-08-28', '150000'), arc('2026-08-27')];
$k     = kept($names, ['daily' => 7]);
check('daily alone thins the morning backup away', !in_array(arc('2026-08-28', '090000'), $k, true));
$k = kept($names, ['last' => 2, 'daily' => 7]);
check('last=2 keeps it', in_array(arc('2026-08-28', '090000'), $k, true));

echo "5. safety rails\n";
$threw = false;
try {
    RetentionPolicy::drops([arc('2026-08-28')], ['montly' => 12]);
} catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'montly');
}
check('a misspelled tier throws instead of silently losing history', $threw);
check('a name without a date is never dropped',
    RetentionPolicy::drops(['kein-datum_data.zip', arc('2026-08-28')], ['daily' => 1]) === []);
check('the freshest archive always survives a tiered prune',
    in_array(arc('2026-08-28'), kept(dailyRun('2026-08-28', 40), ['monthly' => 1]), true));

echo "\n";
echo $fail === 0
    ? "PASS — {$pass} checks\n"
    : "FAIL — {$fail} of " . ($pass + $fail) . " checks failed\n";

exit($fail === 0 ? 0 : 1);
