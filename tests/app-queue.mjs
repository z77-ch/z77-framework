#!/usr/bin/env node
/*
 * The app's offline queue (module-api app.js, `_Z77.app.queue`) — security review S1 of the
 * app-user sign-in (ADR-058). Offline, node only, no npm dependency: app.js runs in a node `vm`
 * context with an in-memory IndexedDB stub and a fetch stub — just enough for the queue, nothing
 * like a browser.
 *
 *   S1  an entry is stamped with the app user signed in now; flush() sends only the entries of
 *       that app user — the waiting captures of another person (signed in earlier on this
 *       browser) never go out under the new sign-in; an entry without a stamp is foreign too;
 *       nobody signed in = nothing is sent
 *
 * What it cannot prove: the start page's cards and the sign-in redirect — those need a browser.
 * Run: node tests/app-queue.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SOURCE = readFileSync(join(ROOT, 'packages/module-api/res/assets/js/app.js'), 'utf8');

let pass = 0;
let fail = 0;
function check(label, ok, got) {
    if (ok) { pass++; console.log('  ok   ' + label); }
    else { fail++; console.log('  FAIL ' + label + (got !== undefined ? '\n       got: ' + JSON.stringify(got) : '')); }
}

/** An IndexedDB that keeps two object stores in memory; requests answer on the next tick. */
function fakeIndexedDB() {
    const data = { kv: new Map(), queue: new Map() };
    const later = (fn) => setTimeout(fn, 0);
    const request = (result) => {
        const req = { result: undefined, onsuccess: null };
        later(() => { req.result = result(); if (req.onsuccess) req.onsuccess(); });
        return req;
    };
    const db = {
        objectStoreNames: { contains: () => true },
        transaction(name) {
            const tx = { oncomplete: null, onerror: null, onabort: null, error: null };
            const map = data[name];
            tx.objectStore = () => ({
                get: (key) => request(() => map.get(key)),
                put: (value, key) => request(() => { map.set(key !== undefined ? key : value.key, structuredClone(value)); }),
                delete: (key) => request(() => { map.delete(key); }),
                clear: () => request(() => { map.clear(); }),
                getAll: () => request(() => [...map.values()].map((v) => structuredClone(v)))
            });
            setTimeout(() => setTimeout(() => { if (tx.oncomplete) tx.oncomplete(); }, 0), 0);
            return tx;
        }
    };
    return {
        data,
        open() {
            const req = { result: db, onsuccess: null, onerror: null, onupgradeneeded: null };
            later(() => { if (req.onsuccess) req.onsuccess(); });
            return req;
        }
    };
}

function boot() {
    const sent = [];
    const idb = fakeIndexedDB();
    const document = {
        body: { getAttribute: () => '' }, // no page: the shell does not start itself
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        dispatchEvent: () => {}
    };
    const window = { indexedDB: idb, location: { replace: (url) => sent.push('redirect ' + url) } };
    const ctx = {
        window, document, console, setTimeout, structuredClone,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        FormData: class { constructor() { this.parts = []; } append(k, v) { this.parts.push([k, v]); } },
        Blob: class { constructor(parts, opts) { this.parts = parts; this.type = opts && opts.type; } },
        fetch: (url, init) => {
            sent.push(init.headers['Idempotency-Key']);
            return Promise.resolve({ status: 200, headers: { get: () => null }, json: () => Promise.resolve({ ok: true }) });
        },
        navigator: { onLine: true }
    };
    vm.createContext(ctx);
    vm.runInContext(SOURCE, ctx);
    return { app: vm.runInContext('_Z77.app', ctx), sent, idb };
}

const entry = (key, created) => ({ key, screen: 'receipt', endpoint: 'receipts', fields: { amount: '1.00' }, file: null, created, state: 'waiting' });

console.log('S1. the queue belongs to the browser, an entry to its app user');
{
    const { app, sent, idb } = boot();
    app.queue.owner('7');
    await app.queue.add(entry('k-anna-1', 1));
    check('S1a add() stamps the entry with the app user signed in now', idb.data.queue.get('k-anna-1')?.appUser === '7', idb.data.queue.get('k-anna-1'));

    // Bert signs in on the same browser; Anna's capture is still waiting.
    app.queue.owner('9');
    await app.queue.add(entry('k-bert-1', 2));
    idb.data.queue.set('k-legacy', entry('k-legacy', 0)); // waiting from before the stamp existed
    await app.queue.flush();
    check('S1b flush() sends only the entries of the app user signed in now — Anna\'s capture does not go out under Bert',
        JSON.stringify(sent) === JSON.stringify(['k-bert-1']), sent);
    check('S1c … the foreign entries stay waiting (to be discarded), Bert\'s is gone (confirmed)',
        idb.data.queue.has('k-anna-1') && idb.data.queue.has('k-legacy') && !idb.data.queue.has('k-bert-1'), [...idb.data.queue.keys()]);
    check('S1d isMine(): Anna\'s and the unstamped entry are foreign to Bert',
        !app.queue.isMine(idb.data.queue.get('k-anna-1')) && !app.queue.isMine(idb.data.queue.get('k-legacy')));

    // Anna signs in again: her capture goes now.
    app.queue.owner('7');
    await app.queue.flush();
    check('S1e back under Anna, her capture goes out — the unstamped one still never', sent.at(-1) === 'k-anna-1' && !sent.includes('k-legacy'), sent);

    app.queue.owner(null);
    const before = sent.length;
    await app.queue.add(entry('k-none', 3));
    await app.queue.flush();
    check('S1f nobody signed in: nothing is sent', sent.length === before, sent);
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
