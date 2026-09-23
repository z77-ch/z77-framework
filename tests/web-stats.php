<?php

/**
 * Web-statistics harness (CLI) — step 1 of docs/03-development/web-stats-bauplan.md
 * (recording + aggregation), against a throwaway project tree.
 *
 * What is load-bearing here:
 *
 *   - the CLASSIFIER: bots by user agent (an empty agent is a bot), the
 *     device class + browser family, the referer classes (direct / internal
 *     / search / social / referral / campaign), the campaign whitelist;
 *   - the SALT: one per day under var/lib/stats, the same key for the same
 *     visitor within a day, a different key the next day (acceptance 2), the
 *     address never in the file, an unwritable directory costs the key only;
 *   - the LINE: exactly the nine fields of the plan, no ip / ua / referer,
 *     an unwritable logs/ never throws (acceptance 6);
 *   - the EVENTS: a declared name is counted, an undeclared one dropped, a
 *     malformed declaration throws (acceptance 4); the event path on the page
 *     view's route (decoded once, UTF-8, language prefix off, alias
 *     canonicalised, `/` for what is no path — E4) and the beacon brake per
 *     address (E3: BEACON_LIMIT_PER_HOUR, IPv6 per /64, fails open);
 *   - the DOOR (StatsRecorder::observe): a cache HIT and a 304 are counted,
 *     the backend, an editor session, a bot, a Fetch call, a POST, a redirect
 *     and a page marked data-stats="off" are not (acceptance 1, 3);
 *   - the ROLLUP: closed days folded once, today's file left alone, the raw
 *     file older than 7 days deleted and the aggregate kept, the abuse caps
 *     (acceptance 5), MAX_KEYS_PER_DAY with a reported overflow (E3), events
 *     per page (E4), the aggregate retention (24 files incl. the running
 *     month — B11), the loud failures (a corrupt month file blocks its month,
 *     an unreadable day file is never marked done — B5), and the legacy
 *     directory logs/ read beside logs/stats/ (E2 transition);
 *   - a real SAPI run (PHP's built-in server): a cache HIT answers 200 + HIT
 *     and is counted, a 304 is counted as 304, an unwritable logs/ still
 *     answers the full page, the beacon collapses a single- and a
 *     double-encoded path onto one key, the deny .htaccess is seeded (B7);
 *   - the hook position in the Dispatcher (after send(), outside
 *     resolveResponse()), the job registration WITH daily@04:40 (E1), the
 *     type-checked endpoint (B6), the fixed backup exclude logs/stats (E2),
 *     the removed just-in-case helpers (B10);
 *   - a timing of the per-request cost (classification + append, and with
 *     the GeoIP database of the reference installation when present).
 *
 * Run: php tests/web-stats.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-web-stats-' . getmypid();
@mkdir($work, 0777, true);
define('ABS_BASE_PATH', $work);
date_default_timezone_set('Europe/Zurich');

spl_autoload_register(static function (string $class) use ($root): void {
    $map = [
        'Z77\\Module\\Frontend\\' => '/packages/module-frontend/src/',
        'Z77\\Module\\Backend\\'  => '/packages/module-backend/src/',
        'Z77\\Core\\'             => '/packages/kernel/core/src/',
        'Z77\\Shared\\'           => '/packages/kernel/shared/src/',
        'Z77\\Persistence\\'      => '/packages/kernel/persistence/src/',
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

use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\NoContentResponse;
use Z77\Core\Http\Response\RedirectResponse;
use Z77\Core\Libraries\Cache\PageCache;
use Z77\Core\Libraries\Cache\PageIdentity;
use Z77\Shared\GeoIp\CountryLookup;
use Z77\Shared\Stats\StatsClassifier;
use Z77\Shared\Stats\StatsEvents;
use Z77\Shared\Stats\StatsHit;
use Z77\Shared\Stats\StatsRecorder;
use Z77\Shared\Stats\StatsRollup;
use Z77\Shared\Stats\StatsRollupJob;
use Z77\Shared\Stats\StatsSalt;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function rrm(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') { rrm($path . '/' . $e); }
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
}

/** @return list<array<string, mixed>> */
function lines(?string $file): array
{
    if ($file === null || !is_file($file)) {
        return [];
    }
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $rows[] = json_decode($line, true);
    }
    return $rows;
}

const UA_CHROME  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
const UA_IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const UA_ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Mobile Safari/537.36';
const UA_TABLET  = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const UA_IPAD    = 'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';
const UA_EDGE    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0';
const UA_FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0';
const UA_SAFARI  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

