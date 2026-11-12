/**
 * _Z77.app — the client of the app shell under /app (plan A6 / T6, ADR-057).
 *
 *   _Z77.app.store   IndexedDB: the last answers (`kv`) and the waiting submissions (`queue`)
 *   _Z77.app.api     the gateway's device channel: `X-Z77-App: 1` on every call, the
 *                    `Idempotency-Key` of a submission, ETag revalidation with an offline copy
 *   _Z77.app.queue   the offline queue: a submission is stored BEFORE it is sent and removed only
 *                    when the server confirmed it (E7); a resend carries the same key
 *   _Z77.app.shell   pairing, the device («me»), which screens to show, the banner, the worker
 *
 * Rule 7: nothing here decides — the gateway checks credential, scope, header and origin, the
 * targets check every value. Nothing stored here is a secret: the device credential is an HttpOnly
 * cookie on /api that no script can read; the store holds what the screens show and the
 * submissions waiting for the network. Every text from the server goes into the page through
 * `textContent` — never as HTML.
 */
var _Z77 = _Z77 || {};
_Z77.app = _Z77.app || {};

_Z77.app.store = (function () {
    var DB = 'z77-app';
    var VERSION = 1;
    var _db = null;

    function open() {
        if (_db) return _db;
        _db = new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            var req = window.indexedDB.open(DB, VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv');
                if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'key' });
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        _db.catch(function () { _db = null; });
        return _db;
    }

    function run(store, mode, fn) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(store, mode);
                var req = fn(tx.objectStore(store));
                var result;
                if (req) req.onsuccess = function () { result = req.result; };
                tx.oncomplete = function () { resolve(result); };
                tx.onerror = tx.onabort = function () { reject(tx.error); };
            });
        });
    }

    return {
        get: function (key) {
            return run('kv', 'readonly', function (s) { return s.get(key); }).catch(function () { return undefined; });
        },
        set: function (key, value) {
            return run('kv', 'readwrite', function (s) { return s.put(value, key); }).catch(function () {});
        },
        /** Rejects when the device has no IndexedDB — the caller then sends without the safety net. */
        put: function (entry) {
            return run('queue', 'readwrite', function (s) { return s.put(entry); });
        },
        remove: function (key) {
            return run('queue', 'readwrite', function (s) { return s.delete(key); }).catch(function () {});
        },
        all: function () {
            return run('queue', 'readonly', function (s) { return s.getAll(); })
                .then(function (list) { return (list || []).sort(function (a, b) { return a.created - b.created; }); })
                .catch(function () { return []; });
        }
    };
})();

_Z77.app.api = (function () {
    var BASE = '/api/v1/';

    /**
     * One call on the device channel. Resolves always (never rejects):
     * {status, ok, data, error: {code, message, fields?}, etag} — status 0 = no network.
     */
    function request(method, endpoint, opts) {
        opts = opts || {};
        var headers = { 'X-Z77-App': '1', 'Accept': 'application/json' };
        if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;
        if (opts.etag) headers['If-None-Match'] = opts.etag;
        var init = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store' };
        if (opts.body !== undefined) {
            if (opts.body instanceof FormData) {
                init.body = opts.body;
            } else {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(opts.body);
            }
        }
        return fetch(BASE + endpoint, init).then(function (res) {
            var etag = res.headers.get('ETag');
            if (res.status === 304) return { status: 304, ok: true, data: null, error: null, etag: etag };
            return res.json().catch(function () { return {}; }).then(function (json) {
                var ok = res.status >= 200 && res.status < 300;
                return {
                    status: res.status,
                    ok: ok,
                    data: ok ? json : null,
                    error: ok ? null : ((json && json.error) || { code: 'internal', message: '' }),
                    etag: etag
                };
            });
        }, function () {
            return { status: 0, ok: false, data: null, error: { code: 'offline', message: '' }, etag: null };
        });
    }

    /**
     * GET with the last answer kept on the device: revalidated by ETag (304 = the copy is
     * current), served from the copy without network (`offline: true`).
     */
    function cached(endpoint) {
        var key = 'get:' + endpoint;
        return _Z77.app.store.get(key).then(function (copy) {
            return request('GET', endpoint, { etag: copy && copy.etag }).then(function (r) {
                if (r.status === 304 && copy) return { status: 200, ok: true, data: copy.data };
                if (r.ok) {
                    _Z77.app.store.set(key, { etag: r.etag, data: r.data });
                    return r;
                }
                if (r.status === 0 && copy) return { status: 200, ok: true, data: copy.data, offline: true };
                return r;
            });
        });
    }

    /** A fresh idempotency key (UUID v4) — one per submission, kept for every resend. */
    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
        var b = window.crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    /** No network, rate limit, server trouble: worth sending again later, unchanged. */
    function isTransient(r) {
        return r.status === 0 || r.status === 429 || r.status >= 500;
    }

    return { request: request, cached: cached, uuid: uuid, isTransient: isTransient };
})();

