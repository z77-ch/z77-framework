#!/usr/bin/env node
/*
 * core.js behaviour harness (offline, node only, no npm dependency).
 *
 * Loads packages/kernel/shared/res/assets/js/core.js into a node `vm` context with a
 * small stub DOM — just enough for the paths under test, nothing like a browser — and
 * checks behaviour that a static grep cannot see (review 2026-10-13, §6):
 *
 *   F  _z77CollectFormData: scalars, bracket names, keyed single switch (`roles[debtor]`),
 *      checkbox groups, radios, disabled fields and buttons left out
 *   B  busy guard: a second submit while the first runs is ignored, aria-busy and the
 *      disabled submitter are released in finally — after success AND after failure
 *   W  wire(): a double submit of a `data-fetch-post` form sends ONE request
 *   R  fetch regions: an older answer arriving after a newer one does not win; the
 *      swapped content is wired
 *   C  close-modal from a window that is gone closes nothing else
 *   X  windows: a 403 envelope / a 500 page never lands as text in a window
 *
 * What it cannot prove: layout, focus, real event order — those need a browser.
 * Run: node tests/core-js.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SOURCE = readFileSync(join(ROOT, 'packages/kernel/shared/res/assets/js/core.js'), 'utf8');

let pass = 0;
let fail = 0;
function check(label, ok, got) {
    if (ok) { pass++; console.log('  ok   ' + label); return; }
    fail++;
    console.log('  FAIL ' + label + (got !== undefined ? '\n       got: ' + JSON.stringify(got) : ''));
}
const tick = () => new Promise((r) => setTimeout(r, 0));

// ── stub DOM ────────────────────────────────────────────────────────────────
class El {
    constructor(tag = 'div', props = {}) {
        this.tagName = tag.toUpperCase();
        this.attrs = {};
        this.children = [];
        this.listeners = {};
        this.textContent = '';
        this.parentNode = null;
        this.nodeType = 1;
        this.classList = { _s: new Set(), add: (c) => this.classList._s.add(c), remove: (c) => this.classList._s.delete(c),
            contains: (c) => this.classList._s.has(c), toggle: (c, on) => (on ?? !this.classList._s.has(c)) ? this.classList._s.add(c) : this.classList._s.delete(c) };
        this.style = { setProperty() {}, removeProperty() {} };
        this.dataset = {};
        this.qsa = {};          // selector → list, set by a test
        Object.assign(this, props);
    }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
    hasAttribute(n) { return n in this.attrs; }
    removeAttribute(n) { delete this.attrs[n]; }
    toggleAttribute(n) { if (n in this.attrs) delete this.attrs[n]; else this.attrs[n] = ''; }
    appendChild(c) { this.children.push(c); c.parentNode = this; return c; }
    insertBefore(c) { return this.appendChild(c); }
    removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentNode = null; return c; }
    replaceWith(n) { this.replacedBy = n; this.isConnected = false; n.isConnected = true; }
    addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); }
    dispatch(t, e) { (this.listeners[t] || []).forEach((fn) => fn(e)); }
    querySelectorAll(sel) { return this.qsa[sel] || []; }
    querySelector(sel) { return (this.qsa[sel] || [])[0] || null; }
    closest() { return null; }
    contains(x) { return x === this; }
    matches() { return false; }
    focus() {}
}

function makeContext() {
    const timers = [];
    const messages = new El('div');
    const flashes = new El('div');
    const body = new El('body');
    const document = new El('#document', {
        body,
        documentElement: new El('html', { clientWidth: 1000 }),
        activeElement: null,
        createElement: (t) => new El(t),
        getElementById: (id) => (id === 'messages' ? messages : id === 'flash-messages' ? flashes : null),
    });
    const location = { href: 'http://test/page', origin: 'http://test', assign(u) { this.href = u; }, reload() {} };
    const fetchQueue = [];
    const ctx = {
        document, location, console, URL, URLSearchParams, Promise, JSON, Array, Object, String,
        CSS: { escape: (s) => String(s).replace(/["\\]/g, '\\$&') },
        history: { state: null, replaceState(_s, _t, u) { ctx.history.last = u; } },
        setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
        getComputedStyle: () => ({ overflowY: 'auto' }),
        innerWidth: 1000,
        addEventListener() {},
        navigator: {},
        // Every fetch() is parked until the test answers it: fetchQueue[i].answer(response).
        fetch: (url, init) => new Promise((resolve, reject) => fetchQueue.push({ url, init, answer: resolve, fail: reject })),
        DOMParser: class { parseFromString(html) { return ctx.parsed[html] || new El('#doc'); } },
        parsed: {},
    };
    ctx.window = ctx;
    vm.createContext(ctx);
    vm.runInContext(SOURCE, ctx, { filename: 'core.js' });
    return { ctx, document, messages, fetchQueue, timers };
}

function response({ status = 200, type = 'text/html', body = '' }) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: (h) => (h.toLowerCase() === 'content-type' ? type : null) },
        text: () => Promise.resolve(typeof body === 'string' ? body : JSON.stringify(body)),
        json: () => Promise.resolve(typeof body === 'string' ? JSON.parse(body) : body),
    };
}

// ── F: _z77CollectFormData ──────────────────────────────────────────────────
console.log('F  _z77CollectFormData');
{
    const { ctx } = makeContext();
    const field = (p) => Object.assign({ name: '', type: 'text', value: '', checked: false, disabled: false, tagName: 'INPUT' }, p);
    const elements = [
        field({ name: 'name', value: 'Muster AG' }),
        field({ name: 'value[de]', value: 'Hallo' }),
        field({ name: 'value[fr]', value: 'Salut' }),
        field({ name: 'roles[debtor]', type: 'checkbox', checked: true }),
        field({ name: 'active', type: 'checkbox', checked: false }),
        field({ name: 'tags', type: 'checkbox', value: 'a', checked: true }),
        field({ name: 'tags', type: 'checkbox', value: 'b', checked: false }),
        field({ name: 'tags', type: 'checkbox', value: 'c', checked: true }),
        field({ name: 'kind', type: 'radio', value: 'x', checked: false }),
        field({ name: 'kind', type: 'radio', value: 'y', checked: true }),
        field({ name: 'locked', value: 'no', disabled: true }),
        field({ name: 'op', type: 'submit', value: 'save', tagName: 'BUTTON' }),
    ];
    const form = { elements, querySelectorAll: (sel) => (sel === 'input[type=checkbox][name]' ? elements.filter((e) => e.type === 'checkbox') : []) };
    const data = JSON.parse(JSON.stringify(ctx._z77CollectFormData(form)));
    check('F1 a scalar arrives as is', data.name === 'Muster AG', data.name);
    check('F2 bracket names nest (value[de] → value.de)', data.value && data.value.de === 'Hallo' && data.value.fr === 'Salut', data.value);
    check('F3 a single keyed switch nests (roles[debtor] → roles.debtor = true)', data.roles && data.roles.debtor === true && !('roles[debtor]' in data), data);
    check('F4 a single plain switch is a boolean', data.active === false, data.active);
    check('F5 a checkbox group is the list of checked values', JSON.stringify(data.tags) === '["a","c"]', data.tags);
    check('F6 a radio group sends the checked value', data.kind === 'y', data.kind);
    check('F7 a disabled field is left out', !('locked' in data), data);
    check('F8 a button is left out', !('op' in data), data);
}

// ── B: busy guard ───────────────────────────────────────────────────────────
console.log('B  busy guard');
{
    const { ctx } = makeContext();
    const busy = ctx._Z77.core.busy;
    const owner = new El('form');
    const button = new El('button', { disabled: false });
    let sent = 0, settle;
    const first = busy.guard(owner, button, () => { sent++; return new Promise((r) => { settle = r; }); });
    check('B1 the first send goes out', sent === 1 && first !== null);
    check('B2 the owner carries aria-busy="true"', owner.getAttribute('aria-busy') === 'true');
    check('B3 the submitter is disabled while it runs', button.disabled === true);
    const second = busy.guard(owner, button, () => { sent++; return Promise.resolve(); });
    check('B4 a second send while busy is ignored (null, nothing sent)', second === null && sent === 1);
    settle();
    await first;
    check('B5 released after success: no aria-busy, submitter enabled', !owner.hasAttribute('aria-busy') && button.disabled === false && !busy.isBusy(owner));

    const failing = busy.guard(owner, button, () => Promise.reject(new Error('net')));
    await failing.catch(() => {});
    check('B6 released after a failure too (finally)', !busy.isBusy(owner) && button.disabled === false);

    const pre = new El('button', { disabled: true });
    await busy.guard(owner, pre, () => Promise.resolve());
    check('B7 a control that was disabled before stays disabled', pre.disabled === true);
}

// ── W: wire() — a double submit sends one request ───────────────────────────
console.log('W  wire() double submit');
{
    const { ctx, fetchQueue } = makeContext();
    const form = new El('form', { elements: [], dataset: { fetchPost: '/backend/contact/contact/add' } });
    form.querySelectorAll = (sel) => (sel === 'input[type=checkbox][name]' ? [] : []);
    const container = new El('div');
    container.qsa['[data-fetch-post]'] = [form];
    ctx._Z77.core.wire(container);
    const submitter = new El('button', { disabled: false });
    const ev = () => ({ preventDefault() { this.prevented = true; }, submitter });
    const e1 = ev(), e2 = ev();
    form.dispatch('submit', e1);
    form.dispatch('submit', e2);
    check('W1 two submits → one request', fetchQueue.length === 1, fetchQueue.length);
    check('W2 the second submit is still prevented (no native page post)', e2.prevented === true);
    check('W3 the form is aria-busy, the submitter disabled', form.getAttribute('aria-busy') === 'true' && submitter.disabled === true);
    fetchQueue[0].answer(response({ type: 'application/json', body: { status: 'success', flashes: [], messages: [] } }));
    await tick(); await tick(); await tick();
    check('W4 after the answer the form is free again', !form.hasAttribute('aria-busy') && submitter.disabled === false);
    form.dispatch('submit', ev());
    check('W5 a later submit goes out again', fetchQueue.length === 2, fetchQueue.length);
}

// ── R: fetch regions — the latest answer wins, the new content is wired ─────
console.log('R  fetch regions');
{
    const { ctx, fetchQueue } = makeContext();
    const region = new El('section', { isConnected: true });
    region.setAttribute('data-fetch-region', 'list');
    const older = new El('section'), newer = new El('section');
    const kebab = new El('button', { dataset: { fetchGet: '/x/actions?id=1' } });
    newer.qsa['[data-fetch-get]'] = [kebab];
    const docOld = new El('#doc'); docOld.qsa['[data-fetch-region="list"]'] = [older];
    const docNew = new El('#doc'); docNew.qsa['[data-fetch-region="list"]'] = [newer];
    ctx.parsed = { OLD: docOld, NEW: docNew };

    const p1 = ctx._Z77.core.region.load(region, 'http://test/list?page=1');
    const p2 = ctx._Z77.core.region.load(region, 'http://test/list?page=2');
    fetchQueue[1].answer(response({ body: 'NEW' }));
    await p2;
    fetchQueue[0].answer(response({ body: 'OLD' }));
    await p1;
    check('R1 the newer answer is in (page=2)', region.replacedBy === newer, region.replacedBy && region.replacedBy === older ? 'older' : null);
    check('R2 the address bar follows the newer load only', ctx.history.last === 'http://test/list?page=2', ctx.history.last);
    check('R3 the swapped content is wired (its ⋮ has a click listener)', (kebab.listeners.click || []).length === 1);
}

// ── C: close-modal from a window that is gone ───────────────────────────────
console.log('C  close-modal');
{
    const { ctx, document } = makeContext();
    let closed = 0;
    const popup = new El('dialog', { open: true, close() { closed++; } });
    popup.qsa['[data-z77-popup-body]'] = [new El('div')];
    document.qsa['[data-z77-popup]'] = [popup];
    ctx._Z77.core.fetch.handleEnvelope({ commands: [{ action: 'close-modal' }] }, '/x', { window: '7' });
    check('C1 a request from a window that is gone does not close the open popup', closed === 0, closed);
    ctx._Z77.core.fetch.handleEnvelope({ commands: [{ action: 'close-modal' }] }, '/x');
    check('C2 from the page it closes the popup', closed === 1, closed);
}

// ── X: windows never show an error as content ───────────────────────────────
console.log('X  windows: status and envelope');
{
    const { ctx, fetchQueue, messages, timers } = makeContext();
    const opened = ctx._Z77.core.windows.open('/backend/contact/contact/edit?id=1');
    fetchQueue[0].answer(response({ status: 403, type: 'application/json', body: { status: 'error', redirect: { url: '/backend/system/login/login' } } }));
    const win = await opened;
    check('X1 a 403 envelope opens no window', win === null, win);
    timers.forEach((t) => t.fn());
    check('X2 its redirect runs (expired session → login)', ctx.location.href === '/backend/system/login/login', ctx.location.href);

    ctx.location.href = 'http://test/page';
    const opened2 = ctx._Z77.core.windows.open('/backend/contact/contact/edit?id=2');
    fetchQueue[1].answer(response({ status: 500, body: '<h1>500</h1><pre>trace</pre>' }));
    const win2 = await opened2;
    const text = messages.children.map((m) => m.children[0] && m.children[0].textContent).join(' | ');
    check('X3 a 500 page opens no window', win2 === null, win2);
    check('X4 it becomes one error message with the status', /HTTP 500/.test(text) && !/trace/.test(text), text);
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
