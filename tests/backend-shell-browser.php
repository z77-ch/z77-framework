<?php

/**
 * Backend shell geometry in a real browser (CLI, headless Edge/Chrome) — SHELL-RAIL-TOP-001.
 *
 * The REAL shell skeleton (`html-shell-skeleton.tpl.php`) with the REAL compiled `base.css`,
 * written as static pages into a temp dir and opened over file:// (no server, no port). A script
 * at the end of each page measures the boxes and writes them into the DOM; `--dump-dom` hands
 * them back. The slot contents are minimal stand-ins (an action button, a crumb, tree nodes).
 * What is load-bearing (owner 2026-11-21):
 *
 *   D  desktop 1360×900: the rail starts DIRECTLY under the action cell — «Dashboard» is its first
 *      row, the first menu entry right under it — at the SAME y on a page with a tab row and on
 *      one without; with a rail-top selection «Dashboard» still comes first; tab row and crumb
 *      line stand in the work column only; the drag handle spans the rail's rows; the action cell
 *      is as high as the top bar, empty or not (R2);
 *   B  the dashboard exception (`be-shell--bare`, owner 2026-10-22 / 2026-11-21): no band, no
 *      «Dashboard» link, the rail starts right under the top bar;
 *   M  phone (a window 390 wide): a wide toolbar and a wide list do not make the page wider than the screen
 *      (the grid column is `minmax(0, 1fr)`), the action square stays on screen, «Dashboard»
 *      travels in the drawer (inside the rail).
 *   F  the font slider (FONT-SCALE-LINEAR-001) at 0.85 / 1.0 / 1.25: root and body 14px × slider,
 *      the smallest text 12px × slider, a list name follows it; capped regions (a button, the
 *      service-panel INFO) never grow past the cap and shrink below 1.0; the fixed-height places
 *      (band, tab row, crumb, rail, badge) keep their text on one line, unclipped;
 *   P  the stored value: UserPreferences clamps it to 0.85–1.25 on read and on write.
 *
 * What it cannot prove: the server side of a screen (which slots a controller fills) — the
 * module harnesses; colours — a look by hand.
 *
 * Needs a browser (msedge, chrome; or Z77_BROWSER=path). Exit 2 when none is found: a run
 * without one has verified nothing.
 * Run: php tests/backend-shell-browser.php
 */

$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$skeleton = $root . '/packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php';
$baseCss  = $root . '/packages/module-backend/res/assets/css/base.css';
require_once $root . '/packages/kernel/core/src/autoload/prod/php/Helper.php';   // e()
require_once $root . '/packages/kernel/shared/src/ValueObjects/UserPreferences.php';   // F/P: the stored font scale

$browser = getenv('Z77_BROWSER') ?: null;
foreach (['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
          'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/microsoft-edge', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $candidate) {
    $browser ??= is_file($candidate) ? $candidate : null;
}
if ($browser === null) {
    fwrite(STDERR, "backend-shell-browser: no browser found, set Z77_BROWSER.\n");
    exit(2);
}
echo "browser: {$browser}\n";

$work = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-backend-shell-browser-' . getmypid();
mkdir($work . '/profile', 0777, true);
register_shutdown_function(static function () use ($work): void {
    exec(DIRECTORY_SEPARATOR === '\\' ? 'rmdir /S /Q "' . str_replace('/', '\\', $work) . '" 2>NUL' : 'rm -rf ' . escapeshellarg($work));
});

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? " — {$got}" : '') . "\n"; }
}

// ── the pages ───────────────────────────────────────────────────────────────────────────────

/** The skeleton rendered with the given slots. `$this->partial()` is never reached: hc3 is set, no help. */
function renderShell(string $skeleton, array $vars): string
{
    $render = function (string $__file, array $__vars): string {
        extract($__vars);
        ob_start();
        include $__file;
        return (string) ob_get_clean();
    };
    $stub = new class {
        public function partial(string $name, array $vars = [], ?string $ns = null): string { return ''; }
    };
    return \Closure::bind($render, $stub, null)($skeleton, $vars);
}

