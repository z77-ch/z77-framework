#!/usr/bin/env node
/*
 * core.js windows in a real browser (headless Chrome / Edge over the DevTools protocol, node only,
 * no npm dependency) — WIN-002 (2026-11-11, the media picker, bauplan media-picker P1).
 *
 * A window opened while the shared popup (a MODAL <dialog>) is open must stand ABOVE it and be
 * usable: a modal dialog paints in the top layer and makes everything outside itself inert, so a
 * window in <body> was behind it, unreachable, and Esc closed the popup instead. core.js now
 * opens such a window in a layer INSIDE the dialog. What is load-bearing, with TRUSTED input
 * (Input.dispatchMouseEvent / dispatchKeyEvent — a synthetic event cannot prove inertness):
 *
 *   1  over the shared popup: the window opens inside the dialog, a hit test lands in it, a real
 *      click focuses its field, the popup's content is inert behind it; Esc and × close the
 *      window and not the popup, the popup is live again; a second Esc (no window left) closes
 *      the popup; closing the popup takes its window along and leaves nothing inert;
 *   2  over a page (unchanged): the window in <body>, the page inert, Esc closes it;
 *   3  over the bare page of the slot editor (html-bare-skeleton: the popup root is a <main>, not
 *      a dialog): the window opens, takes the focus, Esc closes it;
 *   6  a GET form[data-window-form="change"] inside a window (E16): «Anzeigen» re-renders the
 *      window in place, a choice at once, a focused date only when it loses the focus.
 *   7  «Hilfe ergänzen» (HELP-004, owner 2026-11-15): the pencil left of ⤢ only on a help whose
 *      template carries a same-origin `data-help-edit`; a real click opens the edit window ABOVE
 *      the docked and the full-screen help (its × is hit); the save's envelope closes the window
 *      and `help-addition` builds the section from its texts (no markup) and swaps it in the open help and
 *      the page's template; no paragraphs removes it.
 *
 * The pages are static (core.js + the backend's base.css, served by this script); the window's
 * content is a static answer. What it cannot prove: the server side — tests/dms-media-picker.php.
 *
 * Needs Chrome or Edge (or Z77_BROWSER=path). Exit 2 when none is found: a run without one has
 * verified nothing.
 * Run: node tests/core-js-windows-browser.mjs
 */

import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CANDIDATES = [process.env.Z77_BROWSER,
    'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/microsoft-edge'];
const BROWSER = CANDIDATES.find((p) => p && existsSync(p));
if (!BROWSER) {
    console.error('core-js-windows-browser: no browser found, set Z77_BROWSER.');
    process.exit(2);
}
console.log('browser: ' + BROWSER);

