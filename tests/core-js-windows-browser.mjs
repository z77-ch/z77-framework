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
 *      a dialog): the window opens, takes the focus, Esc closes it.
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
};
const server = createServer((req, res) => {
    const hit = FILES[req.url.split('?')[0]];
    if (!hit) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': hit[0] });
    res.end(hit[1]);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const BASE = 'http://127.0.0.1:' + server.address().port;

const profile = mkdtempSync(join(tmpdir(), 'z77-win-browser-'));
const debugPort = 9300 + Math.floor(Math.random() * 500);
const browser = spawn(BROWSER, ['--headless=new', '--disable-gpu', '--window-size=1280,900', '--remote-debugging-port=' + debugPort,
    '--user-data-dir=' + profile, '--no-first-run', 'about:blank'], { stdio: 'ignore' });
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