// Measures what the checks read and writes it as JSON into <pre id="probe"> (--dump-dom returns it).
$probe = <<<'HTML'
<script>
(function () {
    var box = function (sel) {
        var el = document.querySelector(sel);
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height) };
    };
    var rail = document.querySelector('.be-shell-col--1');
    // F: computed font size in px (2 decimals), and whether an element keeps ONE line and stays
    // inside its fixed-height container (nowrap must not change its height; no overflow).
    var px = function (sel) {
        var el = document.querySelector(sel);
        return el ? Math.round(parseFloat(getComputedStyle(el).fontSize) * 100) / 100 : null;
    };
    var fits = function (sel, boxSel) {
        var el = document.querySelector(sel), box = document.querySelector(boxSel);
        if (!el || !box) return null;
        var h = el.getBoundingClientRect().height;
        el.style.whiteSpace = 'nowrap';
        var oneLine = Math.abs(el.getBoundingClientRect().height - h) < 0.5;
        el.style.whiteSpace = '';
        var r = el.getBoundingClientRect(), b = box.getBoundingClientRect();
        return { oneLine: oneLine, inside: r.top >= b.top - 0.5 && r.bottom <= b.bottom + 0.5,
                 notClipped: el.scrollHeight <= el.clientHeight + 1 || el.clientHeight === 0, height: Math.round(r.height * 10) / 10 };
    };
    var out = {
        viewport: window.innerWidth,
        scrollWidth: document.documentElement.scrollWidth,
        topbar: box('.be-shell-topbar'),
        band: box('.be-shell-band'),
        act: box('.be-shell-band__slot--1'),
        tabs: box('.be-shell-tabs'),
        crumb: box('.be-shell-crumb'),
        rail: box('.be-shell-col--1'),
        work: box('.be-shell-col--2'),
        home: box('.be-shell-home'),
        select: box('.be-shell-select'),
        firstEntry: box('.be-shell-col--1 .backend-tree-node'),
        resizer: box('.be-shell__resizer'),
        trigger: box('.be-shell-band__slot--1 .be-shell-add > .be-btn'),
        panel: box('.be-shell-band__slot--1 .be-shell-add__panel'),
        longItem: box('.be-shell-band__slot--1 .be-shell-add__item[data-long]'),
        firstRailChild: rail && rail.firstElementChild ? rail.firstElementChild.className : null,
        homeInRail: !!document.querySelector('.be-shell-col--1 > .be-shell-home'),
        homeCount: document.querySelectorAll('.be-shell-home').length,
        font: {
            root: px('html'), body: px('[data-fs="body"]'), smallest: px('[data-fs="badge"]'), listName: px('[data-fs="name"]'),
            actBtn: px('.be-shell-band__slot--1 .be-btn'), info: px('[data-fs="info"] dd'),
            panelWidth: box('[data-fs="panel"]') ? box('[data-fs="panel"]').right - box('[data-fs="panel"]').left : null
        },
        fit: {
            actBtn: fits('.be-shell-band__slot--1 .be-btn', '.be-shell-band__slot--1'),
            toolbar: fits('[data-fs="tool"]', '.be-shell-band__slot--2'),
            tab: fits('.be-viewtabs__tab.is-active', '.be-shell-tabs'),
            crumb: fits('.be-crumb__here', '.be-shell-crumb'),
            home: fits('.be-shell-home', '.be-shell-home'),
            railEntry: fits('.be-shell-col--1 .backend-tree-node__label', '.be-shell-col--1 .backend-tree-node'),
            badge: fits('[data-fs="badge"]', '[data-fs="badge-cell"]'),
            listName: fits('[data-fs="name"]', '[data-fs="name"]')
        }
    };
    var pre = document.createElement('pre');
    pre.id = 'probe';
    pre.textContent = JSON.stringify(out);
    document.body.appendChild(pre);
})();
</script>
HTML;