_Z77.app.queue = (function () {
    var store = _Z77.app.store;
    var api = _Z77.app.api;
    var _busy = false;
    var _offline = false;

    function changed() {
        document.dispatchEvent(new CustomEvent('z77:app-queue'));
    }

    /**
     * Stores a submission before it is sent:
     * {key, screen, endpoint, fields, fileField, file: {bytes, type, name}|null,
     *  summary: {title, amount, text, date}, noun: [one, many], created, state, error}
     */
    function add(entry) {
        return store.put(entry).then(function () { changed(); return true; }, function () { return false; });
    }

    function remove(key) {
        return store.remove(key).then(changed);
    }

    /**
     * Sends one entry with its own key. Confirmed → removed. No network / 429 / 5xx → stays
     * waiting. 401 → stays (the shell re-pairs, the entry goes with the new pairing). Any other
     * refusal: `immediate` (the form is open, it shows the errors) removes it, otherwise it stays
     * as «refused» with the server's errors until the person edits or discards it.
     */
    function send(entry, immediate) {
        var body = new FormData();
        Object.keys(entry.fields || {}).forEach(function (name) { body.append(name, entry.fields[name]); });
        if (entry.file) {
            body.append(entry.fileField || 'file', new Blob([entry.file.bytes], { type: entry.file.type }), entry.file.name);
        }
        return api.request('POST', entry.endpoint, { body: body, idempotencyKey: entry.key }).then(function (r) {
            _offline = r.status === 0;
            if (r.ok) {
                return remove(entry.key).then(function () {
                    document.dispatchEvent(new CustomEvent('z77:app-sent', { detail: { entry: entry, data: r.data } }));
                    return r;
                });
            }
            if (api.isTransient(r) || r.status === 401) {
                changed();
                return r;
            }
            if (immediate) return remove(entry.key).then(function () { return r; });
            entry.state = 'refused';
            entry.error = r.error;
            return store.put(entry).catch(function () {}).then(function () { changed(); return r; });
        });
    }

    /** Sends every waiting entry, oldest first; stops at the first that cannot go now. */
    function flush() {
        if (_busy) return Promise.resolve();
        _busy = true;
        return store.all().then(function (entries) {
            var waiting = entries.filter(function (e) { return e.state !== 'refused'; });
            var step = function (i) {
                if (i >= waiting.length) return null;
                return send(waiting[i], false).then(function (r) {
                    if (r.status === 401) {
                        window.location.replace('/app/pair');
                        return null;
                    }
                    if (api.isTransient(r)) return null;
                    return step(i + 1);
                });
            };
            return step(0);
        }).then(function () { _busy = false; }, function () { _busy = false; });
    }

    return {
        add: add,
        remove: remove,
        send: send,
        flush: flush,
        all: store.all,
        isOffline: function () { return _offline; }
    };
})();

