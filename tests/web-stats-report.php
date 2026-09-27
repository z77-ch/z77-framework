<?php

/**
 * Web-statistics REPORT harness (CLI) — steps 1.3, 1.4 and 2 of
 * docs/03-development/web-stats-bauplan.md, against a throwaway project tree.
 *
 * What is load-bearing here:
 *
 *   - the SCHEDULE form `monthly@D,HH:MM` (ScheduleExpression): due on day D
 *     of this month or the next, day 1–28 only, the year wrap;
 *   - the TOKEN (ReportToken): mint → verify ok, past the expiry `expired`,
 *     a changed month / expiry / signature / another key `invalid` — and
 *     `invalid` beats `expired` (a forged token never learns it was well
 *     formed), no config = null (link and mail off), a short key throws,
 *     the expiry is the end of the 10th day;
 *   - the REPORT data (StatsReport): the four figures and «Besucher
 *     (Tagessumme)», the comparison only between whole months, `internal`
 *     out of the sources, `other` labelled, declared events in declaration
 *     order with 0 and undeclared ones kept, events per 100 visits, every
 *     day of the month in the curve, a corrupt month THROWS;
 *   - the MAIL job's refusals that need no mail stack: a bad payload month,
 *     no key, no canonicalBaseUrl, a month without data;
 *   - the REGISTRATION: the job without a default schedule, the reserved
 *     route, the link door GUEST and nothing else, the form key seeded in the
 *     framework's emailConfig.
 *
 * The rendered pages and a real send are checked against an installation
 * (see stats.md «Report» → verified), not here.
 *
 * Run: php tests/web-stats-report.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-web-stats-report-' . getmypid();
@mkdir($work . '/config/client', 0777, true);
define('ABS_BASE_PATH', $work);
date_default_timezone_set('Europe/Zurich');

spl_autoload_register(static function (string $class) use ($root): void {
    $map = [
        'Z77\\Module\\Backend\\' => '/packages/module-backend/src/',
        'Z77\\Core\\'            => '/packages/kernel/core/src/',
        'Z77\\Shared\\'          => '/packages/kernel/shared/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Core\Config\AuthRole;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\ScheduleExpression;
use Z77\Shared\Stats\ReportToken;
use Z77\Shared\Stats\StatsReport;
use Z77\Shared\Stats\StatsReportMailJob;
use Z77\Shared\Stats\StatsReportMailer;
use Z77\Shared\Stats\StatsRollup;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $got === '' ? '' : "  — got: {$got}") . "\n";
}
function rrm(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                rrm($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path)) {
        @unlink($path);
    }
}
$ts = static fn (string $local): int => (new DateTimeImmutable($local))->getTimestamp();
$fmt = static fn (int $t): string => date('Y-m-d H:i', $t);

// ── schedule: monthly ──────────────────────────────────────────────────────
echo "schedule monthly@\n";
$monthly = ScheduleExpression::parse('monthly@1,06:00');
check('before 06:00 on the 1st → the same day', $fmt($monthly->nextAfter($ts('2026-10-01 05:59'))) === '2026-10-01 06:00');
check('at 06:00 exactly → next month (strictly after)', $fmt($monthly->nextAfter($ts('2026-10-01 06:00'))) === '2026-11-01 06:00');
check('mid-month → the 1st of next month', $fmt($monthly->nextAfter($ts('2026-09-26 10:00'))) === '2026-10-01 06:00');
check('December → January of the next year', $fmt($monthly->nextAfter($ts('2026-12-15 00:00'))) === '2027-01-01 06:00');
check('the 28th in February', $fmt(ScheduleExpression::parse('monthly@28,23:30')->nextAfter($ts('2027-02-01 00:00'))) === '2027-02-28 23:30');
check('the 28th after the 28th of January → 28 February, no overflow into March',
    $fmt(ScheduleExpression::parse('monthly@28,23:30')->nextAfter($ts('2027-01-29 00:00'))) === '2027-02-28 23:30');
check('the day is 1–28 only (29 refused)', !ScheduleExpression::isValid('monthly@29,06:00'));
check('day 0 refused', !ScheduleExpression::isValid('monthly@0,06:00'));
check('hour 24 refused', !ScheduleExpression::isValid('monthly@1,24:00'));
check('the other forms still parse', ScheduleExpression::isValid('daily@04:40') && ScheduleExpression::isValid('weekly@mon,04:20'));
check('the error names the monthly form', str_contains(ScheduleExpression::FORMS, 'monthly@1,06:00'));

// ── token ──────────────────────────────────────────────────────────────────
echo "token\n";
$key    = str_repeat('k', 40);
$tokens = new ReportToken($key);
$now    = $ts('2026-10-01 06:00');
$exp    = ReportToken::expiresAt($now);
check('expiry = end of the 10th day after today', $fmt($exp) === '2026-10-11 23:59' && date('s', $exp) === '59', date('Y-m-d H:i:s', $exp));
$token = $tokens->mint('2026-09', $exp);
check('token shape: month.expiry.signature', preg_match('~^2026-09\.\d+\.[A-Za-z0-9_-]{43}$~', $token) === 1, $token);
$v = $tokens->verify($token, $now);
check('verify → ok with the month', $v['status'] === ReportToken::OK && $v['month'] === '2026-09' && $v['expiresAt'] === $exp);
check('one second before the expiry still ok', $tokens->verify($token, $exp)['status'] === ReportToken::OK);
check('after the expiry → expired', $tokens->verify($token, $exp + 1)['status'] === ReportToken::EXPIRED);
[$m, $e, $s] = explode('.', $token);
check('another month with the same signature → invalid', $tokens->verify('2026-08.' . $e . '.' . $s, $now)['status'] === ReportToken::INVALID);
check('a longer expiry with the same signature → invalid', $tokens->verify($m . '.' . ($e + 86400) . '.' . $s, $now)['status'] === ReportToken::INVALID);
$flipped = $s;
$flipped[0] = $flipped[0] === 'A' ? 'B' : 'A';
check('a changed signature → invalid', $tokens->verify($m . '.' . $e . '.' . $flipped, $now)['status'] === ReportToken::INVALID);
check('another key → invalid', (new ReportToken(str_repeat('x', 40)))->verify($token, $now)['status'] === ReportToken::INVALID);
check('a forged token past its date → invalid, not expired',
    $tokens->verify($m . '.' . ($e - 999999) . '.' . $s, $now + 99999999)['status'] === ReportToken::INVALID);
check('garbage → invalid', $tokens->verify('../../etc/passwd', $now)['status'] === ReportToken::INVALID
    && $tokens->verify('', $now)['status'] === ReportToken::INVALID);
$threw = false;
try { new ReportToken('short'); } catch (RuntimeException) { $threw = true; }
check('a key under 32 characters throws', $threw);
$threw = false;
try { $tokens->mint('2026-13', $exp); } catch (InvalidArgumentException) { $threw = true; }
check('mint refuses a non-month', $threw);

check('no config file → null (link and mail off)', ReportToken::fromConfig() === null);
file_put_contents($work . '/config/client/stats.inc.php', "<?php return ['reportKey' => ''];");
check('an empty key → null', ReportToken::fromConfig() === null);
file_put_contents($work . '/config/client/stats.inc.php', "<?php return ['reportKey' => '{$key}'];");
check('a key → the service, and it verifies what the test minted',
    ReportToken::fromConfig()?->verify($token, $now)['status'] === ReportToken::OK);
file_put_contents($work . '/config/client/stats.inc.php', "<?php return ['reportKey' => 'tooshort'];");
$threw = false;
try { ReportToken::fromConfig(); } catch (RuntimeException) { $threw = true; }
check('a short key in the config throws (a weak key is no switch)', $threw);
unlink($work . '/config/client/stats.inc.php');

// ── report data ────────────────────────────────────────────────────────────
echo "report\n";
$aggDir = $work . '/' . StatsRollup::AGGREGATE_DIR;
@mkdir($aggDir, 0777, true);
$month = static function (string $month, array $over = []): array {
    return array_replace([
        'month' => $month, 'days_done' => [], 'days' => [], 'views' => 0, 'visitors' => 0, 'visits' => 0, 'capped' => 0,
        'pages' => [], 'entries' => [], 'sources' => [], 'referrers' => [], 'devices' => [], 'browsers' => [],
        'countries' => [], 'languages' => [], 'status' => [], 'campaigns' => [], 'events' => [], 'event_pages' => [],
    ], $over);
};
$save = static function (array $data) use ($aggDir): void {
    file_put_contents($aggDir . '/' . $data['month'] . '.json', json_encode($data, JSON_PRETTY_PRINT));
};

// September: counting started on the 23rd — the installation's first month.
$save($month('2026-09', [
    'days_done' => ['2026-09-23', '2026-09-24'],
    'days'      => ['2026-09-23' => ['views' => 56, 'visitors' => 15, 'visits' => 19, 'events' => 4],
                    '2026-09-24' => ['views' => 44, 'visitors' => 10, 'visits' => 11, 'events' => 2]],
    'views' => 100, 'visitors' => 25, 'visits' => 30,
    'pages' => ['/home' => 40, '/wohnen' => 60],
    'sources' => ['internal' => 50, 'direct' => 30, 'search' => 20],
    'events' => ['floorplan' => 3, 'old-event' => 1],
    'event_pages' => ['floorplan' => ['/wohnen' => 3]],
]));
// October: whole month (September exists), a zero day on the 1st has no entry.
$save($month('2026-10', [
    'days_done' => ['2026-10-02', '2026-10-03'],
    'days'      => ['2026-10-02' => ['views' => 80, 'visitors' => 20, 'visits' => 40, 'events' => 5],
                    '2026-10-03' => ['views' => 70, 'visitors' => 20, 'visits' => 20, 'events' => 0]],
    'views' => 150, 'visitors' => 40, 'visits' => 60,
    'pages' => ['/wohnen' => 90, '/home' => 60],
    'entries' => ['/home' => 45, '/wohnen' => 15],
    'sources' => ['internal' => 90, 'search' => 40, 'direct' => 20],
    'referrers' => ['google.ch' => 38, 'other' => 2],
    'devices' => ['mobile' => 100, 'desktop' => 50],
    'languages' => ['de' => 120, 'fr' => 30],
    'countries' => ['CH' => 140, '??' => 10],
    'events' => ['floorplan' => 6],
    'event_pages' => ['floorplan' => ['/wohnen' => 5, '/home' => 1]],
]));
// November: whole month, compared with October.
$save($month('2026-11', [
    'days_done' => ['2026-11-01'],
    'days'      => ['2026-11-01' => ['views' => 300, 'visitors' => 44, 'visits' => 90, 'events' => 0]],
    'views' => 300, 'visitors' => 44, 'visits' => 90,
]));

$labels  = ['form' => 'Formular abgeschickt', 'floorplan' => 'Grundriss geöffnet'];
$reports = new StatsReport(new StatsRollup($work . '/logs/stats', $aggDir));

check('months newest first', $reports->months() === ['2026-11', '2026-10', '2026-09'], implode(',', $reports->months()));
check('a month without a file → null', $reports->build('2026-08', $labels, $ts('2026-12-02 00:00')) === null);

$sep = $reports->build('2026-09', $labels, $ts('2026-10-01 06:00'));
check('September: label «September 2026»', $sep['monthLabel'] === 'September 2026');
check('September starts late (first counted day 23rd, no August)', $sep['startsLate'] === true && $sep['countedFrom'] === '2026-09-23');
check('September: no comparison (no August)', $sep['previous']['compared'] === false && $sep['kpis'][0]['delta'] === null);
check('the figures in order: Besuche, Seitenaufrufe, Besucher (Tagessumme), Seiten pro Besuch',
    array_column($sep['kpis'], 'label') === ['Besuche', 'Seitenaufrufe', 'Besucher (Tagessumme)', 'Seiten pro Besuch']);
check('Seiten pro Besuch = 100 / 30 = 3.3', $sep['kpis'][3]['value'] === 3.3);
check('the curve has every day of September (30)', count($sep['days']) === 30);
check('an uncounted day is null, not 0', $sep['days'][0]['visits'] === null && $sep['days'][22]['visits'] === 19);
check('the busiest day is 100 %', $sep['days'][22]['percent'] === 100 && $sep['days'][23]['percent'] === 58);
check('internal is not a source', !in_array('internal', array_column($sep['sources'], 'label'), true)
    && array_column($sep['sources'], 'label') === ['Direkt', 'Suchmaschine']);
check('events: declared order, a declared name with 0 shown, an undeclared one kept by name',
    array_column($sep['events'], 'name') === ['form', 'floorplan', 'old-event']
    && $sep['events'][0]['count'] === 0 && $sep['events'][2]['label'] === 'old-event');
check('events per 100 visits: 3 / 30 → 10.0', $sep['events'][1]['per100'] === 10.0);

$oct = $reports->build('2026-10', $labels, $ts('2026-11-01 06:00'));
check('October is whole although its 1st had no visit (September exists)', $oct['startsLate'] === false);
check('October is NOT compared with the partial September', $oct['previous']['compared'] === false);
check('the rest bucket is labelled «übrige»', end($oct['referrers'])['label'] === 'übrige');
check('shares: mobile 67 %', $oct['devices'][0]['label'] === 'Mobiltelefon' && $oct['devices'][0]['percent'] === 67);
check('languages by name', array_column($oct['languages'], 'label') === ['Deutsch', 'Französisch']);
check('an unknown country is «unbekannt»', in_array('unbekannt', array_column($oct['countries'], 'label'), true));
check('the event names its pages', $oct['events'][1]['pages'][0] === ['label' => '/wohnen', 'count' => 5]);

$novRunning = $reports->build('2026-11', $labels, $ts('2026-11-15 12:00'));
check('the running month is never compared', $novRunning['closed'] === false && $novRunning['kpis'][0]['delta'] === null);
$nov = $reports->build('2026-11', $labels, $ts('2026-12-01 06:00'));
check('November (closed, October whole) is compared', $nov['previous']['compared'] === true);
check('Besuche 90 vs 60 → «+ 50 %», trend up', $nov['kpis'][0]['delta'] === '+ 50 %' && $nov['kpis'][0]['trend'] === 'up');
check('Besucher 44 vs 40 → «+ 10 %»', $nov['kpis'][2]['delta'] === '+ 10 %');
check('Seiten pro Besuch 3.3 vs 2.5 → «+ 0,8»', $nov['kpis'][3]['delta'] === '+ 0,8', (string) $nov['kpis'][3]['delta']);
check('headline = the first three figures, formatted', StatsReport::headline($nov) === [
    ['label' => 'Besuche', 'value' => '90'], ['label' => 'Seitenaufrufe', 'value' => '300'], ['label' => 'Besucher (Tagessumme)', 'value' => '44'],
]);
check('Swiss number format', StatsReport::formatNumber(11942) === '11’942' && StatsReport::formatNumber(3.5, 1) === '3,5');

file_put_contents($aggDir . '/2026-12.json', '{"month": "2026-12", torn');
$threw = false;
try { $reports->build('2026-12', $labels); } catch (RuntimeException) { $threw = true; }
check('a corrupt month file THROWS, never an empty report', $threw);
unlink($aggDir . '/2026-12.json');

// ── mail job: the refusals ─────────────────────────────────────────────────
echo "mail job\n";
$ctx = static fn (array $payload): JobContext => new JobContext('stats-report-mail', $payload, null, 1, time() + 50, new AuthUser([
    'id' => 'cron', 'user_name' => 'harness', 'roles' => [AuthRole::CRON_JOB], 'realm' => AuthUser::REALM_CRON,
]));
$job = new StatsReportMailJob();

$r = $job->run($ctx(['month' => '2026-13']));
check('a malformed payload month fails', $r->hasFailed() && str_contains($r->getNote(), 'YYYY-MM'), $r->getNote());
$r = $job->run($ctx(['month' => '2026-10']));
check('no key → fails and says where the key goes', $r->hasFailed() && str_contains($r->getNote(), 'stats.inc.php'), $r->getNote());
file_put_contents($work . '/config/client/stats.inc.php', "<?php return ['reportKey' => '{$key}'];");
$r = $job->run($ctx(['month' => '2026-10']));
check('no canonicalBaseUrl → fails, nothing sent', $r->hasFailed() && str_contains($r->getNote(), 'canonicalBaseUrl'), $r->getNote());
define('CANONICAL_BASE_URL', 'https://example.ch');
$r = $job->run($ctx(['month' => '2026-08']));
check('a month without an aggregate → done, no mail', $r->isDone() && !$r->hasFailed() && str_contains($r->getNote(), 'no mail'), $r->getNote());

$m = (new StatsReportMailer())->send('2026-08');
check('mailer: a month without data → status nothing, no mail', $m['status'] === 'nothing' && $m['expiresAt'] === null, $m['note']);
$m = (new StatsReportMailer())->send('09-2026');
check('mailer: a malformed month → status failed', $m['status'] === 'failed', $m['note']);
$src = (string) file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Service/StatsController.php');
$sendAction = substr($src, (int) strpos($src, 'function sendAction'), 1200);
check('«jetzt senden» sends in the request through StatsReportMailer', str_contains($sendAction, '(new StatsReportMailer())->send($month)'));
check('«jetzt senden» queues nothing (no JobQueue in the controller)', !str_contains($src, 'JobQueue'));
$job = (string) file_get_contents($root . '/packages/kernel/shared/src/Stats/StatsReportMailJob.php');
check('the job delegates to the mailer (one send path)', str_contains($job, '(new StatsReportMailer())->send($month)'));

// ── registration ───────────────────────────────────────────────────────────
echo "registration\n";
$backend = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$entry   = $backend['jobs']['stats-report-mail'] ?? null;
check('job registered with the mail job class', ($entry['class'] ?? null) === StatsReportMailJob::class);
check('job ships NO default schedule (writes outward — a human switches it on)', $entry !== null && !isset($entry['defaultSchedule']));
check('reserved route /stats/report → backend/service/stats/report', ($backend['reservedRoutes']['/stats/report'] ?? null) === [
    'module' => 'backend', 'group' => 'service', 'controller' => 'stats', 'action' => 'report',
]);
check('ReportToken::LINK_PATH is that route', isset($backend['reservedRoutes'][ReportToken::LINK_PATH]));
$acl = $backend['controllers']['service']['StatsController'] ?? [];
check('only the link door is GUEST, the page stays on the ADMIN baseline',
    ($acl['actions'] ?? []) === ['reportAction' => AuthRole::GUEST] && !isset($acl['controllerRole']));
$email = require $root . '/packages/kernel/shared/src/Config/emailConfig.inc.php';
check('form key statsReport seeded with its template', ($email['forms']['statsReport']['template'] ?? null) === ['emails/statsReport', 'Z77\Shared']);
check('the mail template exists', is_file($root . '/packages/kernel/shared/res/view/templates/emails/statsReport.tpl.php'));
$nav = json_decode((string) file_get_contents($root . '/packages/kernel/core/data/framework/routing/navigation.default.json'), true);
$ids = array_column($nav, 'id');
check('navigation seed: «Statistik» under Service, ids unique',
    in_array('stats', array_column($nav, 'key'), true) && count($ids) === count(array_unique($ids)));

// ── cleanup ─────────────────────────────────────────────────────────────────
rrm($work);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