$css    = '<style>' . file_get_contents($baseCss) . '</style>';
$topbar = '<header class="be-shell-topbar"><div class="be-shell-topbar__mod"><div class="be-shell-mod"><span class="be-shell-mod__name">Bereich</span></div></div>'
        . '<button type="button" class="be-shell-topbar__burger" aria-label="Menü"></button><div class="be-shell-topbar__mid"></div></header>';
$subnav = '<nav class="backend-subnav" aria-label="Bereich"><div class="backend-subnav__header"><span class="backend-subnav__title">Bereich</span></div><div class="backend-subnav__tree">'
        . str_repeat('<a href="#" class="backend-tree-node"><span class="backend-tree-node__toggle" aria-hidden="true"></span><span class="backend-tree-node__label">Eintrag</span></a>', 6)
        . '</div></nav>';
$act    = '<button type="button" class="be-btn be-btn--primary"><span class="be-btn__label">Neu</span></button>';
$tabs   = '<nav class="be-viewtabs"><a href="#" class="be-viewtabs__tab is-active">Übersicht</a><a href="#" class="be-viewtabs__tab">Details</a></nav>';
$select = '<details class="be-shell-add be-shell-add--select"><summary class="be-btn"><span class="be-btn__label">Jahr <span class="be-shell-select__value">2026</span></span></summary></details>';
// An OPEN action-cell picker (the script that toggles `hidden` is not loaded — rendered open).
$menu   = static fn (string $long): string => '<div class="be-shell-add"><button type="button" class="be-btn be-btn--primary"><span class="be-btn__label">Aktionen</span></button>'
        . '<div class="be-shell-add__panel" role="menu"><button type="button" class="be-shell-add__item" role="menuitem">Kurz</button>'
        . '<button type="button" class="be-shell-add__item" role="menuitem" data-long>' . $long . '</button></div></div>';
$wide   = '<table><tr>' . str_repeat('<td style="white-space:nowrap;padding:0 1rem">eine breite Spalte einer Liste</td>', 8) . '</tr></table>';

$base = [
    'bePalette' => 'salbei', 'beTheme' => 'light', 'beFontScale' => 1.0,
    'head' => '<meta charset="utf-8"><title>shell</title>', 'css' => $css, 'shellTopbar' => $topbar,
    'hc1' => $act, 'hc2' => '<span>Werkzeuge</span>', 'hc3' => '<nav class="be-crumb"><span class="be-crumb__here">Seite</span></nav>',
    'crumbRoot' => '/backend/system/dashboard/overview', 'subnav' => $subnav, 'main' => '<p>Inhalt</p>', 'jsFooter' => $probe,
];
$pages = [
    'plain'       => $base,
    'tabs'        => $base + ['tabs' => $tabs],
    'select'      => $base + ['railSelect' => $select],
    'select-tabs' => $base + ['railSelect' => $select, 'tabs' => $tabs],
    'empty-act'   => ['hc1' => ''] + $base,
    'bare'        => ['crumbRoot' => '', 'shellBare' => true, 'hc1' => '', 'hc2' => ''] + $base,
    'menu-short'  => ['hc1' => $menu('Neu')] + $base,
    // Long, but under the 24rem cap at slider 1.0 — since the font tokens grew to their true
    // sizes (FONT-SCALE-LINEAR-001, +14 %) the former «… und alle Daten entfernen …» wraps there.
    'menu-long'   => ['hc1' => $menu('Mandant endgültig löschen mit allen Daten …')] + $base,
    'menu-cap'    => ['hc1' => $menu(str_repeat('ein sehr langer Eintrag ', 8))] + $base,
    'wide'        => ['hc2' => '<span>' . str_repeat('Werkzeug ', 30) . '</span>', 'main' => $wide] + $base + ['tabs' => $tabs],
];
// F: the font slider at its ends and its default — the value goes through UserPreferences, as on a
// real page (BackendAbstractController::html() → `style="--be-font-scale: …"`).
$fontMain = '<p data-fs="body">Fliesstext der Arbeitsfläche</p>'
    . '<div class="be-list"><div class="be-list__frame"><div class="be-list__table" style="--be-list-cols: 6rem minmax(0, 1fr) 8rem"><div class="be-list__item"><div class="be-list__row">'
    . '<span class="be-list__cell be-list__cell--num">1001</span><label class="be-list__cell" data-fs="name" data-field="name">Muster Treuhand AG</label>'
    . '<span class="be-list__cell" data-fs="badge-cell"><span class="badge badge--info" data-fs="badge">offen</span></span></div></div></div></div></div>'
    . '<div style="position:relative"><div class="backend-service-panel" data-fs="panel" style="position:static">'
    . '<dl class="backend-service-panel__info be-font-cap" data-fs="info"><div class="backend-service-panel__info-row"><dt>Modul</dt><dd>Z77\\Module\\Debtor</dd></div></dl></div></div>';