const head = '<!DOCTYPE html><html lang="de" class="be"><head><meta charset="utf-8"><meta name="csrf-token" content="x"><link rel="stylesheet" href="/base.css"></head>';
const FILES = {
    '/core.js':  ['application/javascript', readFileSync(join(ROOT, 'packages/kernel/shared/res/assets/js/core.js'))],
    '/base.css': ['text/css', readFileSync(join(ROOT, 'packages/module-backend/res/assets/css/base.css'))],
    '/win.html': ['text/html; charset=utf-8', '<div data-window="pick" data-window-entity="pick:1" data-window-title="Bild wählen"><p>Fenster</p><input id="inwin" name="q"></div>'],
    '/shell.html': ['text/html; charset=utf-8', head + '<body><div id="page"><p>Seite</p><button type="button" id="pageopener" data-window-open="/win.html">x</button></div>'
        + '<dialog id="z77-popup" class="be-modal" data-z77-popup><div class="be-modal__inner"><div class="z77-popup__body" data-z77-popup-body></div></div></dialog>'
        + '<script src="/core.js"></script></body></html>'],
    '/bare.html': ['text/html; charset=utf-8', head + '<body class="be-bare"><main class="be-bare__main" data-z77-popup><div class="be-bare__body" data-z77-popup-body>'
        + '<form><button type="button" id="opener" data-window-open="/win.html">Auswählen</button></form></div></main><script src="/core.js"></script></body></html>'],
    // WIN-003: a long page behind a window whose content is longer than the screen (the order
    // window over the order list), and a window opened from it (the customer picker).
    '/long.html': ['text/html; charset=utf-8', '<div data-window="order" data-window-entity="order:1" data-window-title="A-180321 · Support 2026">'
        + '<button type="button" id="childopener" data-window-open="/win.html">Kunde</button>'
        + Array.from({ length: 120 }, (_, i) => '<p>Zeile ' + i + '</p>').join('') + '</div>'],
    '/scroll.html': ['text/html; charset=utf-8', head + '<body><div id="page"><button type="button" id="pageopener" data-window-open="/long.html">Auftrag</button>'
        + Array.from({ length: 300 }, (_, i) => '<p>Liste ' + i + '</p>').join('') + '</div><script src="/core.js"></script></body></html>'],
    // DARK-NATIVE-001: what the browser draws itself (a native option list, a date picker)
    // follows the theme attribute.
    '/theme.html': ['text/html; charset=utf-8', head.replace('class="be"', 'class="be" data-be-theme="dark"') + '<body>'
        + '<label class="be-status-field"><span class="be-status-field__label">Status</span><select id="status"><option>Terminauftrag</option></select></label>'
        + '<input type="date" id="date" class="be-input"></body></html>'],
    // E16 (owner 2026-11-12): a GET form inside a window re-renders THAT window ([data-window-form]).
    // The answer echoes what it was asked for, so the test sees which load filled the window.
    '/post.html': (url) => {
        const cut = /^\d{4}-\d{2}-\d{2}$/.test(url.searchParams.get('cutoff') || '') ? url.searchParams.get('cutoff') : '2026-11-12';
        const k = url.searchParams.get('k') === 'b' ? 'b' : 'a';
        return ['text/html; charset=utf-8', '<div data-window="journal-post" data-window-entity="journal:open" data-window-title="Journal verbuchen">'
            + '<form method="get" action="/post.html" data-window-form="change"><input type="date" id="cutoff" name="cutoff" value="' + cut + '">'
            + '<select id="sel" name="k"><option' + (k === 'a' ? ' selected' : '') + '>a</option><option' + (k === 'b' ? ' selected' : '') + '>b</option></select>'
            + '<button type="submit" id="show">Anzeigen</button></form><p id="shown">' + cut + '|' + k + '</p></div>'];
    },
    '/postpage.html': ['text/html; charset=utf-8', head + '<body><div id="page"><button type="button" id="postopener" data-window-open="/post.html">Journal verbuchen</button></div><script src="/core.js"></script></body></html>'],
    // HELP-004: a page with two helps (one editable), the edit window, the save's envelope.
    '/helppage.html': ['text/html; charset=utf-8', head + '<body><div id="page"><button type="button" id="helptrigger" data-help-open data-help-trigger hidden>Hilfe</button></div>'
        + '<main><template data-help data-help-title="Hilfe: Dinge" data-help-topic="t1" data-help-edit="/helpedit.html?topic=t1"><h3>Dinge</h3><p>Alle Dinge.</p></template>'
        + '<template id="other" data-help data-help-title="Andere" data-help-topic="t2"><p>Ohne Ergänzung.</p></template>'
        + '<template id="foreign" data-help data-help-title="Fremd" data-help-topic="t3" data-help-edit="//evil.example/x"><p>Fremd.</p></template></main>'
        + '<script src="/core.js"></script></body></html>'],
    '/helpedit.html': ['text/html; charset=utf-8', '<div data-window="help-addition" data-window-entity="help-addition:t1" data-window-title="Hilfe ergänzen: Dinge" data-window-width="44rem">'
        + '<form method="post" action="/helpsave"><button type="submit" id="addsave">Speichern</button><textarea id="addtext" name="addition"></textarea></form></div>'],
    '/helpsave': ['application/json', JSON.stringify({ status: 'success', flashes: [], messages: [], commands: [{ action: 'close-window' },
        { action: 'help-addition', topic: 't1', title: 'Ergänzung', paragraphs: ['Hausregel <b>fett</b>\nZweite Zeile', 'Absatz 2'] }] })],
};
const server = createServer((req, res) => {
    const entry = FILES[req.url.split('?')[0]];
    const hit = typeof entry === 'function' ? entry(new URL(req.url, 'http://local')) : entry;
    if (!hit) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': hit[0] });
    res.end(hit[1]);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const BASE = 'http://127.0.0.1:' + server.address().port;

const profile = mkdtempSync(join(tmpdir(), 'z77-win-browser-'));
const debugPort = 9300 + Math.floor(Math.random() * 500);
const browser = spawn(BROWSER, ['--headless=new', '--disable-gpu', '--window-size=1280,900', '--remote-debugging-port=' + debugPort,
    '--user-data-dir=' + profile, '--no-first-run', '--disable-smooth-scrolling', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let ws;
let seq = 0;
const pending = new Map();
function send(method, params = {}) {
    return new Promise((resolve) => { const i = ++seq; pending.set(i, resolve); ws.send(JSON.stringify({ id: i, method, params })); });
}
async function ev(expr) {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.exceptionDetails ? 'EXC ' + JSON.stringify(r.exceptionDetails.exception && r.exceptionDetails.exception.description) : r.result.value;
}
async function click(sel) {
    const p = await ev(`(function(){var e=document.querySelector(${JSON.stringify(sel)}); if(!e) return null; var r=e.getBoundingClientRect(); return [r.left+r.width/2, r.top+r.height/2];})()`);
    if (!p) return false;
    for (const type of ['mousePressed', 'mouseReleased']) {
        await send('Input.dispatchMouseEvent', { type, x: p[0], y: p[1], button: 'left', clickCount: 1 });
    }
    return true;
}
async function esc() {
    for (const type of ['rawKeyDown', 'keyUp']) {
        await send('Input.dispatchKeyEvent', { type, key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27, nativeVirtualKeyCode: 27 });
    }
}
/* A trusted mouse wheel at a point (or the centre of an element), then a moment for the scroll. */
async function wheel(at, deltaY) {
    const p = typeof at === 'string'
        ? await ev(`(function(){var e=document.querySelector(${JSON.stringify(at)}); if(!e) return null; var r=e.getBoundingClientRect(); return [r.left+r.width/2, r.top+Math.min(r.height/2, 40)];})()`)
        : at;
    if (!p) return false;
    await send('Input.dispatchMouseEvent', { type: 'mouseWheel', x: p[0], y: p[1], deltaX: 0, deltaY });
    await sleep(250);
    return true;
}
async function until(expr, ms = 3000) {
    const end = Date.now() + ms;
    while (Date.now() < end) { if (await ev(expr)) return true; await sleep(50); }
    return false;
}

let pass = 0;
let fail = 0;
function check(label, ok, got) {
    if (ok) { pass++; console.log('  ok   ' + label); return; }
    fail++;
    console.log('  FAIL ' + label + (got !== undefined ? '\n       got: ' + JSON.stringify(got) : ''));
}
const WINDOWS = `document.querySelectorAll('[data-z77-window]').length`;
const ACTIVE = `document.activeElement && document.activeElement.id`;

try {
    let target = null;
    for (let i = 0; i < 100 && !target; i++) {
        try {
            const list = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
            target = list.find((t) => t.type === 'page');
        } catch { /* not up yet */ }
        if (!target) await sleep(100);
    }
    if (!target) throw new Error('the browser did not answer on its debugging port');
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r) => ws.addEventListener('open', r));
    ws.addEventListener('message', (m) => {
        const d = JSON.parse(m.data);
        if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result || d); pending.delete(d.id); }
    });
    await send('Page.enable');
    await send('Runtime.enable');

    console.log('1 a window over the shared popup (a modal <dialog>)');
    await send('Page.navigate', { url: BASE + '/shell.html' });
    await until(`!!(window._Z77 && _Z77.core && _Z77.core.windows)`);
    await ev(`_Z77.core.popup.show('<form><input id="inpopup" name="title"><button type="button" id="opener" data-window-open="/win.html">Auswählen</button></form>', '/x')`);
    await click('#opener');
    check('the window opens inside the dialog', await until(`!!document.querySelector('dialog[open] [data-z77-window]')`));
    check('a hit test lands in the window, not on the dialog', await ev(`(function(){var w=document.querySelector('.z77-window'),r=w.getBoundingClientRect(),h=document.elementFromPoint(r.left+r.width/2,r.top+15);return !!h&&w.contains(h);})()`));
    await click('#inwin');
    check('a real click focuses a field in the window', await until(`${ACTIVE} === 'inwin'`), await ev(ACTIVE));
    check('the popup\'s content is inert behind it', await ev(`document.querySelector('.be-modal__inner').inert`));
    await esc();
    check('Esc closes the window …', await until(`${WINDOWS} === 0`), await ev(WINDOWS));
    check('… not the popup, which is live again', await ev(`document.querySelector('dialog').open && !document.querySelector('.be-modal__inner').inert`));
    await click('#inpopup');
    check('a field in the popup takes the focus again', await until(`${ACTIVE} === 'inpopup'`));
    await click('#opener');
    await until(`${WINDOWS} === 1`);
    await click('.z77-window__close');
    check('× closes the window, the popup stays', await until(`${WINDOWS} === 0`) && await ev(`document.querySelector('dialog').open`));
    await click('#opener');
    await until(`${WINDOWS} === 1`);
    await esc();
    await until(`${WINDOWS} === 0`);
    await esc();
    check('a second Esc (no window left) closes the popup', await until(`!document.querySelector('dialog').open`));
    await ev(`_Z77.core.popup.show('<form><button type="button" id="opener" data-window-open="/win.html">Auswählen</button></form>', '/x')`);
    await click('#opener');
    await until(`${WINDOWS} === 1`);
    await ev(`_Z77.core.popup.close()`);
    check('closing the popup takes its window along', await until(`${WINDOWS} === 0`), await ev(WINDOWS));
    check('… nothing stays inert', await ev(`document.querySelectorAll('[inert]').length`) === 0, await ev(`document.querySelectorAll('[inert]').length`));

    console.log('2 a window over a page');
    await click('#pageopener');
    check('the window opens in <body>', await until(`!!document.querySelector('body > [data-z77-windows] > [data-z77-window]')`));
    check('the page is inert', await ev(`document.getElementById('page').inert`));
    await esc();
    check('Esc closes it, the page is live', await until(`${WINDOWS} === 0 && !document.getElementById('page').inert`));

    console.log('3 a window over the bare page (the slot editor\'s iframe page)');
    await send('Page.navigate', { url: BASE + '/bare.html' });
    await until(`!!(window._Z77 && _Z77.core && _Z77.core.windows)`);
    await click('#opener');
    check('the window opens', await until(`${WINDOWS} === 1`));
    await click('#inwin');
    check('a real click focuses a field in the window', await until(`${ACTIVE} === 'inwin'`));
    check('<main> is inert behind it', await ev(`document.querySelector('main').inert`));
    await esc();
    check('Esc closes it, <main> is live', await until(`${WINDOWS} === 0 && !document.querySelector('main').inert`));

    // WIN-003 (owner 2026-11-12, the order window over the order list): the wheel moved the page
    // behind and the window with it. A window stands fixed in the viewport, its content scrolls
    // inside, the page behind stays where it is while a window is open — and scrolls again after.
    console.log('4 a window over a long page stays put — the page behind does not scroll (WIN-003)');
    const PAGE_Y = `Math.round(window.scrollY)`;
    const WIN_TOP = (sel = '.z77-window') => `Math.round(document.querySelector(${JSON.stringify(sel)}).getBoundingClientRect().top)`;
    await send('Page.navigate', { url: BASE + '/scroll.html' });
    await until(`!!(window._Z77 && _Z77.core && _Z77.core.windows)`);
    await click('#pageopener');
    await until(`${WINDOWS} === 1`);
    const top0 = await ev(WIN_TOP());
    for (let i = 0; i < 4; i++) await wheel('.z77-window__body', 1500);
    check('the wheel over the window scrolls its own content …', await ev(`document.querySelector('.z77-window__body').scrollTop`) > 0);
    check('… to its end and no further: the page behind stays, the window stays', await ev(PAGE_Y) === 0 && await ev(WIN_TOP()) === top0
        && await ev(`document.querySelector('[data-z77-windows]').scrollTop`) === 0, [await ev(PAGE_Y), await ev(WIN_TOP()), top0]);
    await wheel('.z77-window__head', 1500);
    check('the wheel over the title bar moves nothing', await ev(PAGE_Y) === 0 && await ev(WIN_TOP()) === top0, [await ev(PAGE_Y), await ev(WIN_TOP())]);
    await wheel([12, 450], 1500);
    check('the wheel over the backdrop moves nothing (scroll lock)', await ev(PAGE_Y) === 0 && await ev(WIN_TOP()) === top0, [await ev(PAGE_Y), await ev(WIN_TOP())]);
    await ev(`document.querySelector('.z77-window__body').scrollTop = 0`);
    await click('#childopener');
    check('a window opened from it lies over its parent', await until(`!!document.querySelector('[data-z77-window-parent]')`)
        && await ev(`(function(){var w=document.querySelector('[data-z77-window-parent]'),r=w.getBoundingClientRect(),h=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return !!h&&w.contains(h);})()`));
    await wheel('[data-z77-window-parent]', 1500);
    check('the wheel over the child moves neither the page nor the parent', await ev(PAGE_Y) === 0 && await ev(WIN_TOP('[data-z77-window]:not([data-z77-window-parent])')) === top0);
    await esc();
    await until(`${WINDOWS} === 1`);
    await click('.z77-window__full');
    check('full screen covers the viewport', await until(`document.querySelector('.z77-window').classList.contains('is-full')`)
        && await ev(`(function(){var r=document.querySelector('.z77-window').getBoundingClientRect();return r.top===0&&Math.round(r.height)===innerHeight;})()`));
    for (let i = 0; i < 4; i++) await wheel('.z77-window__body', 1500);
    check('… and the wheel in it leaves the page where it was', await ev(PAGE_Y) === 0 && await ev(WIN_TOP()) === 0, [await ev(PAGE_Y), await ev(WIN_TOP())]);
    await click('.z77-window__full');
    await esc();
    await until(`${WINDOWS} === 0`);
    await wheel([640, 450], 600);
    check('the last window closed: the page scrolls again', await ev(PAGE_Y) > 0, await ev(PAGE_Y));
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: false });
    await ev(`window.scrollTo(0, 0)`);
    await click('#pageopener');
    await until(`${WINDOWS} === 1`);
    check('phone: the window covers the viewport', await ev(`(function(){var r=document.querySelector('.z77-window').getBoundingClientRect();return Math.round(r.top)===0&&Math.round(r.height)===innerHeight;})()`),
        await ev(`JSON.stringify(document.querySelector('.z77-window').getBoundingClientRect())`));
    for (let i = 0; i < 4; i++) await wheel('.z77-window__body', 1500);
    check('phone: the wheel scrolls the window\'s content, not the page', await ev(PAGE_Y) === 0 && await ev(WIN_TOP()) === 0
        && await ev(`document.querySelector('.z77-window__body').scrollTop`) > 0, [await ev(PAGE_Y), await ev(WIN_TOP())]);
    await esc();
    await until(`${WINDOWS} === 0`);
    await send('Emulation.clearDeviceMetricsOverride');

    // DARK-NATIVE-001 (owner 2026-11-12): the status select's option list was white on white in
    // the dark theme. What the browser draws (option list, date picker) follows `color-scheme`.
    console.log('5 native controls follow the theme (DARK-NATIVE-001)');
    await send('Page.navigate', { url: BASE + '/theme.html' });
    await until(`document.readyState === 'complete'`);
    const SCHEME = (sel) => `getComputedStyle(document.querySelector(${JSON.stringify(sel)})).colorScheme`;
    check('dark theme: the status select and a date input are drawn dark', await ev(SCHEME('#status')) === 'dark' && await ev(SCHEME('#date')) === 'dark',
        [await ev(SCHEME('#status')), await ev(SCHEME('#date'))]);
    await ev(`document.documentElement.setAttribute('data-be-theme', 'light')`);
    check('the theme switch to light: drawn light', await ev(SCHEME('#status')) === 'light' && await ev(SCHEME('#date')) === 'light');

    console.log('6 a GET form inside a window re-renders that window ([data-window-form], E16)');
    await send('Page.navigate', { url: BASE + '/postpage.html' });
    await until(`!!(window._Z77 && _Z77.core && _Z77.core.windows)`);
    await click('#postopener');
    const SHOWN = `(document.getElementById('shown')||{}).textContent`;
    check('the window opens with the proposed date', await until(`${SHOWN} === '2026-11-12|a'`), await ev(SHOWN));
    await ev(`document.getElementById('cutoff').value = '2026-10-31'`);
    await click('#show');
    check('«Anzeigen» re-renders the window in place: the new date, still one window, the page not left',
        await until(`${SHOWN} === '2026-10-31|a'`) && await ev(WINDOWS) === 1 && await ev(`location.pathname`) === '/postpage.html', [await ev(SHOWN), await ev(WINDOWS), await ev(`location.pathname`)]);
    await ev(`(function(){var s=document.getElementById('sel'); s.value='b'; s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    check('a choice re-renders at once (the date kept)', await until(`${SHOWN} === '2026-10-31|b'`), await ev(SHOWN));
    await ev(`(function(){var d=document.getElementById('cutoff'); d.focus(); d.value='2026-09-30'; d.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    await sleep(400);
    const typing = await ev(SHOWN);
    await ev(`document.getElementById('cutoff').blur()`);
    check('a date changed while it has the focus waits; leaving the field re-renders', typing === '2026-10-31|b' && await until(`${SHOWN} === '2026-09-30|b'`), [typing, await ev(SHOWN)]);
    await esc();
    await until(`${WINDOWS} === 0`);

    console.log('7 «Hilfe ergänzen»: the pencil, the edit window above the help, the swap (HELP-004)');
    await send('Page.navigate', { url: BASE + '/helppage.html' });
    await until(`!!(window._Z77 && _Z77.core && _Z77.core.help) && !document.getElementById('helptrigger').hidden`);
    await click('#helptrigger');
    const HEAD = `Array.prototype.map.call(document.querySelector('.z77-help__head').children, function (c) { return c.className; }).join(' ')`;
    check('the help opens; its head: title, ✎, ⤢, ×', await until(`!!document.querySelector('[data-z77-help]')`)
        && await ev(HEAD) === 'z77-help__title z77-help__edit z77-help__full z77-help__close', await ev(HEAD));
    check('… the pencil opens the edit address as a window, labelled', await ev(`document.querySelector('[data-help-edit-open]').getAttribute('data-window-open')`) === '/helpedit.html?topic=t1'
        && await ev(`document.querySelector('[data-help-edit-open]').getAttribute('aria-label')`) === 'Hilfe ergänzen');
    await ev(`_Z77.core.help.open(document.getElementById('other'))`);
    check('a help without data-help-edit: no pencil', await ev(`document.querySelectorAll('[data-help-edit-open]').length`) === 0, await ev(HEAD));
    await ev(`_Z77.core.help.open(document.getElementById('foreign'))`);
    check('an edit address that is not a same-origin path: no pencil', await ev(`document.querySelectorAll('[data-help-edit-open]').length`) === 0);
    await click('#helptrigger');
    await click('[data-help-full]');
    check('the help in full screen', await until(`document.querySelector('[data-z77-help]').classList.contains('is-full')`));
    await click('[data-help-edit-open]');
    const HIT = (sel) => `(function(){var e=document.querySelector(${JSON.stringify(sel)}); if(!e) return false; var r=e.getBoundingClientRect(), h=document.elementFromPoint(r.left+r.width/2, r.top+r.height/2); return !!h && e.contains(h);})()`;
    check('a real click on ✎ opens the edit window — above the full-screen help (its × is hit)', await until(`${WINDOWS} === 1`) && await until(HIT('.z77-window__close')));
    await click('.z77-window__close');
    check('× closes it, the help stays', await until(`${WINDOWS} === 0`) && await ev(`!!document.querySelector('[data-z77-help]')`));
    await click('[data-help-full]');
    await click('[data-help-edit-open]');
    check('docked help: the edit window stands above it too (× and the textarea are hit)', await until(`${WINDOWS} === 1`)
        && await until(HIT('.z77-window__close')) && await ev(HIT('#addtext')));
    await click('#addtext');
    check('a real click focuses the textarea', await until(`${ACTIVE} === 'addtext'`), await ev(ACTIVE));
    await click('#addsave');
    const ADD = `(document.querySelector('.z77-help__body [data-help-addition] p')||{}).textContent`;
    check('«Speichern»: the window closes, the help shows the new addition as TEXT (heading, two paragraphs, a line break as <br>)',
        await until(`${WINDOWS} === 0`) && await until(`${ADD} === 'Hausregel <b>fett</b>Zweite Zeile'`)
        && await ev(`document.querySelectorAll('.z77-help__body [data-help-addition] b').length`) === 0
        && await ev(`document.querySelectorAll('.z77-help__body [data-help-addition] p').length`) === 2
        && await ev(`document.querySelectorAll('.z77-help__body [data-help-addition] p br').length`) === 1
        && await ev(`document.querySelector('.z77-help__body [data-help-addition] h4').textContent`) === 'Ergänzung', await ev(ADD));
    check('… the page\'s template of that topic carries it too (a reopened help shows it), the other help not',
        await ev(`!!document.querySelector('template[data-help-topic="t1"]').content.querySelector('[data-help-addition]')`)
        && await ev(`!document.getElementById('other').content.querySelector('[data-help-addition]')`));
    await ev(`_Z77.core.fetch.handleEnvelope({status:'success',flashes:[],messages:[],commands:[{action:'help-addition',topic:'t1',title:'Ergänzung',paragraphs:[]}]}, '/helpsave')`);
    check('no paragraphs: the addition is removed from the help and the template', await ev(`document.querySelectorAll('[data-help-addition]').length`) === 0
        && await ev(`!document.querySelector('template[data-help-topic="t1"]').content.querySelector('[data-help-addition]')`));
    await click('[data-help-close]');
} catch (e) {
    fail++;
    console.log('  FAIL ' + (e && e.message ? e.message : e));
}

console.log(`\n${pass} passed, ${fail} failed`);
try { ws && ws.close(); } catch { /* gone */ }
browser.kill();
server.close();
await sleep(300);
try { rmSync(profile, { recursive: true, force: true }); } catch { /* the browser may still hold a file */ }
process.exit(fail ? 1 : 0);