// ── A. the classifier ───────────────────────────────────────────────────────
echo "StatsClassifier\n";
check('empty user agent is a bot', StatsClassifier::isBot(null) && StatsClassifier::isBot('  '));
check('Googlebot is a bot', StatsClassifier::isBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'));
check('curl is a bot', StatsClassifier::isBot('curl/8.4.0'));
check('an uptime monitor is a bot', StatsClassifier::isBot('UptimeRobot/2.0'));
check('a browser is not a bot', !StatsClassifier::isBot(UA_CHROME) && !StatsClassifier::isBot(UA_IPHONE) && !StatsClassifier::isBot(UA_SAFARI));

$device = fn(string $ua): string => StatsClassifier::device($ua);
check('desktop chrome', $device(UA_CHROME) === 'desktop/chrome', $device(UA_CHROME));
check('mobile safari (iPhone)', $device(UA_IPHONE) === 'mobile/safari', $device(UA_IPHONE));
check('mobile chrome (Android phone)', $device(UA_ANDROID) === 'mobile/chrome', $device(UA_ANDROID));
check('tablet chrome (Android without Mobile)', $device(UA_TABLET) === 'tablet/chrome', $device(UA_TABLET));
check('tablet safari (iPad)', $device(UA_IPAD) === 'tablet/safari', $device(UA_IPAD));
check('edge before chrome', $device(UA_EDGE) === 'desktop/edge', $device(UA_EDGE));
check('firefox', $device(UA_FIREFOX) === 'desktop/firefox', $device(UA_FIREFOX));
check('desktop safari', $device(UA_SAFARI) === 'desktop/safari', $device(UA_SAFARI));
check('unknown agent → desktop/other', $device('Something/1.0') === 'desktop/other', $device('Something/1.0'));

$src = fn(?string $ref, array $q = []): string => StatsClassifier::source($ref, 'www.kunde.ch', $q);
check('no referer → direct', $src(null) === 'direct');
check('own host → internal (www stripped)', $src('https://kunde.ch/angebot') === 'internal' && $src('https://www.kunde.ch/') === 'internal');
check('google → search:google.ch (class + host, path dropped)', $src('https://www.google.ch/search?q=secret') === 'search:google.ch', $src('https://www.google.ch/search?q=secret'));
check('bing → search:bing.com', $src('https://www.bing.com/search?q=x') === 'search:bing.com');
check('duckduckgo → search:duckduckgo.com', $src('https://duckduckgo.com/') === 'search:duckduckgo.com');
check('linkedin → social:linkedin.com', $src('https://www.linkedin.com/feed/') === 'social:linkedin.com');
check('t.co → social:t.co', $src('https://t.co/abc') === 'social:t.co');
check('instagram (subdomain) → social:l.instagram.com', $src('https://l.instagram.com/?u=x') === 'social:l.instagram.com');
check('other site → referral:host, lower-case, no port, no www', $src('https://WWW.ImmoScout24.ch:443/de/d/x') === 'referral:immoscout24.ch', $src('https://WWW.ImmoScout24.ch:443/de/d/x'));
check('splitSource: class + host', StatsClassifier::splitSource('search:google.ch') === ['search', 'google.ch'] && StatsClassifier::splitSource('referral:a.b') === ['referral', 'a.b']);
check('splitSource: no host for direct / internal / campaign', StatsClassifier::splitSource('direct') === ['direct', null] && StatsClassifier::splitSource('campaign:nl') === ['campaign', null]);
check('garbage referer → direct', $src('not a url') === 'direct', $src('not a url'));
check('utm_source wins over the referer (no host kept then)', $src('https://www.google.ch/', ['utm_source' => 'Newsletter Sept']) === 'campaign:newsletter-sept', $src('https://www.google.ch/', ['utm_source' => 'Newsletter Sept']));
check('utm_campaign alone → campaign', $src(null, ['utm_campaign' => 'launch']) === 'campaign:launch');
// B9: the referring host is validated as a HOSTNAME — what does not look like one is `referral` without a host
check('B9: a referring host that is not a hostname is `referral` without a host', $src('https://a"b_c.example/x') === 'referral' && $src('http://192.0.2.7/x') === 'referral' && $src('https://xn--bcher-kva.example/') === 'referral:xn--bcher-kva.example', $src('https://a"b_c.example/x'));

$cq = fn(array $q): string => StatsClassifier::campaignQuery($q);
check('campaign keys kept, sorted, other keys dropped', $cq(['utm_source' => 'nl', 'id' => '42', 'utm_campaign' => 'x', 'token' => 'secret']) === 'utm_campaign=x&utm_source=nl', $cq(['utm_source' => 'nl', 'id' => '42', 'utm_campaign' => 'x', 'token' => 'secret']));
check('no campaign keys → empty', $cq(['q' => 'search term', 'page' => '2']) === '');
check('a long campaign value is truncated', strlen($cq(['utm_source' => str_repeat('a', 200)])) === strlen('utm_source=') + StatsClassifier::CAMPAIGN_VALUE_MAX);
// B8: an address in utm_* is never a campaign name (newsletter tools that put the recipient into utm)
check('B8: an e-mail address in utm_* becomes `unknown` — in the source and in the path', $src(null, ['utm_source' => 'peter.ruepp@gmail.com']) === 'campaign:unknown' && $cq(['utm_source' => 'peter.ruepp@gmail.com', 'utm_campaign' => 'launch']) === 'utm_campaign=launch&utm_source=unknown', $src(null, ['utm_source' => 'peter.ruepp@gmail.com']) . ' ' . $cq(['utm_source' => 'peter.ruepp@gmail.com']));

check('visitor key: 16 hex', preg_match('~^[0-9a-f]{16}$~', (string)StatsClassifier::visitor('1.2.3.4', UA_CHROME, 'salt')) === 1);
check('visitor key: different salt → different key', StatsClassifier::visitor('1.2.3.4', UA_CHROME, 'a') !== StatsClassifier::visitor('1.2.3.4', UA_CHROME, 'b'));
check('visitor key: no ip or no salt → null', StatsClassifier::visitor(null, UA_CHROME, 'a') === null && StatsClassifier::visitor('1.2.3.4', UA_CHROME, null) === null);

// ── B. the salt ─────────────────────────────────────────────────────────────
echo "StatsSalt\n";
StatsSalt::forget();
$today = date('Y-m-d');
$s1 = StatsSalt::forDay($today);
check('salt created under var/lib/stats/salt', is_file($work . '/var/lib/stats/salt') && is_string($s1) && strlen($s1) === 64);
StatsSalt::forget();
check('same day → same salt (re-read from disk)', StatsSalt::forDay($today) === $s1);
$stored = (string)file_get_contents($work . '/var/lib/stats/salt');
check('file carries day + salt only', preg_match('~^\d{4}-\d{2}-\d{2} [0-9a-f]{64}\s*$~', $stored) === 1, $stored);
StatsSalt::forget();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$s2 = StatsSalt::forDay($tomorrow);
check('next day → rotated, different salt', is_string($s2) && $s2 !== $s1);
check('yesterday\'s salt is gone from disk', !str_contains((string)file_get_contents($work . '/var/lib/stats/salt'), (string)$s1));
$k1 = StatsClassifier::visitor('203.0.113.7', UA_CHROME, $s1);
$k2 = StatsClassifier::visitor('203.0.113.7', UA_CHROME, $s2);
check('acceptance 2: the same visitor is one key within a day', $k1 === StatsClassifier::visitor('203.0.113.7', UA_CHROME, $s1));
check('acceptance 2: the same visitor cannot be matched to the day before', $k1 !== $k2);
StatsSalt::forget();

// unwritable: a FILE where the directory should be
rrm($work . '/var/lib/stats');
file_put_contents($work . '/var/lib/stats', 'blocker');
check('unwritable salt directory → null, no throw', StatsSalt::forDay($today) === null);
StatsSalt::forget();
@unlink($work . '/var/lib/stats');

// ── C. the line ─────────────────────────────────────────────────────────────
echo "StatsRecorder::record\n";
$hit = fn(array $o = []): StatsHit => new StatsHit(
    path:      $o['path'] ?? '/angebot',
    query:     $o['query'] ?? [],
    status:    $o['status'] ?? 200,
    lang:      $o['lang'] ?? 'de',
    ip:        array_key_exists('ip', $o) ? $o['ip'] : '203.0.113.7',
    userAgent: array_key_exists('ua', $o) ? $o['ua'] : UA_IPHONE,
    referer:   $o['referer'] ?? 'https://www.google.ch/',
    host:      'www.kunde.ch',
    event:     $o['event'] ?? StatsRecorder::PAGE_EVENT,
);
$file = StatsRecorder::file();
@unlink($file);
check('record() writes a line', StatsRecorder::record($hit()) && count(lines($file)) === 1);
$row = lines($file)[0];
check('exactly the nine fields of the plan, in order', array_keys($row) === ['at', 'path', 'status', 'lang', 'visitor', 'source', 'device', 'country', 'event'], implode(',', array_keys($row)));
check('no ip, no user agent, no referring URL in the line (the host only)', !str_contains((string)file_get_contents($file), '203.0.113.7') && !str_contains((string)file_get_contents($file), 'iPhone') && !str_contains((string)file_get_contents($file), 'https://www.google.ch'));
check('classified at request time', $row['source'] === 'search:google.ch' && $row['device'] === 'mobile/safari' && $row['event'] === 'page' && $row['lang'] === 'de' && $row['status'] === 200);
check('country is null without a database (not a throw)', array_key_exists('country', $row) && $row['country'] === null);
check('visitor is a 16-hex key', preg_match('~^[0-9a-f]{16}$~', (string)$row['visitor']) === 1);
check('at is ISO-8601', preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$~', (string)$row['at']) === 1, (string)$row['at']);

StatsRecorder::record($hit(['path' => '/angebot', 'query' => ['utm_source' => 'nl', 'id' => '9']]));
$row = lines($file)[1];
check('path keeps the campaign keys only', $row['path'] === '/angebot?utm_source=nl' && $row['source'] === 'campaign:nl', $row['path']);
StatsRecorder::record($hit(['path' => str_repeat('/x', 300)]));
check('an overlong path is truncated', strlen(lines($file)[2]['path']) === StatsRecorder::PATH_MAX);
StatsRecorder::record($hit(['ip' => null]));
check('no address → line with visitor null', lines($file)[3]['visitor'] === null);
$two = fn() => StatsRecorder::record($hit()) && StatsRecorder::record($hit());
$before = count(lines($file));
$two();
$rows = lines($file);
check('two requests of one visitor on one day carry one key', $rows[$before]['visitor'] === $rows[$before + 1]['visitor']);
check('E2: file is logs/stats/stats-YYYY-MM-DD.jsonl — its own directory, so the backup can leave it out', basename((string)$file) === 'stats-' . date('Y-m-d') . '.jsonl' && str_ends_with(str_replace('\\', '/', dirname((string)$file)), '/logs/stats'), (string)$file);
$deny = fn(string $dir): bool => is_file($dir . '/.htaccess') && str_contains((string)file_get_contents($dir . '/.htaccess'), 'Require all denied');
check('B7: the runtime-created directory carries the deny .htaccess (logs/stats and logs/ alike)', $deny($work . '/logs/stats') && $deny($work . '/logs'));
check('B10: no just-in-case helpers — StatsEvents::label() is gone, file() takes no day', !method_exists(StatsEvents::class, 'label') && (new ReflectionMethod(StatsRecorder::class, 'file'))->getNumberOfParameters() === 0);

// acceptance 6: unwritable logs/
rrm($work . '/logs');
file_put_contents($work . '/logs', 'blocker');
$ok = true;
try { $written = StatsRecorder::record($hit()); } catch (\Throwable $e) { $ok = false; }
check('acceptance 6: unwritable logs/ → false, no throw', $ok && $written === false);
@unlink($work . '/logs');

// ── D. events ───────────────────────────────────────────────────────────────
echo "StatsEvents\n";
$declared = StatsEvents::fromSources(['a' => ['floorplan-pdf' => 'Grundriss-PDF', 'form-submitted' => 'Formular'], 'b' => ['floorplan-pdf' => 'Grundriss-PDF']]);
check('sources merge; the same label twice is fine', $declared === ['floorplan-pdf' => 'Grundriss-PDF', 'form-submitted' => 'Formular']);
$throws = function (array $sources): bool { try { StatsEvents::fromSources($sources); return false; } catch (\RuntimeException) { return true; } };
check('a list instead of a map throws', $throws(['x' => ['a', 'b']]));
check('an invalid name throws', $throws(['x' => ['Floor Plan' => 'l']]) && $throws(['x' => ['' => 'l']]) && $throws(['x' => [str_repeat('a', 41) => 'l']]));
check('the reserved name page throws', $throws(['x' => ['page' => 'l']]));
check('an empty label throws', $throws(['x' => ['a' => '']]));
check('a conflicting label throws', $throws(['a' => ['e' => 'one'], 'b' => ['e' => 'two']]));
check('nothing declared → empty map', StatsEvents::fromSources([]) === []);

// ── DI doubles for the doors ────────────────────────────────────────────────
final class FakeConfig { public function __construct(private array $d) {} public function get(string|array $k, mixed $default = null): mixed { return $this->d[$k] ?? $default; } }
final class FakeModules
{
    public function getModuleKeys(): array { return ['frontend', 'backend']; }
    public function getModuleConfig(string $key): ?FakeConfig
    {
        return match ($key) {
            'frontend' => new FakeConfig(['public' => true]),
            'backend'  => new FakeConfig(['public' => false]),
            default    => null,
        };
    }
    public function getConfigExtensions(string $module, string $key): array { return []; }
}
DI::getInstance(true)->set('ModuleManager', new FakeModules(), true);

/** A request as the Dispatcher sees it after parsing. */
$request = static function (array $o = []): Request {
    $ref = new ReflectionClass(Request::class);
    $req = $ref->newInstanceWithoutConstructor();
    foreach ([
        'pathSegments' => $o['segments'] ?? ['angebot'],
        'language'     => $o['lang'] ?? 'de',
        'method'       => $o['method'] ?? 'get',
        'mode'         => $o['mode'] ?? RequestMode::Page,
        'module'       => $o['module'] ?? 'frontend',
    ] as $prop => $value) {
        $ref->getProperty($prop)->setValue($req, $value);
    }
    return $req;
};
$_SERVER['HTTP_USER_AGENT'] = UA_CHROME;
$_SERVER['HTTP_REFERER']    = 'https://www.linkedin.com/';
$_SERVER['HTTP_HOST']       = 'www.kunde.ch';
$_SERVER['REMOTE_ADDR']     = '198.51.100.9';
$_GET = [];

echo "StatsRecorder::event\n";
StatsEvents::use(['floorplan-pdf' => 'Grundriss-PDF geöffnet', 'application-link' => 'Bewerbungslink']);
$file = StatsRecorder::file();
@unlink($file);
check('acceptance 4: a declared event is counted', StatsRecorder::event('floorplan-pdf', '/angebot/haus-a', $request(), false) && lines($file)[0]['event'] === 'floorplan-pdf' && lines($file)[0]['path'] === '/angebot/haus-a');
check('acceptance 4: an undeclared name is dropped', !StatsRecorder::event('anything', '/angebot', $request(), false) && !StatsRecorder::event('page', '/angebot', $request(), false) && count(lines($file)) === 1);
$_SERVER['HTTP_USER_AGENT'] = 'curl/8.0';
check('a bot cannot post events', !StatsRecorder::event('floorplan-pdf', '/x', $request(), false) && count(lines($file)) === 1);
$_SERVER['HTTP_USER_AGENT'] = UA_CHROME;
check('an editor session posts no events', !StatsRecorder::event('floorplan-pdf', '/x', $request(), true) && count(lines($file)) === 1);
check('a path with query or fragment is cut to the path', StatsRecorder::event('application-link', '/jobs?id=5#top', $request(), false) && lines($file)[1]['path'] === '/jobs');
$last = fn(): array => lines($file)[count(lines($file)) - 1];
check('E4: a value that is not a path falls back to `/` — the event itself still counts', StatsRecorder::event('floorplan-pdf', 'javascript:x', $request(), false) && $last()['path'] === '/' && StatsRecorder::event('floorplan-pdf', '/with space', $request(), false) && $last()['path'] === '/' && StatsRecorder::event('floorplan-pdf', '', $request(), false) && $last()['path'] === '/' && count(lines($file)) === 5, json_encode($last()));
check('an event line carries the beacon\'s own status and no referer class', lines($file)[0]['status'] === 204 && lines($file)[0]['source'] === 'direct');
check('cleanPath keeps a plain path', StatsRecorder::cleanPath('/angebot/haus-a') === '/angebot/haus-a');
// E4: the event path and the page-view path of one page must be the SAME key
check('E4: cleanPath keeps a non-ASCII path, and decodes the browser\'s percent-encoding once (/%C3%BCber-uns → /über-uns)', StatsRecorder::cleanPath('/über-uns') === '/über-uns' && StatsRecorder::cleanPath('/%C3%BCber-uns') === '/über-uns' && StatsRecorder::cleanPath(rawurlencode('/über-uns')) === '/über-uns', var_export(StatsRecorder::cleanPath('/über-uns'), true));
check('E4: cleanPath normalises like the page view — no trailing slash, no double slash, at most PATH_MAX characters', StatsRecorder::cleanPath('/angebot/') === '/angebot' && StatsRecorder::cleanPath('//a//b/') === '/a/b' && StatsRecorder::cleanPath('/') === '/' && mb_strlen((string)StatsRecorder::cleanPath('/' . str_repeat('ü', 300))) === StatsRecorder::PATH_MAX);
check('E4: a control character or invalid UTF-8 falls back to `/`', StatsRecorder::cleanPath("/a\x01b") === '/' && StatsRecorder::cleanPath("/\xC3") === '/' && StatsRecorder::cleanPath('/%00') === '/');
final class FakeI18n { public function getDefaultLanguage(): string { return 'de'; } public function isValidLanguage(string $l): bool { return in_array($l, ['de', 'fr'], true); } }
final class FakeAliases
{
    public function resolve(array $segments, string $language): ?array
    {
        $hit = ($language === 'fr' && $segments === ['contact']) || $segments === ['kontakt'];
        return $hit ? ['navigation' => null, 'slugs' => [], 'canonical' => ['kontakt'], 'localized' => [$language === 'fr' ? 'contact' : 'kontakt']] : null;
    }
}
DI::getInstance()->set('I18n', new FakeI18n(), true);
DI::getInstance()->set('AliasPathResolver', new FakeAliases(), true);
check('E4: the event path takes the page view\'s route — language prefix stripped, alias canonicalised (/fr/contact → /kontakt)', StatsRecorder::event('floorplan-pdf', '/fr/contact', $request(), false) && $last()['path'] === '/kontakt' && StatsRecorder::event('floorplan-pdf', '/kontakt', $request(), false) && $last()['path'] === '/kontakt', json_encode($last()));
check('E4: a non-alias path keeps its spelling, the prefix still goes (/fr/über-uns → /über-uns, /fr → /)', StatsRecorder::event('floorplan-pdf', '/fr/über-uns', $request(), false) && $last()['path'] === '/über-uns' && StatsRecorder::event('floorplan-pdf', '/fr', $request(), false) && $last()['path'] === '/', json_encode($last()));
// E3: the visitor key is client-influenced (the user agent is in it), so the beacon gets a brake per ADDRESS
@unlink($file);
$limit = defined(StatsRecorder::class . '::BEACON_LIMIT_PER_HOUR') ? StatsRecorder::BEACON_LIMIT_PER_HOUR : 0;
$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
$allowed = 0;
for ($i = 0; $i < $limit + 5; $i++) {
    $_SERVER['HTTP_USER_AGENT'] = UA_CHROME . ' rotated/' . $i;   // a rotating agent = a new visitor key each time
    if (StatsRecorder::event('floorplan-pdf', '/x', $request(), false)) { $allowed++; }
}
$_SERVER['HTTP_USER_AGENT'] = UA_CHROME;
check('E3: the beacon is throttled per address — BEACON_LIMIT_PER_HOUR lines from one address and hour, whatever the agent says', $limit > 0 && $allowed === $limit && count(lines($file)) === $limit && is_dir($work . '/var/lib/throttle/stats'), "limit {$limit}, allowed {$allowed}");
$_SERVER['REMOTE_ADDR'] = '203.0.113.51';
check('E3: another address is not affected', StatsRecorder::event('floorplan-pdf', '/x', $request(), false));
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1';
for ($i = 0; $i < $limit; $i++) { StatsRecorder::event('floorplan-pdf', '/x', $request(), false); }
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:bbbb::1';
check('E3: IPv6 is counted per /64 — a second address of the same prefix shares the counter', !StatsRecorder::event('floorplan-pdf', '/x', $request(), false));
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
check('E3: the brake never throws and fails OPEN when var/lib is unusable', (function () use ($work, $request): bool {
    rrm($work . '/var/lib/throttle');
    file_put_contents($work . '/var/lib/throttle', 'blocker');   // a FILE where the directory should be
    set_error_handler(static fn(): bool => true);                // FileThrottle warns on purpose (loud, not silent) before it throws
    try { $ok = StatsRecorder::event('floorplan-pdf', '/x', $request(), false); } catch (\Throwable) { $ok = false; } finally { restore_error_handler(); }
    @unlink($work . '/var/lib/throttle');
    return $ok;
})());
@unlink($file);

// ── E. the Dispatcher's door ────────────────────────────────────────────────
echo "StatsRecorder::observe\n";
$page = fn(string $html = '<!doctype html><html lang="de"><body>Hallo</body></html>'): HtmlResponse => HtmlResponse::fromCache($html, 1700000000);
$count = fn(): int => count(lines(StatsRecorder::file()));
@unlink(StatsRecorder::file());
StatsRecorder::observe($request(), $page(), false);
check('acceptance 1: a page served from the page cache is counted', $count() === 1);
$row = lines(StatsRecorder::file())[0];
check('the line comes from the Request accessors (path, lang, source, device)', $row['path'] === '/angebot' && $row['lang'] === 'de' && $row['source'] === 'social:linkedin.com' && $row['device'] === 'desktop/chrome', json_encode($row));
// After an alias hit the Request holds the CANONICAL segments (`kontakt` for
// `/fr/contact`) and the language apart — the line follows that split.
StatsRecorder::observe($request(['segments' => ['kontakt'], 'lang' => 'fr']), $page(), false);
check('the canonical path + the language, not the localized URL', lines(StatsRecorder::file())[1]['path'] === '/kontakt' && lines(StatsRecorder::file())[1]['lang'] === 'fr', json_encode(lines(StatsRecorder::file())[1]));
StatsRecorder::observe($request(), HtmlResponse::notModified(1700000000), false);
check('a 304 (browser revalidation) is a counted visit', $count() === 3);
StatsRecorder::observe($request(['module' => 'backend']), $page(), false);
check('acceptance 3: the backend produces no line', $count() === 3);
StatsRecorder::observe($request(), $page(), true);
check('acceptance 3: a signed-in editor/admin produces no line', $count() === 3);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
StatsRecorder::observe($request(), $page(), false);
check('acceptance 3: a known bot produces no line', $count() === 3);
$_SERVER['HTTP_USER_AGENT'] = UA_CHROME;
StatsRecorder::observe($request(['mode' => RequestMode::Fetch]), $page(), false);
check('a Fetch-mode request (partial, XHR) is not a page view', $count() === 3);
StatsRecorder::observe($request(['method' => 'post']), $page(), false);
StatsRecorder::observe($request(['method' => 'head']), $page(), false);
check('POST and HEAD are not page views', $count() === 3);
StatsRecorder::observe($request(), new RedirectResponse('/login'), false);
StatsRecorder::observe($request(), new NoContentResponse(), false);
check('a redirect or a 204 is not a page view', $count() === 3);
StatsRecorder::observe($request(), $page('<!doctype html><html lang="de" data-stats="off"><body>intern</body></html>'), false);
check('a page marked data-stats="off" is not counted', $count() === 3);
StatsRecorder::observe($request(), $page('<!doctype html><html><body>' . str_repeat('x', 5000) . 'data-stats="off"</body></html>'), false);
check('the mark counts only in the head of the document (first 4 KB)', $count() === 4);
StatsRecorder::observe($request(['module' => 'nope']), $page(), false);
check('an unknown module (no config) is not counted, and does not throw', $count() === 4);
$_GET = ['utm_source' => 'NL', 'secret' => 'x'];
StatsRecorder::observe($request(), $page(), false);
check('the query reaches the line only as campaign keys', lines(StatsRecorder::file())[4]['path'] === '/angebot?utm_source=nl' && !str_contains((string)file_get_contents((string)StatsRecorder::file()), 'secret'));
$_GET = [];

// ── F. the hook, the registration, the endpoint ─────────────────────────────
echo "wiring\n";
$dispatcher = (string)file_get_contents($root . '/packages/kernel/core/src/Routing/Dispatcher.php');
$sendPos    = strpos($dispatcher, '$response->send();');
$hookPos    = strpos($dispatcher, '$this->countVisit($request, $response);');
$resolvePos = strpos($dispatcher, 'private function resolveResponse(');
check('the hook sits right after send() in execute(), not inside resolveResponse()', $sendPos !== false && $hookPos !== false && $hookPos > $sendPos && $hookPos < $resolvePos);
check('the hook is fenced (try/catch) and skips the stateless path', preg_match('~function countVisit\(.*?if \(\$this->stateless\).*?try \{.*?StatsRecorder::observe.*?catch \(\\\\Throwable\)~s', $dispatcher) === 1);
$jobs = (require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php')['jobs'];
check('stats-rollup is registered by backendConfig', ($jobs['stats-rollup']['class'] ?? null) === StatsRollupJob::class);
check('E1: stats-rollup SHIPS daily@04:40 — here the deletion IS the privacy promise (JOBS-SCHED-001)', ($jobs['stats-rollup']['defaultSchedule'] ?? null) === 'daily@04:40', var_export($jobs['stats-rollup']['defaultSchedule'] ?? null, true));
check('StatsRollupJob implements Job', is_subclass_of(StatsRollupJob::class, \Z77\Shared\Jobs\Job::class));
$action = new ReflectionMethod(\Z77\Module\Frontend\Ui\Controllers\Main\StatsController::class, 'eventAction');
$ctrlSrc = (string)file_get_contents($action->getFileName());
check('the endpoint is GET-only (a beacon carries no CSRF header)', ($action->getAttributes(\Z77\Shared\Attributes\HttpMethod::class)[0] ?? null)?->newInstance()->methods === ['GET']);
check('the endpoint answers 204 through the helper', (string)$action->getReturnType() === NoContentResponse::class && str_contains($ctrlSrc, '$this->noContent()'));
check('B6: the endpoint type-checks its parameters instead of casting them (event[]=x would be "Array" + E_WARNING)', !str_contains($ctrlSrc, '(string)$request->getGetParameter') && preg_match('~is_string\(\$name\)~', $ctrlSrc) === 1 && preg_match('~is_string\(\$path\)~', $ctrlSrc) === 1);
check('Request exposes the three accessors the recorder reads', method_exists(Request::class, 'getUserAgent') && method_exists(Request::class, 'getReferer') && method_exists(Request::class, 'getHost'));
// E2: the raw lines never ride into an archive — and not through the seed-once config (BACKUP-LIB-001)
$backup = new \Z77\Shared\Backup\BackupService($work, ['fullExcludes' => ['vendor']]);
check('E2: logs/stats is excluded from the full backup unconditionally, next to the operator\'s own list', in_array('logs/stats', $backup->fullExcludes(), true) && in_array('vendor', $backup->fullExcludes(), true) && !in_array('logs', $backup->fullExcludes(), true), json_encode($backup->fullExcludes()));

// ── G. the rollup ───────────────────────────────────────────────────────────
echo "StatsRollup\n";
$rawDir    = $work . '/logs/stats';
$legacyDir = $work . '/logs';          // where the raw files lived until 2026-09-23 (E2 transition)
$aggDir    = $work . '/data/framework/stats';
rrm($legacyDir); rrm($aggDir);
@mkdir($rawDir, 0777, true);
$now  = mktime(4, 40, 0, 9, 23, 2026);   // the rollup runs on 2026-09-23 04:40
$day  = fn(int $minus): string => date('Y-m-d', $now - $minus * 86400);
$line = fn(string $d, array $o = []): string => json_encode([
    'at' => $d . 'T10:00:00+02:00', 'path' => $o['path'] ?? '/', 'status' => $o['status'] ?? 200, 'lang' => $o['lang'] ?? 'de',
    'visitor' => array_key_exists('visitor', $o) ? $o['visitor'] : 'aaaaaaaaaaaaaaaa', 'source' => $o['source'] ?? 'direct',
    'device' => $o['device'] ?? 'desktop/chrome', 'country' => array_key_exists('country', $o) ? $o['country'] : 'CH', 'event' => $o['event'] ?? 'page',
]) . "\n";

// yesterday: 2 visitors, 3 views, 1 declared event, one visitor flooding the beacon
$y = $day(1);
$content  = $line($y, ['path' => '/', 'source' => 'search:google.ch', 'device' => 'mobile/safari', 'lang' => 'de']);
$content .= $line($y, ['path' => '/angebot?utm_source=nl', 'visitor' => 'bbbbbbbbbbbbbbbb', 'country' => 'DE', 'lang' => 'fr', 'status' => 304]);
$content .= $line($y, ['path' => '/angebot', 'visitor' => 'bbbbbbbbbbbbbbbb', 'country' => null]);
$content .= $line($y, ['event' => 'form-submitted', 'status' => 204, 'path' => '/kontakt']);
$content .= "not json\n";
$flood = 150;   // beacon flood by one client: only EVENT_DAY_CAP of these count
for ($i = 0; $i < $flood; $i++) {
    $content .= $line($y, ['event' => 'floorplan-pdf', 'visitor' => 'cccccccccccccccc', 'status' => 204, 'path' => '/angebot/haus-a']);
}
file_put_contents($rawDir . '/stats-' . $y . '.jsonl', $content);
// eight days ago: past the raw retention
file_put_contents($rawDir . '/stats-' . $day(8) . '.jsonl', $line($day(8)) . $line($day(8), ['visitor' => 'dddddddddddddddd']));
// today: still open
file_put_contents($rawDir . '/stats-' . $day(0) . '.jsonl', $line($day(0)));
// aggregates older than 24 months (2024-08, and 2024-09 = exactly 24 months back), and one just inside
@mkdir($aggDir, 0777, true);
file_put_contents($aggDir . '/2024-08.json', '{"month":"2024-08"}');
file_put_contents($aggDir . '/2024-09.json', '{"month":"2024-09"}');
file_put_contents($aggDir . '/2024-10.json', '{"month":"2024-10"}');

$rollup = new StatsRollup($rawDir, $aggDir, $legacyDir);
$r = $rollup->run($now);
check('two closed days folded, today left alone', $r['folded'] === [$day(8), $day(1)] && is_file($rawDir . '/stats-' . $day(0) . '.jsonl'), json_encode($r));
check('acceptance 5: the raw file older than 7 days is deleted', !is_file($rawDir . '/stats-' . $day(8) . '.jsonl') && $r['rawDeleted'] === 1);
check('acceptance 5: yesterday\'s raw file stays (within 7 days)', is_file($rawDir . '/stats-' . $y . '.jsonl'));
$m = json_decode((string)file_get_contents($aggDir . '/' . substr($y, 0, 7) . '.json'), true);
check('acceptance 5: the aggregate exists and lists the folded days', is_array($m) && in_array($y, $m['days_done'], true));
check('day totals: 3 views, 3 distinct visitors, 2 visits, events', ($m['days'][$y] ?? null) === ['views' => 3, 'visitors' => 3, 'visits' => 2, 'events' => 1 + StatsRollup::EVENT_DAY_CAP], json_encode($m['days'][$y] ?? null));
$expectedCapped = $flood - StatsRollup::EVENT_DAY_CAP;
check('abuse cap (events): the flooding client counts EVENT_DAY_CAP events, the rest is capped', ($m['events']['floorplan-pdf'] ?? 0) === StatsRollup::EVENT_DAY_CAP && $m['capped'] === $expectedCapped && $r['capped'] === $expectedCapped, json_encode([$m['events'], $m['capped']]));
check('the two caps: page views 100, beacon events 20 (a claim is bounded tighter than an observation)', StatsRollup::PAGE_VIEW_DAY_CAP === 100 && StatsRollup::EVENT_DAY_CAP === 20 && StatsRollup::EVENT_DAY_CAP < StatsRollup::PAGE_VIEW_DAY_CAP);
// Month totals cover BOTH folded days: yesterday (3 views) and eight days ago (2 views on `/`).
check('pages keyed without the campaign query; campaigns tallied apart', ($m['pages']['/angebot'] ?? 0) === 2 && ($m['pages']['/'] ?? 0) === 3 && ($m['campaigns']['utm_source=nl'] ?? 0) === 1, json_encode($m['pages']));
check('sources by CLASS / devices / browsers / countries / languages / status', $m['sources'] === ['direct' => 4, 'search' => 1] && $m['devices'] === ['desktop' => 4, 'mobile' => 1] && $m['browsers'] === ['chrome' => 4, 'safari' => 1] && $m['countries'] === ['CH' => 3, 'DE' => 1, '??' => 1] && $m['languages'] === ['de' => 4, 'fr' => 1] && $m['status'] === ['200' => 4, '304' => 1], json_encode([$m['sources'], $m['devices'], $m['countries'], $m['status']]));
check('referring hosts by NAME, apart from the class', $m['referrers'] === ['google.ch' => 1], json_encode($m['referrers']));
// the referrer cap: 60 hosts → the 50 most frequent stay, the rest is `other`
$spam = [];
for ($i = 1; $i <= 60; $i++) { $spam['spam-' . $i . '.example'] = $i; }
$spam[StatsRollup::REST_KEY] = 7;
$capped = StatsRollup::capTally($spam, StatsRollup::MAX_REFERRERS_PER_MONTH);
check('capTally keeps the top 50 and sums the rest into `other` (an English data key)', StatsRollup::REST_KEY === 'other' && count($capped) === 51 && array_key_first($capped) === 'spam-60.example' && !isset($capped['spam-10.example']) && $capped[StatsRollup::REST_KEY] === 7 + array_sum(range(1, 10)), json_encode(array_slice($capped, -3, 3, true)));
check('capTally with nothing to fold adds no rest bucket', StatsRollup::capTally(['a' => 2, 'b' => 1], 5) === ['a' => 2, 'b' => 1]);
check('the malformed line is skipped, not fatal', $r['lines'] === 4 + $flood + 2);
check('E4: events are counted per PATH as well — «on which page was the floor plan opened» — under the same caps', ($m['event_pages'] ?? null) === ['floorplan-pdf' => ['/angebot/haus-a' => StatsRollup::EVENT_DAY_CAP], 'form-submitted' => ['/kontakt' => 1]], json_encode($m['event_pages'] ?? null));
check('month visitors = sum of daily distinct (keys are per-day salted)', $m['visitors'] === array_sum(array_column($m['days'], 'visitors')));
check('month visits = sum of the days; entries = one per visit', $m['visits'] === 4 && $m['visits'] === array_sum(array_column($m['days'], 'visits')) && array_sum($m['entries']) === 4 && $m['entries'] === ['/' => 3, '/angebot' => 1], json_encode($m['entries']));

// ── visits and entry pages (a fixture of its own, so the month totals above stay exact) ──
$vRaw = $work . '/visits/logs';
$vAgg = $work . '/visits/data';
@mkdir($vRaw, 0777, true);
$vd  = $day(2);
$vLine = function (string $time, array $o = []) use ($vd): string {
    $row = [
        'at' => $vd . 'T' . $time . '+02:00', 'path' => $o['path'] ?? '/', 'status' => $o['status'] ?? 200, 'lang' => 'de',
        'visitor' => array_key_exists('visitor', $o) ? $o['visitor'] : 'v1v1v1v1v1v1v1v1', 'source' => 'direct',
        'device' => 'desktop/chrome', 'country' => 'CH', 'event' => $o['event'] ?? 'page',
    ];
    return json_encode($row) . "\n";
};
file_put_contents($vRaw . '/stats-' . $vd . '.jsonl',
    $vLine('10:00:00', ['path' => '/a'])                                                   // v1: visit 1, entry /a
  . $vLine('10:10:00', ['path' => '/b'])                                                   // v1: 10 min later → same visit
  . $vLine('12:00:00', ['path' => '/c', 'visitor' => 'v2v2v2v2v2v2v2v2'])                 // v2: visit, entry /c
  . $vLine('12:20:00', ['event' => 'floorplan-pdf', 'status' => 204, 'visitor' => 'v2v2v2v2v2v2v2v2']) // beacon: starts nothing
  . $vLine('12:40:00', ['path' => '/d', 'visitor' => 'v2v2v2v2v2v2v2v2'])                 // v2: 40 min after /c → new visit, entry /d
  . $vLine('13:00:00', ['path' => '/e', 'visitor' => null])                                // no key: no visit, no entry
  . $vLine('13:29:00', ['path' => '/f', 'visitor' => 'v3v3v3v3v3v3v3v3'])
  . $vLine('13:59:00', ['path' => '/g', 'visitor' => 'v3v3v3v3v3v3v3v3'])                 // exactly 30 min → still the same visit
  . $vLine('14:29:01', ['path' => '/h', 'visitor' => 'v3v3v3v3v3v3v3v3'])                 // 30 min + 1 s → new visit
);
$vr = (new StatsRollup($vRaw, $vAgg))->run($now);
$vm = json_decode((string)file_get_contents($vAgg . '/' . substr($vd, 0, 7) . '.json'), true);
check('visits: two hits 10 minutes apart are one visit with one entry', ($vm['entries']['/a'] ?? 0) === 1 && !isset($vm['entries']['/b']));
check('visits: 40 minutes apart are two visits with two entries', ($vm['entries']['/c'] ?? 0) === 1 && ($vm['entries']['/d'] ?? 0) === 1);
check('visits: a beacon event between them does not start a visit', !isset($vm['entries']['floorplan-pdf']) && ($vm['events']['floorplan-pdf'] ?? 0) === 1);
check('visits: a line without a key counts neither a visit nor an entry', !isset($vm['entries']['/e']) && ($vm['pages']['/e'] ?? 0) === 1);
check('visits: the gap is "more than 30 minutes" — exactly 30 continues, 30:01 starts anew', ($vm['entries']['/f'] ?? 0) === 1 && !isset($vm['entries']['/g']) && ($vm['entries']['/h'] ?? 0) === 1);
check('visits: day and month totals', ($vm['days'][$vd]['visits'] ?? null) === 5 && $vm['visits'] === 5 && array_sum($vm['entries']) === 5 && $vm['days'][$vd]['views'] === 8, json_encode($vm['days'][$vd] ?? null));
check('B11: aggregate retention — 24 month files at most, the running one included: 2024-08 AND 2024-09 deleted on a 2026-09 run, 2024-10 kept', !is_file($aggDir . '/2024-08.json') && !is_file($aggDir . '/2024-09.json') && is_file($aggDir . '/2024-10.json') && $r['aggregatesDeleted'] === 2, json_encode(array_map('basename', glob($aggDir . '/*.json') ?: [])) . ' deleted=' . $r['aggregatesDeleted']);
$r2 = $rollup->run($now);
$m2 = json_decode((string)file_get_contents($aggDir . '/' . substr($y, 0, 7) . '.json'), true);
check('a rerun folds nothing twice', $r2['folded'] === [] && $m2['views'] === $m['views'] && $m2['days'] === $m['days']);
// next day: yesterday's file becomes 8 days old later, today's file closes
$r3 = $rollup->run($now + 86400);
check('the next run folds the day that just closed', $r3['folded'] === [$day(0)] && json_decode((string)file_get_contents($aggDir . '/' . substr($day(0), 0, 7) . '.json'), true)['views'] === $m['views'] + 1);

// ── E3: the fold must not grow without bound per key — a flood day hits MAX_KEYS_PER_DAY, visibly ──
$maxKeys = defined(StatsRollup::class . '::MAX_KEYS_PER_DAY') ? StatsRollup::MAX_KEYS_PER_DAY : 20000;
$extra   = 500;
$fd      = $day(2);
$fh      = fopen($rawDir . '/stats-' . $fd . '.jsonl', 'wb');
for ($i = 0; $i < $maxKeys + $extra; $i++) {
    fwrite($fh, $line($fd, ['visitor' => sprintf('%016x', $i + 1), 'path' => '/flood']));
}
$pathsPerEvent = defined(StatsRollup::class . '::MAX_PATHS_PER_EVENT') ? StatsRollup::MAX_PATHS_PER_EVENT : 50;
for ($i = 0; $i < $pathsPerEvent + 10; $i++) {   // one declared event from 60 different pages, by keys that are already in the table
    fwrite($fh, $line($fd, ['visitor' => sprintf('%016x', $i + 1), 'event' => 'floorplan-pdf', 'status' => 204, 'path' => '/haus-' . $i]));
}
fclose($fh);
$r5 = $rollup->run($now);
$m5 = json_decode((string)file_get_contents($aggDir . '/' . substr($fd, 0, 7) . '.json'), true);
check('E3: beyond MAX_KEYS_PER_DAY a line with a NEW key is dropped and tallied under `capped` — the table stops growing', ($m5['days'][$fd]['visitors'] ?? null) === $maxKeys && ($m5['days'][$fd]['views'] ?? null) === $maxKeys && $r5['capped'] === $extra, json_encode($m5['days'][$fd] ?? null) . ' capped=' . $r5['capped']);
check('E3: the overflow is REPORTED (day → dropped lines), not silent', ($r5['overflow'] ?? null) === [$fd => $extra], json_encode($r5['overflow'] ?? null));
// 61 paths for floorplan-pdf in this month (60 here + /angebot/haus-a from yesterday's fixture) → the top 50 stay, 11 fold into `other`
check('E4: paths per event are capped at MAX_PATHS_PER_EVENT + `other` at save time (the referrer cap\'s character)', count($m5['event_pages']['floorplan-pdf'] ?? []) === $pathsPerEvent + 1 && ($m5['event_pages']['floorplan-pdf'][StatsRollup::REST_KEY] ?? 0) === 11 && array_sum($m5['event_pages']['floorplan-pdf'] ?? []) === StatsRollup::EVENT_DAY_CAP + $pathsPerEvent + 10 && array_key_first($m5['event_pages']['floorplan-pdf']) === '/angebot/haus-a', json_encode(array_slice($m5['event_pages']['floorplan-pdf'] ?? [], -2, 2, true)));

// ── B5: the rollup fails LOUDLY instead of losing data ──
$bRaw = $work . '/b5/logs/stats';
$bAgg = $work . '/b5/data';
@mkdir($bRaw, 0777, true); @mkdir($bAgg, 0777, true);
$full = json_encode(['month' => '2026-08', 'days_done' => ['2026-08-01'], 'days' => ['2026-08-01' => ['views' => 5000, 'visitors' => 100, 'visits' => 120, 'events' => 0]], 'views' => 5000, 'visitors' => 100, 'visits' => 120, 'capped' => 0, 'pages' => ['/' => 5000]]);
$truncated = substr($full, 0, -20);          // a torn or half-copied month file
file_put_contents($bAgg . '/2026-08.json', $truncated);
file_put_contents($bRaw . '/stats-2026-08-15.jsonl', $line('2026-08-15'));   // closed, older than the raw retention
$rb = (new StatsRollup($bRaw, $bAgg))->run($now);
check('B5: an unreadable month file is a FAILURE — not folded, not overwritten, the raw file kept (before: 5000 views became 1 and the job said done)', in_array('2026-08', $rb['failed'], true) && $rb['folded'] === [] && (string)file_get_contents($bAgg . '/2026-08.json') === $truncated && is_file($bRaw . '/stats-2026-08-15.jsonl') && $rb['rawDeleted'] === 0, json_encode($rb));
@mkdir($bRaw . '/stats-' . $day(4) . '.jsonl');   // a DIRECTORY where a day file should be
file_put_contents($bRaw . '/stats-' . $day(3) . '.jsonl', $line($day(3)));
$rb2 = (new StatsRollup($bRaw, $bAgg))->run($now);
$bm  = json_decode((string)file_get_contents($bAgg . '/' . substr($day(3), 0, 7) . '.json'), true);
check('B5: a day file that cannot be read is reported under `failedDays` and NOT marked done (before: folded=[day], failed=[])', ($rb2['failedDays'] ?? null) === [$day(4)] && $rb2['folded'] === [$day(3)] && !in_array($day(4), $bm['days_done'] ?? [], true), json_encode([$rb2['folded'], $rb2['failedDays'] ?? null, $bm['days_done'] ?? null]));

// ── E2 transition: raw files written before the move (directly in logs/) are still found, folded and swept ──
$tRaw = $work . '/transition/logs/stats';
$tLeg = $work . '/transition/logs';
$tAgg = $work . '/transition/data';
@mkdir($tRaw, 0777, true);
file_put_contents($tLeg . '/stats-' . $day(8) . '.jsonl', $line($day(8)) . $line($day(8), ['visitor' => 'dddddddddddddddd']));   // legacy only, past retention
file_put_contents($tLeg . '/stats-' . $day(3) . '.jsonl', $line($day(3), ['visitor' => 'eeeeeeeeeeeeeeee']));                    // the deploy day: morning in logs/ …
file_put_contents($tRaw . '/stats-' . $day(3) . '.jsonl', $line($day(3), ['visitor' => 'ffffffffffffffff']));                    // … afternoon in logs/stats/
$tr = (new StatsRollup($tRaw, $tAgg, $tLeg))->run($now);
$tm = json_decode((string)file_get_contents($tAgg . '/' . substr($day(3), 0, 7) . '.json'), true);
check('E2: a legacy file in logs/ is folded and, past retention, deleted', in_array($day(8), $tr['folded'], true) && !is_file($tLeg . '/stats-' . $day(8) . '.jsonl') && $tr['rawDeleted'] === 1, json_encode($tr));
check('E2: a day split across logs/ and logs/stats/ is folded as ONE day (both files, legacy first)', ($tm['days'][$day(3)] ?? null) === ['views' => 2, 'visitors' => 2, 'visits' => 2, 'events' => 0] && is_file($tLeg . '/stats-' . $day(3) . '.jsonl') && is_file($tRaw . '/stats-' . $day(3) . '.jsonl'), json_encode($tm['days'] ?? null));
check('E2: a rerun folds neither half twice', (new StatsRollup($tRaw, $tAgg, $tLeg))->run($now)['folded'] === []);

// a month that cannot be saved keeps its raw file
rrm($aggDir);
file_put_contents($aggDir, 'blocker');
file_put_contents($rawDir . '/stats-' . $day(9) . '.jsonl', $line($day(9)));
$r4 = (new StatsRollup($rawDir, $aggDir))->run($now);
check('an unsaveable month is reported and its raw file kept', $r4['failed'] !== [] && is_file($rawDir . '/stats-' . $day(9) . '.jsonl'));
@unlink($aggDir);
$job = new StatsRollupJob();
$ctx = new \Z77\Shared\Jobs\JobContext('stats-rollup', [], null, 1, time() + 50, new \Z77\Shared\Auth\AuthUser());
$res = $job->run($ctx);   // refolds the tree (the aggregate dir was removed above) — the flood day included
check('the job runs against ABS_BASE_PATH and reports', $res->isDone() && str_contains($res->getNote(), 'folded'), $res->getNote());
check('E3: the job\'s note names the overflow day loudly', str_contains($res->getNote(), 'visitor table full') && str_contains($res->getNote(), $fd), $res->getNote());
@mkdir($rawDir . '/stats-' . $day(5) . '.jsonl');   // a directory where a day file should be
$res2 = $job->run($ctx);
check('B5: an unreadable day file FAILS the job and is named', $res2->hasFailed() && str_contains($res2->getNote(), $day(5)), $res2->getNote());
rrm($rawDir . '/stats-' . $day(5) . '.jsonl');

// ── H. real SAPI: PHP's built-in server ─────────────────────────────────────
echo "built-in server\n";
$router = <<<'PHP'
<?php
$root = getenv('Z77_ROOT');
$case = $_GET['case'] ?? '';
define('ABS_BASE_PATH', getenv('Z77_WORK') . '/' . $case);
@mkdir(ABS_BASE_PATH, 0777, true);
spl_autoload_register(static function (string $class) use ($root): void {
    $map = ['Z77\\Core\\' => '/packages/kernel/core/src/', 'Z77\\Shared\\' => '/packages/kernel/shared/src/', 'Z77\\Persistence\\' => '/packages/kernel/persistence/src/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
            return;
        }
    }
});
use Z77\Core\DI;
use Z77\Core\Http\Request;
use Z77\Core\Http\RequestMode;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Core\Http\Response\PageCacheStatus;
use Z77\Core\Libraries\Cache\PageCache;
use Z77\Core\Libraries\Cache\PageIdentity;
use Z77\Shared\Stats\StatsEvents;
use Z77\Shared\Stats\StatsRecorder;

final class Cfg { public function get(string|array $k, mixed $d = null): mixed { return $k === 'public' ? true : $d; } }
final class Mods { public function getModuleConfig(string $k): ?Cfg { return new Cfg(); } }
DI::getInstance(true)->set('ModuleManager', new Mods(), true);

$ref = new ReflectionClass(Request::class);
$request = $ref->newInstanceWithoutConstructor();
foreach (['pathSegments' => ['angebot'], 'language' => 'de', 'method' => strtolower($_SERVER['REQUEST_METHOD']), 'mode' => RequestMode::Page, 'module' => 'frontend'] as $p => $v) {
    $ref->getProperty($p)->setValue($request, $v);
}
if ($case === 'unwritable') {
    @file_put_contents(ABS_BASE_PATH . '/logs', 'blocker');   // a FILE named logs
}
if ($case === 'event') {
    StatsEvents::use(['floorplan-pdf' => 'Grundriss-PDF']);
    StatsRecorder::event((string)($_GET['event'] ?? ''), (string)($_GET['path'] ?? ''), $request, false);
    http_response_code(204);
    exit;
}
// the page-cache HIT path exactly as the Dispatcher builds it
$cache = new PageCache();
$cache->setCacheDir(ABS_BASE_PATH . '/var/cache');
$identity = new PageIdentity('de', 'frontend', 'main', 'index', 'angebot');
$cache->set($identity, HtmlResponse::fromCache('<!doctype html><html lang="de"><body>CACHED-PAGE</body></html>', 1), 60);
if ($case === 'notmodified') {
    $response = HtmlResponse::notModified((int)$cache->getMtime($identity));
} else {
    $response = $cache->get($identity)->setCacheStatus(PageCacheStatus::Hit);
}
$response->send();
StatsRecorder::observe($request, $response, false);
PHP;
file_put_contents($work . '/router.php', $router);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$env = array_merge(getenv(), ['Z77_ROOT' => $root, 'Z77_WORK' => $work . '/http']);
$cmd = [PHP_BINARY, '-d', 'output_buffering=4096', '-S', "127.0.0.1:{$port}", $work . '/router.php'];
$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'w'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $work, $env);
$get = function (string $query) use ($port): array {
    $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'header' => "User-Agent: " . UA_CHROME . "\r\nReferer: https://www.google.ch/\r\n"]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}/?{$query}", false, $ctx);
    $status = 0; $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int)$m[1]; }
        $headers[] = $h;
    }
    return [$status, (string)$body, implode("\n", $headers)];
};
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100_000);
    $s = @fsockopen('127.0.0.1', $port);
    if ($s) { fclose($s); $up = true; }
}
check('built-in server started', $up);
if ($up) {
    $rawOf = fn(string $case): string => $work . '/http/' . $case . '/logs/stats/stats-' . date('Y-m-d') . '.jsonl';
    [$st, $body, $headers] = $get('case=hit');
    $hitLines = lines($rawOf('hit'));
    check('acceptance 1 (SAPI): a page-cache HIT answers 200 + HIT', $st === 200 && str_contains($body, 'CACHED-PAGE') && str_contains($headers, 'X-Z77-PageCache: HIT'), $st . ' ' . $headers);
    check('acceptance 1 (SAPI): … and is counted with the real headers classified', count($hitLines) === 1 && $hitLines[0]['status'] === 200 && $hitLines[0]['source'] === 'search:google.ch' && $hitLines[0]['device'] === 'desktop/chrome' && $hitLines[0]['visitor'] !== null, json_encode($hitLines));
    check('acceptance 1 (SAPI): the address (127.0.0.1) is nowhere in the line', !str_contains((string)file_get_contents($rawOf('hit')), '127.0.0.1'));
    check('acceptance 1 (SAPI): the salt lives under var/lib/stats', is_file($work . '/http/hit/var/lib/stats/salt'));
    check('B7 (SAPI): the directory born at runtime carries the deny .htaccess', is_file($work . '/http/hit/logs/stats/.htaccess') && is_file($work . '/http/hit/logs/.htaccess'));
    [$st, $body] = $get('case=notmodified');
    $nmLines = lines($rawOf('notmodified'));
    check('a 304 is counted with status 304', $st === 304 && $body === '' && count($nmLines) === 1 && $nmLines[0]['status'] === 304, $st . ' ' . json_encode($nmLines));
    [$st, $body] = $get('case=unwritable');
    check('acceptance 6 (SAPI): an unwritable logs/ still answers the full page with 200', $st === 200 && str_contains($body, 'CACHED-PAGE'), (string)$st);
    check('acceptance 6 (SAPI): … and wrote nothing', !is_dir($work . '/http/unwritable/logs'));
    [$st] = $get('case=event&event=floorplan-pdf&path=' . rawurlencode('/angebot/haus-a'));
    $evLines = lines($rawOf('event'));
    check('acceptance 4 (SAPI): the beacon answers 204 and the declared event is a line', $st === 204 && count($evLines) === 1 && $evLines[0]['event'] === 'floorplan-pdf' && $evLines[0]['path'] === '/angebot/haus-a', $st . ' ' . json_encode($evLines));
    [$st] = $get('case=event&event=' . rawurlencode('drop table') . '&path=/x');
    check('acceptance 4 (SAPI): an undeclared name answers 204 too and leaves no line', $st === 204 && count(lines($rawOf('event'))) === 1);
    // E4: what the browser sends — `encodeURIComponent(location.pathname)` double-encodes (`/%25C3%25BCber-uns`), the corrected
    // snippet encodes once; both must land on the page view's key `/über-uns`
    $get('case=event&event=floorplan-pdf&path=' . rawurlencode(rawurlencode('/über-uns')));
    $get('case=event&event=floorplan-pdf&path=' . rawurlencode('/über-uns'));
    $evLines = lines($rawOf('event'));
    check('E4 (SAPI): a double- and a single-encoded non-ASCII path both become /über-uns (before: dropped)', count($evLines) === 3 && $evLines[1]['path'] === '/über-uns' && $evLines[2]['path'] === '/über-uns', json_encode(array_column($evLines, 'path')));
    check('E3 (SAPI): the beacon leaves its counter under var/lib/throttle/stats', is_dir($work . '/http/event/var/lib/throttle/stats'));
}
proc_terminate($server);
proc_close($server);