$fontPage = ['tabs' => $tabs, 'main' => $fontMain, 'hc1' => '<button type="button" class="be-btn be-btn--primary"><span class="be-btn__label">Neue Rechnung</span></button>',
    'hc2' => '<span data-fs="tool">Offen · Alle · Exportieren</span>'] + $base;
$fontScales = ['085' => 0.85, '100' => 1.0, '125' => 1.25];
foreach ($fontScales as $key => $scale) {
    $pages["font-{$key}"] = ['beFontScale' => (new \Z77\Shared\ValueObjects\UserPreferences(['font_scale' => $scale]))->getFontScale()] + $fontPage;
}

foreach ($pages as $name => $vars) {
    file_put_contents("{$work}/{$name}.html", renderShell($skeleton, $vars));
}

// ── the browser ─────────────────────────────────────────────────────────────────────────────

/** Opens one page headless at the given size and returns what its probe measured. */
function measure(string $browser, string $work, string $page, string $size): ?array
{
    $out  = "{$work}/{$page}-{$size}.out";
    $proc = proc_open([$browser, '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-gpu',
        '--user-data-dir=' . $work . '/profile', '--window-size=' . $size, '--hide-scrollbars', '--dump-dom', "file:///{$work}/{$page}.html"],
        [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', "{$work}/browser.err", 'w']], $pipes);
    $deadline = time() + 60;
    while (proc_get_status($proc)['running'] && time() < $deadline) {
        usleep(100_000);
    }
    $status = proc_get_status($proc);
    if ($status['running']) {
        DIRECTORY_SEPARATOR === '\\' ? exec('taskkill /T /F /PID ' . (int) $status['pid'] . ' 2>NUL') : proc_terminate($proc);
        echo "  (browser killed after 60 s: {$page})\n";
    }
    proc_close($proc);
    $dom = (string) @file_get_contents($out);
    if (!preg_match('#<pre id="probe">(.*?)</pre>#s', $dom, $m)) {
        return null;
    }
    return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
}

$m = [];
foreach (array_keys($pages) as $name) {
    $m[$name] = measure($browser, $work, $name, $name === 'wide' ? '390,844' : '1360,900');
}
$m['plain-phone'] = measure($browser, $work, 'plain', '390,844');
$m['menu-phone']  = measure($browser, $work, 'menu-cap', '390,844');
$measured = array_filter($m);
if ($measured === []) {
    fwrite(STDERR, "backend-shell-browser: the browser returned no measurement.\n");
    exit(2);
}
$at = static fn (?array $page, string $part, string $edge): ?int => $page[$part][$edge] ?? null;
$show = static fn (...$v): string => json_encode($v);

echo "D. Desktop 1360x900 — the rail starts under the action cell\n";
$plain = $m['plain'];
$tabsP = $m['tabs'];
check('every page was measured', count($measured) === count($m), $show(array_keys(array_diff_key($m, $measured))));
check('the band starts under the top bar and is as high as it (R2)',
    $at($plain, 'band', 'top') === $at($plain, 'topbar', 'bottom') && $at($plain, 'act', 'height') === $at($plain, 'topbar', 'height'),
    $show($plain['topbar'] ?? null, $plain['act'] ?? null));
check('an EMPTY action cell is just as high (R2)', $at($m['empty-act'], 'act', 'height') === $at($plain, 'act', 'height'),
    $show($m['empty-act']['act'] ?? null));
check('without tabs: the rail starts at the band\'s bottom edge', $at($plain, 'rail', 'top') === $at($plain, 'band', 'bottom'),
    $show($plain['rail'] ?? null, $plain['band'] ?? null));
check('with tabs: the rail starts at the band\'s bottom edge (R1)', $at($tabsP, 'rail', 'top') === $at($tabsP, 'band', 'bottom'),
    $show($tabsP['rail'] ?? null, $tabsP['band'] ?? null));
check('«Dashboard» is the first row of the rail', ($plain['firstRailChild'] ?? '') === 'be-shell-home' && ($plain['homeInRail'] ?? false) === true
    && ($plain['homeCount'] ?? 0) === 1, $show($plain['firstRailChild'] ?? null, $plain['homeCount'] ?? null));
check('«Dashboard» at the rail\'s top, as high as the crumb line',
    $at($plain, 'home', 'top') === $at($plain, 'rail', 'top') && $at($plain, 'home', 'height') === $at($plain, 'crumb', 'height'),
    $show($plain['home'] ?? null, $plain['crumb'] ?? null));
check('the first menu entry directly under «Dashboard»', $at($plain, 'firstEntry', 'top') === $at($plain, 'home', 'bottom'),
    $show($plain['firstEntry'] ?? null, $plain['home'] ?? null));
check('«Dashboard» at the same y with and without tabs', $at($plain, 'home', 'top') !== null && $at($plain, 'home', 'top') === $at($tabsP, 'home', 'top'),
    $show($at($plain, 'home', 'top'), $at($tabsP, 'home', 'top')));
check('the first menu entry at the same y with and without tabs (R1)',
    $at($plain, 'firstEntry', 'top') !== null && $at($plain, 'firstEntry', 'top') === $at($tabsP, 'firstEntry', 'top'),
    $show($at($plain, 'firstEntry', 'top'), $at($tabsP, 'firstEntry', 'top')));
check('with a rail-top selection «Dashboard» still comes first, the selection under it',
    ($m['select']['firstRailChild'] ?? '') === 'be-shell-home' && $at($m['select'], 'select', 'top') === $at($m['select'], 'home', 'bottom'),
    $show($m['select']['firstRailChild'] ?? null, $m['select']['select'] ?? null, $m['select']['home'] ?? null));
check('the rail-top selection at the same y with and without tabs',
    $at($m['select'], 'select', 'top') !== null && $at($m['select'], 'select', 'top') === $at($m['select-tabs'], 'select', 'top'),
    $show($at($m['select'], 'select', 'top'), $at($m['select-tabs'], 'select', 'top')));
check('tab row and crumb line stand in the work column only',
    $at($tabsP, 'tabs', 'left') === $at($tabsP, 'work', 'left') && $at($tabsP, 'crumb', 'left') === $at($tabsP, 'work', 'left')
    && $at($tabsP, 'tabs', 'left') >= $at($tabsP, 'rail', 'right'),
    $show($tabsP['tabs'] ?? null, $tabsP['crumb'] ?? null, $tabsP['rail'] ?? null));
check('the work column starts under the crumb line', $at($tabsP, 'work', 'top') === $at($tabsP, 'crumb', 'top') + 32
    && $at($plain, 'work', 'top') === $at($plain, 'crumb', 'top') + 32,
    $show($tabsP['crumb'] ?? null, $tabsP['work'] ?? null));
check('the drag handle spans the rail\'s rows', $at($tabsP, 'resizer', 'top') === $at($tabsP, 'rail', 'top')
    && $at($tabsP, 'resizer', 'bottom') === $at($tabsP, 'rail', 'bottom'),
    $show($tabsP['resizer'] ?? null, $tabsP['rail'] ?? null));

echo "A. The action cell's menu — at least the button's width, grows with its longest item (SHELL-ADD-WIDTH-001)
";
$short = $m['menu-short'];
$long  = $m['menu-long'];
$cap   = $m['menu-cap'];
$width = static fn (?array $page, string $part): ?int => isset($page[$part]) ? $page[$part]['right'] - $page[$part]['left'] : null;
check('short items: the menu is exactly as wide as the button', $width($short, 'panel') !== null && $width($short, 'panel') === $width($short, 'trigger'),
    $show($short['panel'] ?? null, $short['trigger'] ?? null));
check('a long item: the menu starts at the left edge of the button and grows over the work column',
    $at($long, 'panel', 'left') === $at($long, 'trigger', 'left') && $at($long, 'panel', 'right') > $at($long, 'rail', 'right'),
    $show($long['panel'] ?? null, $long['rail'] ?? null));
check('a long item stays inside the menu, on one line', $at($long, 'longItem', 'right') !== null && $at($long, 'longItem', 'right') <= $at($long, 'panel', 'right')
    && $at($long, 'longItem', 'height') <= 40,
    $show($long['longItem'] ?? null, $long['panel'] ?? null));
check('a very long item: the menu stops at its cap (24rem) and the text wraps inside',
    $width($cap, 'panel') !== null && $width($cap, 'panel') <= 384 && $at($cap, 'longItem', 'right') <= $at($cap, 'panel', 'right')
    && $at($cap, 'longItem', 'height') > 40, $show($cap['panel'] ?? null, $cap['longItem'] ?? null));
$menuPhone = $m['menu-phone'];
$mvw       = $menuPhone['viewport'] ?? 0;
check('phone: the menu stays on the screen', $at($menuPhone, 'panel', 'left') !== null && $at($menuPhone, 'panel', 'left') >= 0
    && $at($menuPhone, 'panel', 'right') <= $mvw && ($menuPhone['scrollWidth'] ?? PHP_INT_MAX) <= $mvw,
    $show($menuPhone['panel'] ?? null, $mvw, $menuPhone['scrollWidth'] ?? null));

echo "B. The dashboard exception — no band, the rail under the top bar\n";
$bare = $m['bare'];
check('no «Dashboard» link on the dashboard', ($bare['homeCount'] ?? -1) === 0, $show($bare['homeCount'] ?? null));
check('the rail starts right under the top bar', $at($bare, 'rail', 'top') === $at($bare, 'topbar', 'bottom'),
    $show($bare['rail'] ?? null, $bare['topbar'] ?? null));
check('the first menu entry is the rail\'s first row', $at($bare, 'firstEntry', 'top') === $at($bare, 'rail', 'top'),
    $show($bare['firstEntry'] ?? null));

echo "M. Phone — no page wider than the screen, «Dashboard» in the drawer
";
// The window is asked for 390 wide; a headless browser may keep a wider minimum (Edge on Windows:
// 492). What counts is that the phone block (below 767px) applies.
$wideP = $m['wide'];
$vw    = $wideP['viewport'] ?? 0;
check("the phone block applies (viewport {$vw}px)", $vw > 0 && $vw < 767, $show($vw));
check('a wide toolbar and a wide list: the band is no wider than the screen', $at($wideP, 'band', 'right') !== null && $at($wideP, 'band', 'right') <= $vw,
    $show($wideP['band'] ?? null, $vw));
check('the action square stays on the screen', $at($wideP, 'act', 'right') !== null && $at($wideP, 'act', 'right') <= $vw,
    $show($wideP['act'] ?? null, $vw));
check('nothing scrolls the page sideways', ($wideP['scrollWidth'] ?? PHP_INT_MAX) <= $vw, $show($wideP['scrollWidth'] ?? null, $vw));
check('tab row and crumb line as wide as the band', $at($wideP, 'tabs', 'left') === $at($wideP, 'band', 'left')
    && $at($wideP, 'tabs', 'right') === $at($wideP, 'band', 'right') && $at($wideP, 'crumb', 'right') === $at($wideP, 'band', 'right'),
    $show($wideP['tabs'] ?? null, $wideP['crumb'] ?? null, $wideP['band'] ?? null));
$phone = $m['plain-phone'];
check('«Dashboard» rides in the drawer (first row of the rail, off screen while closed)',
    ($phone['firstRailChild'] ?? '') === 'be-shell-home' && $at($phone, 'home', 'right') !== null && $at($phone, 'home', 'right') <= 0,
    $show($phone['firstRailChild'] ?? null, $phone['home'] ?? null));

echo "F. The font slider acts once; the cap holds upwards, capped regions shrink below 1.0 (FONT-SCALE-LINEAR-001)\n";
$near = static fn (?float $got, float $want): bool => $got !== null && abs($got - $want) < 0.06;
foreach ($fontScales as $key => $scale) {
    $f   = $m["font-{$key}"]['font'] ?? [];
    $fit = $m["font-{$key}"]['fit'] ?? [];
    $cap = min($scale, 1.0);
    echo "  slider {$scale}: " . json_encode($f) . "\n";    check("{$scale}: root and body text 14px × slider", $near($f['root'] ?? null, 14 * $scale) && $near($f['body'] ?? null, 14 * $scale),
        $show($f['root'] ?? null, $f['body'] ?? null));
    check("{$scale}: smallest text (badge, --font-size-xs) 12px × slider", $near($f['smallest'] ?? null, 12 * $scale), $show($f['smallest'] ?? null));
    check("{$scale}: the list's name cell follows the slider (13px × slider)", $near($f['listName'] ?? null, 13 * $scale), $show($f['listName'] ?? null));
    check("{$scale}: a capped button 13px × min(slider, cap)", $near($f['actBtn'] ?? null, 13 * $cap), $show($f['actBtn'] ?? null));
    check("{$scale}: the capped service-panel INFO 12px × min(slider, cap)", $near($f['info'] ?? null, 12 * $cap), $show($f['info'] ?? null));
    check("{$scale}: the service panel 280px × slider wide", abs(($f['panelWidth'] ?? 0) - 280 * $scale) <= 1, $show($f['panelWidth'] ?? null));
    foreach (['actBtn' => 'action button in the band', 'toolbar' => 'toolbar text in the band', 'tab' => 'tab in the tab row',
              'crumb' => 'crumb', 'home' => '«Dashboard»', 'railEntry' => 'rail entry', 'badge' => 'badge in a list row',
              'listName' => 'list name'] as $part => $label) {
        $p = $fit[$part] ?? null;
        check("{$scale}: {$label} on one line, not clipped", is_array($p) && $p['oneLine'] && $p['inside'] && $p['notClipped'], $show($p));
    }
}
check('the fixed band does not grow with the slider', $at($m['font-125'], 'act', 'height') === 48 && $at($m['font-085'], 'act', 'height') === 48,
    $show($m['font-085']['act'] ?? null, $m['font-125']['act'] ?? null));

echo "P. The stored font scale — clamped to the slider's range on read and write\n";
$prefs = static fn (array $data): float => (new \Z77\Shared\ValueObjects\UserPreferences($data))->getFontScale();
check('no stored value: 1.0', $prefs([]) === 1.0);
check('an old value 1.4 (the former range) reads 1.25', $prefs(['font_scale' => 1.4]) === 1.25);
check('a value below the range reads 0.85', $prefs(['font_scale' => 0.5]) === 0.85);
check('a value off the 5 % step is rounded onto it', $prefs(['font_scale' => 1.07]) === 1.05);
$set = new \Z77\Shared\ValueObjects\UserPreferences();
$set->setFontScale(3.0);
check('setFontScale clamps (the save endpoint takes the posted number)', $set->getFontScale() === 1.25 && $set->toArray()['font_scale'] === 1.25);
$set->setFontScale(0.0);
check('setFontScale clamps a non-number (0) to the lower end', $set->getFontScale() === 0.85);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
