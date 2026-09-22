<?php

/**
 * Fresh-install harness (CLI) — what a new installation meets before anyone has
 * edited its config (P2 exit check 2026-09-22, findings S1–S3).
 *
 * What is load-bearing here:
 *
 *   - SEC-005 stays intact: without `canonicalBaseUrl`, Request::getBaseUrl()
 *     throws — and its message names the file that exists since ADR-036,
 *     `config/client/systemConfig.inc.php` (S2);
 *   - SeoLinks defers the canonical/hreflang set to its first read: building
 *     the page context never touches the origin, so a layout that prints no
 *     canonical — the backend, the first-run setup — renders without it (S1),
 *     while the frontend head partial still fails loudly (never a guessed or
 *     empty canonical);
 *   - the seeds: `canonicalBaseUrl` empty (no default is possible, ADR-030),
 *     database host `localhost` (on Unix it selects the MariaDB socket; see
 *     persistence-doctrine.md DOCTRINE-HOST-001 for the Windows cost) (S3);
 *   - no message or comment in the packages, and no living doc, names a
 *     pre-split config path (`config/X.inc.php` for a file that lives in
 *     config/client/ or config/vendor/) (S2) — ADRs and an explicit allowlist
 *     of dated plans/reviews excluded, each with its reason;
 *   - the installer's closing notice: one line naming the file and the key
 *     while `canonicalBaseUrl` is empty, none once it is set.
 *
 * Run: php tests/fresh-install-setup.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));

require $root . '/packages/kernel/core/src/Exception/ViewException.php';
require $root . '/packages/kernel/core/src/Services/TemplateRenderer.php';
require $root . '/packages/kernel/core/src/Libraries/Seo/SeoLinks.php';
require $root . '/packages/kernel/core/src/Libraries/Seo/SiteIdentity.php';
require $root . '/packages/kernel/core/src/Http/Request.php';
require $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\Http\Request;
use Z77\Core\Libraries\Seo\SeoLinks;
use Z77\Core\Libraries\Seo\SiteIdentity;
use Z77\Core\Services\TemplateRenderer;

define('CANONICAL_BASE_URL', '');   // a fresh install: the seed is empty

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// ── SEC-005: the origin still throws, with the right path ───────────────────
echo "Request::getBaseUrl() without canonicalBaseUrl\n";
$request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
$message = '';
try {
    $request->getBaseUrl();
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
check('throws (no Host-header fallback)', $message !== '');
check('names config/client/systemConfig.inc.php', str_contains($message, 'config/client/systemConfig.inc.php'), $message);

// ── SeoLinks: built on first read, the throw reaches only who reads ─────────
echo "SeoLinks\n";
$calls = 0;
$seo   = new SeoLinks(function () use (&$calls, $request) {
    $calls++;
    return ['canonical' => $request->getBaseUrl() . '/home', 'alternates' => []];
});
check('constructing does not build (no origin needed)', $calls === 0);

$thrown = false;
try { $seo['canonical']; } catch (RuntimeException) { $thrown = true; }
check('the first read builds and throws the SEC-005 error', $thrown && $calls === 1);

$thrown = false;
try { $seo['canonical']; } catch (RuntimeException) { $thrown = true; }
check('every later read throws too (no empty canonical after a failure)', $thrown);

$built = 0;
$ok    = new SeoLinks(function () use (&$built) {
    $built++;
    return ['canonical' => 'https://kunde.ch/home', 'alternates' => [['hreflang' => 'fr', 'url' => 'https://kunde.ch/fr/home']]];
});
$ok['canonical'];
$ok['alternates'];
check('with an origin: array reads work, built once', $ok['canonical'] === 'https://kunde.ch/home' && count($ok['alternates']) === 1 && $built === 1);
check('isset / empty work through offsetExists', isset($ok['alternates']) && !isset($ok['nope']) && !empty($ok['alternates']));
$readOnly = false;
try { $ok['canonical'] = 'x'; } catch (LogicException) { $readOnly = true; }
check('read-only', $readOnly);

// ── the head partials: backend renders, frontend fails loudly ───────────────
echo "Head partials without canonicalBaseUrl\n";
$renderer = new TemplateRenderer();
$failing  = new SeoLinks(fn() => ['canonical' => $request->getBaseUrl(), 'alternates' => []]);
$context  = ['seo' => $failing, 'metaData' => null, 'site' => SiteIdentity::empty()];

$html = '';
try {
    $html = $renderer->render($root . '/packages/module-backend/res/view/templates/partials/head/seo.tpl.php', $context);
} catch (Throwable $e) {
    $html = 'THREW: ' . $e->getMessage();
}
check('backend head (setup page, backend shell) renders', str_contains($html, '<title>'), $html);

$thrown = false;
try {
    $renderer->render($root . '/packages/module-frontend/res/view/templates/partials/head/seo.tpl.php', $context);
} catch (RuntimeException) {
    $thrown = true;
}
check('frontend head still throws (SEC-005 kept for public pages)', $thrown);

// ── seeds ───────────────────────────────────────────────────────────────────
echo "Seeds\n";
$system   = require $root . '/packages/kernel/core/src/Config/systemConfig.default.inc.php';
$database = require $root . '/packages/kernel/core/src/Config/database.default.inc.php';
check('canonicalBaseUrl seeded empty (no default is possible, ADR-030)', ($system['canonicalBaseUrl'] ?? null) === '');
check("database host seeded 'localhost' (Unix socket; DOCTRINE-HOST-001)", ($database['host'] ?? null) === 'localhost', (string)($database['host'] ?? ''));

// ── S2 guard: no pre-split config path in the packages ──────────────────────
echo "Config paths (ADR-036)\n";
$preSplit = '#(?<![a-zA-Z/_.-])config/(systemConfig|mail|auth|backup|geoip|i18n|database|bootstrap|fileFinder|moduleManager)\.inc\.php#';
$scan = function (string $dir, string $ext, callable $skip) use ($root, $preSplit): array {
    $hits = [];
    $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        $rel  = substr($path, strlen($root) + 1);
        if (!str_ends_with($path, $ext) || $skip($rel)) {
            continue;
        }
        foreach (file($path) as $no => $line) {
            if (preg_match($preSplit, $line)) {
                $hits[] = $rel . ':' . ($no + 1);
            }
        }
    }
    return $hits;
};

$stale = $scan('/packages', '.php', fn(string $rel) => str_contains($rel, '/vendor/') || str_contains($rel, '/vendored/'));
check('packages: no config/X.inc.php without client/ or vendor/', $stale === [], implode(', ', $stale));

// Docs. ADRs are historical records (decided text is never rewritten), so
// docs/02-decisions/ is out. The allowlist holds DATED plans and reviews in
// docs/03-development/ — each records the flat layout as it was on its date,
// before ADR-036; rewriting them would falsify what was planned or found.
// Anything else — handbook, topics, concepts — describes the present.
$dated    = 'dated plan/review — records the flat pre-ADR-036 layout of its date';
$docAllow = [
    'docs/03-development/backup-service-bauplan.md'                       => $dated,
    'docs/03-development/dms-folder-image-profiles-bauplan.md'            => $dated,
    'docs/03-development/dokumentenverwaltung-bauplan.md'                 => $dated,
    'docs/03-development/email-service-bauplan.md'                        => $dated,
    'docs/03-development/email-settings-v2-bauplan.md'                    => $dated,
    'docs/03-development/form-geo-guard-bauplan.md'                       => $dated,
    'docs/03-development/laufzeit-zustand-nach-lib-bauplan.md'            => $dated,
    'docs/03-development/laufzeit-zustand-nach-lib-review-2026-08-25.md'  => $dated,
    'docs/03-development/member-login-security-review-2026-08-07.md'      => $dated,
    'docs/03-development/member-mem-findings-bauplan.md'                  => $dated,
    'docs/03-development/review-email-service-usage.md'                   => $dated,
    'docs/03-development/review-filefinder.md'                            => $dated . ' (quotes the code message of that date)',
    'docs/03-development/review-router-cache-debug.md'                    => $dated,
];
$stale = $scan('/docs', '.md', fn(string $rel) => str_starts_with($rel, 'docs/02-decisions/') || isset($docAllow[$rel]));
check('docs: no config/X.inc.php without client/ or vendor/ (ADRs + dated allowlist excluded)', $stale === [], implode(', ', $stale));
$gone = array_filter(array_keys($docAllow), fn(string $rel) => !is_file($root . '/' . $rel));
check('docs allowlist names only existing files', $gone === [], implode(', ', $gone));

// ── installer: the one line after the run (INST-FRESH-001) ──────────────────
echo "Installer notice\n";
require $root . '/packages/kernel/core/src/Installer/Install.php';
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-fresh-install-' . getmypid() . '.inc.php';
file_put_contents($tmp, "<?php\nreturn ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];\n");
$notice = \Z77\Core\Installer\Install::canonicalBaseUrlNotice($tmp);
check('empty canonicalBaseUrl → one line naming file and key', $notice !== null
    && str_contains($notice, 'config/client/systemConfig.inc.php') && str_contains($notice, 'canonicalBaseUrl'), (string)$notice);
file_put_contents($tmp, "<?php\nreturn ['canonicalBaseUrl' => 'https://kunde.ch'];\n");
check('set canonicalBaseUrl → no line', \Z77\Core\Installer\Install::canonicalBaseUrlNotice($tmp) === null);
@unlink($tmp);
check('no installed file → no line', \Z77\Core\Installer\Install::canonicalBaseUrlNotice($tmp) === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