// ── I. timing ───────────────────────────────────────────────────────────────
echo "timing (per request)\n";
$_SERVER['HTTP_USER_AGENT'] = UA_CHROME;
rrm($work . '/logs');
$n = 300;
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    StatsRecorder::record($hit(['ip' => '81.62.10.' . ($i % 250)]));
}
$noGeo = (hrtime(true) - $t) / $n / 1000;
printf("  classification + salt + append, no GeoIP database: %.0f µs\n", $noGeo);
// Guards against a pathological regression only — the number itself is the
// finding (Windows + Defender: ~1.5 ms, of which ~1 ms is the append itself;
// the classification is ~50 µs). Linux is expected an order of magnitude lower.
check('without a database the line costs under 10 ms', $noGeo < 10000, sprintf('%.0f µs', $noGeo));

$mmdb = 'Z:/z77/z77-1.0.0-zihlundsee.ch/data/framework/geoip/GeoLite2-Country.mmdb';
if (is_file($mmdb)) {
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        CountryLookup::forget();          // a web request opens the reader anew
        CountryLookup::useFile($mmdb);
        StatsRecorder::record($hit(['ip' => '81.62.10.' . ($i % 250)]));
    }
    $withGeo = (hrtime(true) - $t) / $n / 1000;
    printf("  … with the GeoLite2-Country database (open + lookup per request): %.0f µs\n", $withGeo);
    $last = lines(StatsRecorder::file());
    check('with the database the country is resolved (CH for a Swisscom range)', end($last)['country'] === 'CH', (string)end($last)['country']);
    // Same guard character. Measured on Windows: open + metadata ~1.7 ms,
    // the lookup ~3–4 ms (MmdbReader does one fseek+fread per tree node —
    // 128 for an IPv4 address inside the IPv6 tree). That is the GeoIP
    // layer's cost, paid per page view now; see stats.md pending.
    check('with GeoIP the line still costs under 25 ms', $withGeo < 25000, sprintf('%.0f µs', $withGeo));
    CountryLookup::forget();
} else {
    echo "  (no GeoLite2 database found at {$mmdb} — GeoIP cost not measured)\n";
}

// ── cleanup ─────────────────────────────────────────────────────────────────
rrm($work);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
