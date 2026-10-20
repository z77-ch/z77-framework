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
 *   T  tree picker: index (roots, children, path), the search over all levels (every word
 *      in the path, one in the own name, order, limit), marks; no HTML from strings
 *   P  price mode: rows convert with their own rate (net ↔ gross, half away from zero);
 *      dated rates on a code picker ([data-rate-keep-label] — the invoice editor)
 *   K  the article picker's parts: pick-list steps, the `add-row` and `focus` commands (a clone
 *      of the template, `__i__` replaced, the value + change, the focus), the window context
 *      (data-window-with, autofocus, Esc), no HTML from strings
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

// ── T: tree picker — the pure parts (index, search, marks) ─────────────────
console.log('T  tree picker');
{
    const { ctx } = makeContext();
    const tp = ctx._Z77.core.treePicker;
    const nodes = [
        { id: 1, parent: null, name: 'Handelswaren' },
        { id: 2, parent: 1, name: 'Elektronik' },
        { id: 3, parent: 2, name: 'Speicher' },
        { id: 4, parent: 3, name: 'SD-Cards' },
        { id: 5, parent: 3, name: 'USB-Sticks' },
        { id: 6, parent: null, name: 'Wein' },
        { id: 7, parent: 6, name: 'Weisswein' },
        { id: 8, parent: 7, name: 'Wallis' },
        { id: 9, parent: 6, name: 'Rotwein' },
        { id: 10, parent: 9, name: 'Wallis' },
        { id: 11, parent: 99, name: 'Waise' },
        { id: 12, parent: 8, name: 'Weisswallis Spezial' },
    ];
    const idx = tp.index(nodes);
    const names = (list) => list.map((n) => n.name + ':' + n.id).join(',');
    check('T1 index: the roots in order (a node whose parent is missing counts as a root), the children per parent',
        names(idx.kids['']) === 'Handelswaren:1,Wein:6,Waise:11' && names(idx.kids['3']) === 'SD-Cards:4,USB-Sticks:5' && !idx.kids['4'], Object.keys(idx.kids));
    check('T2 index: the path of a node, the root first', idx.path['4'].map((n) => n.name).join(' › ') === 'Handelswaren › Elektronik › Speicher › SD-Cards');
    const sd = tp.search(idx, 'sd');
    check('T3 search: a part of a name on any level', names(sd.nodes) === 'SD-Cards:4' && sd.total === 1, names(sd.nodes));
    const ww = tp.search(idx, 'weiss wallis');
    check('T4 search, several words: every word in the path, at least one in the own name — the Wallis under Weisswein, not the one under Rotwein; a node with ALL words in its own name comes first, even when deeper',
        names(ww.nodes) === 'Weisswallis Spezial:12,Wallis:8', names(ww.nodes));
    const wein = tp.search(idx, 'wein');
    check('T5 search order: shallow first, then by name', names(wein.nodes) === 'Wein:6,Rotwein:9,Weisswein:7', names(wein.nodes));
    check('T6 search: a word only in the path is no hit by itself («elektronik» finds the group, not its children)', names(tp.search(idx, 'elektronik').nodes) === 'Elektronik:2');
    check('T7 search: the limit cuts the list, the total stays', tp.search(idx, 'w', 2).nodes.length === 2 && tp.search(idx, 'w', 2).total > 2);
    check('T8 search: an empty query finds nothing', tp.search(idx, '   ').total === 0);
    check('T9 marks: the words in the name, sorted, case-insensitive, overlaps dropped',
        JSON.stringify(tp.marks('Weisswein Wallis', 'wallis weiss')) === '[[0,5],[10,16]]'
        && JSON.stringify(tp.marks('SD-Cards', 'sd-c sd')) === '[[0,4]]' && JSON.stringify(tp.marks('Wein', '')) === '[]',
        [tp.marks('Weisswein Wallis', 'wallis weiss'), tp.marks('SD-Cards', 'sd-c sd')]);
    const from = SOURCE.indexOf('/* ── tree picker'), to = SOURCE.indexOf('/* ── small triggers');
    check('T10 the tree picker section builds no HTML from strings (no innerHTML / outerHTML / insertAdjacentHTML / eval — Rule 7)',
        from > 0 && to > from && !/innerHTML|outerHTML|insertAdjacentHTML|eval\(/.test(SOURCE.slice(from, to)));
}

// ── P: price mode + dated rates on a code picker (the invoice editor, owner 2026-10-19) ──
console.log('P  price mode, dated rates');
{
    const { ctx, document } = makeContext();
    ctx.Event = class { constructor(type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); } };
    const option = (value, label, rate) => {
        const o = new El('option', { value, textContent: label });
        if (rate !== undefined) o.setAttribute('data-rate', rate);
        return o;
    };
    const fired = (el) => { el.events = []; el.dispatchEvent = (e) => el.events.push(e.type); return el; };
    // A row: price field + the MWST code select (label = the CODE, `data-rate` = the rate).
    const row = (price, code) => {
        const r = new El('div');
        const field = fired(new El('input', { value: price }));
        const select = fired(new El('select'));
        select.options = [option('', '–'), option('UN', 'UN', '8.1'), option('RE', 'RE', '2.6'), option('XX', 'XX')];
        select.selectedIndex = Math.max(0, select.options.findIndex((o) => o.value === code));
        select.setAttribute('data-rate-keep-label', '');
        r.qsa['[data-price-mode-price]'] = [field];
        r.qsa['[data-price-mode-rate]'] = [select];
        return { r, field, select };
    };
    const rows = [row('20.00', 'UN'), row('0.05', 'UN'), row('100.00', 'RE'), row('50.00', 'XX'), row('', 'UN')];
    const form = new El('form');
    const mode = fired(new El('select', { value: 'gross' }));
    mode.matches = (sel) => sel === 'select[data-price-mode-select]';
    mode.closest = (sel) => (sel === '[data-price-mode]' ? form : null);
    const shown = fired(new El('input', { value: 'net' }));
    form.qsa['[data-price-mode-select]'] = [mode];
    form.qsa['[data-price-mode-shown]'] = [shown];
    form.qsa['[data-row]'] = rows.map((x) => x.r);
    ctx._Z77.core.priceMode.bind();
    const settle = async () => { for (let i = 0; i < 6; i++) await tick(); };
    document.dispatch('change', { target: mode });
    await settle();
    check('P1 net → gross converts each row with ITS rate, cents half away from zero (20.00 → 21.62 at 8.1 %, 0.05 → 0.05, 100.00 → 102.60 at 2.6 %); a row without a rate and an empty price stay; the shown mode follows',
        rows.map((x) => x.field.value).join(' ') === '21.62 0.05 102.60 50.00 ' && shown.value === 'gross' && rows[0].field.events.includes('input'),
        rows.map((x) => x.field.value));
    mode.value = 'net';
    document.dispatch('change', { target: mode });
    await settle();
    check('P2 and back gross → net (21.62 → 20.00, 102.60 → 100.00)', rows.map((x) => x.field.value).join(' ') === '20.00 0.05 100.00 50.00 ' && shown.value === 'net',
        rows.map((x) => x.field.value));

    // Dated rates on the same select: the rate follows the service date, the label (the code) stays.
    const island = new El('script', { textContent: JSON.stringify({ UN: [['2011-01-01', '8'], ['2018-01-01', '7.7'], ['2024-01-01', '8.1']] }) });
    island.setAttribute('data-rate-schedule', 'invoice-vat');
    island.setAttribute('data-rate-date', 'service_from invoice_date');
    const dated = new El('form', { elements: { namedItem: (n) => (n === 'service_from' ? { value: '' } : n === 'invoice_date' ? { value: '2018-06-30' } : null) } });
    island.closest = (sel) => (sel === 'form' ? dated : null);
    const select = rows[0].select;
    const plain = fired(new El('select'));
    plain.options = [option('UN', '8.1 %', '8.1')];
    plain.selectedIndex = 0;
    dated.qsa['select[data-rate-schedule-ref="invoice-vat"]'] = [select, plain];
    ctx._Z77.core.datedRates.apply(island);
    check('P3 dated rates: the service date empty → the invoice date (2018) decides — UN 7.7 on both selects; [data-rate-keep-label] keeps the code «UN», the plain one shows «7.7 %»; a changed rate fires change',
        select.options[1].getAttribute('data-rate') === '7.7' && select.options[1].textContent === 'UN' && plain.options[0].textContent === '7.7 %'
        && select.events.includes('change'), [select.options[1].getAttribute('data-rate'), select.options[1].textContent, plain.options[0].textContent]);
}