_Z77.app.shell = (function () {
    var store = _Z77.app.store;
    var api = _Z77.app.api;
    var queue = _Z77.app.queue;
    var _device = null;
    var _readyFns = [];

    function q(selector) { return document.querySelector(selector); }

    function text(el, value) { if (el) el.textContent = value; }

    /** A screen's script waits for the device: called at once when it is known already. */
    function ready(fn) {
        if (_device) fn(_device);
        else _readyFns.push(fn);
    }

    function notice(message) {
        var el = q('[data-app-notice]');
        text(el, message);
        if (el) el.hidden = message === '';
    }

    function registerWorker() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) return Promise.resolve(null);
        return navigator.serviceWorker.register('/app/sw.js', { scope: '/app/' }).catch(function () { return null; });
    }

    // ── start ──────────────────────────────────────────────────────────────

    function start() {
        registerWorker();
        api.request('GET', 'devices/me').then(function (r) {
            if (r.ok && r.data && r.data.device) {
                store.set('me', r.data.device);
                return r.data.device;
            }
            if (r.status === 401) {
                window.location.replace('/app/pair');
                return 'away';
            }
            if (r.status === 0) {
                return store.get('me').then(function (me) {
                    if (!me) notice('Kein Netz — die App war auf diesem Gerät noch nie verbunden.');
                    return me || null;
                });
            }
            notice('Der Server hat die App abgewiesen (' + r.status + ').');
            return null;
        }).then(function (device) {
            if (device === 'away') return;
            text(q('[data-app-loading]'), '');
            var loading = q('[data-app-loading]');
            if (loading) loading.hidden = true;
            if (!device) return;
            showDevice(device);
            if (!revealScreens(device.scopes || [])) {
                notice('Für dieses Gerät ist keine Funktion freigeschaltet. Im Backend unter «Geräte» ändern.');
            }
            _device = device;
            _readyFns.splice(0).forEach(function (fn) { fn(device); });
            renderQueue();
            queue.flush();
        });

        document.addEventListener('z77:app-queue', renderQueue);
        window.addEventListener('online', function () { queue.flush(); });
        window.setInterval(function () {
            if (document.visibilityState === 'visible') queue.flush();
        }, 30000);
    }

    function showDevice(device) {
        var parts = [device.label, device.owner].filter(function (p) { return p; });
        text(q('[data-app-device]'), parts.join(' · '));
    }

    /** Shows every screen whose scope the device holds; the bar takes the title of a single one. */
    function revealScreens(scopes) {
        var shown = [];
        document.querySelectorAll('[data-app-screen]').forEach(function (section) {
            section.hidden = scopes.indexOf(section.getAttribute('data-app-scope')) === -1;
            if (!section.hidden) shown.push(section);
        });
        if (shown.length === 1 && shown[0].getAttribute('data-app-title')) {
            text(q('[data-app-title]'), shown[0].getAttribute('data-app-title'));
        }
        return shown.length > 0;
    }

    // ── the queue on the start page ────────────────────────────────────────

    function renderQueue() {
        queue.all().then(function (entries) {
            var banner = q('[data-app-banner]');
            var list = q('[data-app-queue]');
            if (!banner || !list) return;
            var waiting = entries.filter(function (e) { return e.state !== 'refused'; });
            banner.hidden = waiting.length === 0;
            if (waiting.length > 0) {
                var noun = waiting[0].noun || ['Eintrag', 'Einträge'];
                var one = waiting.length === 1;
                var what = waiting.length + ' ' + (one ? noun[0] : noun[1]);
                text(q('[data-app-banner-text]'), queue.isOffline() || !navigator.onLine
                    ? 'Kein Netz — ' + what + (one ? ' wartet' : ' warten') + ' auf Netz'
                    : what + (one ? ' wird' : ' werden') + ' gesendet …');
            }
            while (list.firstChild) list.removeChild(list.firstChild);
            entries.forEach(function (entry) { list.appendChild(card(entry)); });
            list.hidden = entries.length === 0;
        });
    }

    function svgIcon(id, cls) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'za-icon ' + (cls || ''));
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS(ns, 'use');
        use.setAttribute('href', '#' + id);
        svg.appendChild(use);
        return svg;
    }

    function el(tag, cls, value) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (value !== undefined) node.textContent = value;
        return node;
    }

    function card(entry) {
        var refused = entry.state === 'refused';
        var s = entry.summary || {};
        var box = el('div', 'za-card ' + (refused ? 'za-card-refused' : 'za-card-wait'));
        var head = el('span', 'za-row');
        head.appendChild(el('strong', '', s.title || ''));
        head.appendChild(el('span', 'za-mono', s.amount || ''));
        box.appendChild(head);
        var sub = el('span', 'za-row za-muted');
        sub.appendChild(el('span', '', s.text || ''));
        sub.appendChild(el('span', 'za-mono', new Date(entry.created).toTimeString().slice(0, 5)));
        box.appendChild(sub);

        var status = el('span', 'za-card-status');
        status.appendChild(svgIcon(refused ? 'za-i-alert' : 'za-i-clock', 'za-icon-sm'));
        status.appendChild(el('span', '', refused ? refusal(entry.error) : 'wird gesendet, sobald Netz da ist'));
        box.appendChild(status);

        if (refused) {
            var actions = el('span', 'za-card-actions');
            var edit = el('button', 'za-link', 'Bearbeiten');
            edit.type = 'button';
            edit.addEventListener('click', function () {
                document.dispatchEvent(new CustomEvent('z77:app-edit', { detail: { entry: entry } }));
            });
            var drop = el('button', 'za-link', 'Verwerfen');
            drop.type = 'button';
            drop.addEventListener('click', function () {
                if (window.confirm('Diesen Eintrag verwerfen? Er wird nicht gesendet.')) queue.remove(entry.key);
            });
            actions.appendChild(edit);
            actions.appendChild(drop);
            box.appendChild(actions);
        }
        return box;
    }

    /** What a person reads for a refusal: the field messages of the target, else a sentence per code. */
    function refusal(error) {
        error = error || {};
        if (error.fields) {
            return Object.keys(error.fields).map(function (k) { return error.fields[k]; }).join(' ');
        }
        switch (error.code) {
            case 'forbidden': return 'Dieses Gerät darf das nicht (mehr). Im Backend unter «Geräte» prüfen.';
            case 'idempotency_conflict': return 'Doppelt gesendet mit anderem Inhalt. Bitte neu erfassen.';
            case 'bad_request': return 'Die Anfrage war unvollständig. Bitte nochmals senden.';
            default: return 'Der Server hat den Eintrag nicht angenommen.';
        }
    }

    // ── pairing ────────────────────────────────────────────────────────────

    function pair() {
        var worker = registerWorker();
        var form = q('[data-app-pair]');
        if (!form) return;
        var input = form.querySelector('input[name="code"]');
        var error = form.querySelector('[data-error-for="code"]');
        var button = form.querySelector('[data-app-pair-submit]');

        // The code is a one-time secret: out of the address bar (and its history) once read.
        if (window.location.search) window.history.replaceState(null, '', '/app/pair');

        function fail(message) {
            text(error, message);
            error.hidden = false;
            button.disabled = false;
            button.textContent = 'Koppeln';
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            error.hidden = true;
            var code = input.value.trim();
            if (code === '') {
                fail('Bitte den Code eingeben.');
                return;
            }
            button.disabled = true;
            button.textContent = 'Wird gekoppelt …';
            api.request('POST', 'devices/pair', { body: { code: code } }).then(function (r) {
                if (r.ok && r.data && r.data.device) {
                    store.set('me', r.data.device);
                    // Let the worker take over first, so the start page is kept for offline use.
                    var waited = Promise.race([
                        worker.then(function (reg) { return reg ? navigator.serviceWorker.ready : null; }),
                        new Promise(function (resolve) { window.setTimeout(resolve, 2500); })
                    ]);
                    waited.then(function () { window.location.replace('/app/'); });
                    return;
                }
                switch (r.status) {
                    case 0: fail('Kein Netz. Bitte nochmals versuchen, sobald das Handy online ist.'); break;
                    case 400: fail('Bitte den Code eingeben.'); break;
                    case 403: fail('Der Code ist ungültig, abgelaufen oder schon benutzt. Im Backend einen neuen Code erzeugen.'); break;
                    case 429: fail('Zu viele Versuche. Bitte in einer Stunde nochmals.'); break;
                    default: fail('Koppeln hat nicht geklappt (' + r.status + ').');
                }
            });
        });
    }

    var page = document.body ? document.body.getAttribute('data-app-page') : '';
    if (page === 'start') start();
    else if (page === 'pair') pair();

    return { ready: ready, notice: notice, refusal: refusal, svgIcon: svgIcon, el: el };
})();