// ── K: the article picker's parts — pick list, add-row, focus, window context (owner 2026-10-19) ──
console.log('K  pick list, add-row, window context');
{
    const { ctx, document } = makeContext();
    ctx.Event = class { constructor(type, init) { this.type = type; this.bubbles = !!(init && init.bubbles); } };
    const fired = (el) => { el.events = []; el.dispatchEvent = (e) => el.events.push(e.type); return el; };
    const step = ctx._Z77.core.pickList.step;
    check('K1 pick list: ↓ from none → the first, ↑ from none → the last; a step stops at the ends; no items → none',
        step(-1, 1, 3) === 0 && step(-1, -1, 3) === 2 && step(0, 1, 3) === 1 && step(2, 1, 3) === 2 && step(0, -1, 3) === 0 && step(-1, 1, 0) === -1);

    // add-row: the template cloned into the box, `__i__` replaced (also in href / data-window-open),
    // the value written into the field the server named + `change` (so «fill» runs), the focus set.
    const box = fired(new El('div', { attrs: { 'data-rows': 'rows' } }));
    const article = fired(new El('input', { value: '' }));
    const qty = new El('input');
    let focused = null;
    qty.focus = () => { focused = qty; };
    const link = new El('a', { attrs: { href: '/p/article-pick?target=order-row-__i__-article', 'data-window-open': '/p/article-pick?target=order-row-__i__-article' } });
    const row = new El('div', { attrs: { 'data-row': '' } });
    row.qsa['*'] = [article, qty, link];
    row.qsa['[data-fill-url]'] = [article];
    row.qsa['[data-sum-qty]'] = [qty];
    const tpl = new El('template', { content: { firstElementChild: { cloneNode: () => row } } });
    tpl.closest = () => box;
    box.qsa['template[data-row-template="article"]'] = [tpl];
    document.qsa['[data-rows="rows"]'] = [box];
    ctx._Z77.core.fetch.handleEnvelope({ commands: [{ action: 'add-row', target: '[data-rows="rows"]', kind: 'article', values: { '[data-fill-url]': 'SD-32' }, focus: '[data-sum-qty]' }] }, '/x');
    check('K2 add-row: a clone of the template appended, `__i__` → a unique key in href and data-window-open, the article field takes the number and fires change, the quantity takes the focus, the box fires input',
        box.children[0] === row && /target=order-row-n\d+-article$/.test(link.getAttribute('href')) && link.getAttribute('data-window-open') === link.getAttribute('href')
        && article.value === 'SD-32' && article.events.includes('change') && focused === qty && box.events.includes('input'),
        [box.children.length, link.getAttribute('href'), article.value, article.events, box.events]);
    const before = box.children.length;
    ctx._Z77.core.fetch.handleEnvelope({ commands: [{ action: 'add-row', target: '[data-rows="rows"]', kind: 'text' }] }, '/x');
    check('K3 add-row without such a template adds nothing', box.children.length === before);

    const field = new El('input');
    let got = false;
    field.focus = () => { got = true; };
    document.qsa['#order-row-3-quantity'] = [field];
    ctx._Z77.core.fetch.handleEnvelope({ commands: [{ action: 'focus', target: '#order-row-3-quantity' }] }, '/x');
    check('K4 focus: the named field takes the focus', got);

    const from = SOURCE.indexOf('/* ── pick list'), to = SOURCE.indexOf('/* ── small triggers');
    check('K5 the pick list builds no HTML and runs no code from strings (Rule 7); Enter clicks the server\'s button, typing submits the search form',
        from > 0 && to > from && !/innerHTML|outerHTML|insertAdjacentHTML|eval\(/.test(SOURCE.slice(from, to))
        && SOURCE.slice(from, to).includes('active.click()') && SOURCE.slice(from, to).includes('form.requestSubmit()'));
    check('K6 windows: [data-window-with] fields ride along at the click, [autofocus] takes the focus, an Esc a control used itself keeps the window; a tree picker in a region reloads the region; a region keeps what was typed',
        SOURCE.includes("open(_withFields(opener, opener.getAttribute('data-window-open'))") && SOURCE.includes("win.querySelector('[autofocus]')")
        && SOURCE.includes("e.key !== 'Escape' || e.defaultPrevented") && SOURCE.includes("if (inRegion) pick.setAttribute('data-fetch-region-link', '')")
        && SOURCE.includes('if (link) link.click();') && !SOURCE.includes('window.location.assign(link.href)') && SOURCE.includes('el.value = typed;'));

    // «+ Artikel» keeps the picker open (owner 2026-10-20): add-row without `focus` hands the
    // focus back to where it was (the picker's button), not into the new row behind.
    const pickBtn = new El('button');
    pickBtn.isConnected = true;
    let back = false;
    pickBtn.focus = () => { back = true; };
    const prevActive = document.activeElement;
    document.activeElement = pickBtn;
    ctx._Z77.core.fetch.handleEnvelope({ commands: [{ action: 'add-row', target: '[data-rows="rows"]', kind: 'article', values: { '[data-fill-url]': 'SD-64' } }] }, '/x');
    document.activeElement = prevActive;
    check('K7 add-row without `focus`: the row is added and the focus goes back to the element that had it (the picker stays usable)',
        back && article.value === 'SD-64', [back, article.value]);
    check('K8 windows: [data-window-dismiss] closes a chooser on a click outside it (not on the message channels, not on a removed target); wire() enhances tree pickers and publishes the action bar\'s height (--z77-form-actions-h)',
        SOURCE.includes("w.querySelector('[data-window-dismiss]')") && SOURCE.includes("e.target.closest('#messages, #flash-messages, [data-z77-help]')")
        && /_Z77\.core\.wire = function[\s\S]{0,600}_Z77\.core\.treePicker\.init\(container\);\s*_Z77\.core\.stickyTop\.init\(container\);/.test(SOURCE)
        && SOURCE.includes("setProperty('--z77-form-actions-h', (bar.offsetHeight + top) + 'px')"));
}

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
