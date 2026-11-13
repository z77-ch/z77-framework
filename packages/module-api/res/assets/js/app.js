/**
 * _Z77.app — the client of the app shell under /app (plan A6 / T6, ADR-057, ADR-058).
 *
 *   _Z77.app.store   IndexedDB: the last answers (`kv`) and the waiting submissions (`queue`)
 *   _Z77.app.api     the gateway's device channel: `X-Z77-App: 1` on every call, the
 *                    `Idempotency-Key` of a submission, ETag revalidation with an offline copy
 *   _Z77.app.queue   the offline queue: a submission is stored BEFORE it is sent and removed only
 *                    when the server confirmed it (E7); a resend carries the same key; an entry
 *                    goes out only under the app user that captured it (S1)
 *   _Z77.app.shell   the code sign-in, the device («me»), which screens to show, the banner,
 *                    the sign-out, the worker
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

    /**
     * An open that never answers (seen in Firefox: a damaged or locked profile store, a pending
     * `blocked`) must not hang the app — after OPEN_MS it counts as «no IndexedDB»: reads come back
     * empty, a submission is sent without the safety net. The next call tries again.
     */
    var OPEN_MS = 3000;

    function open() {
        if (_db) return _db;
        _db = new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            var timer = window.setTimeout(function () { reject(new Error('IndexedDB open timed out')); }, OPEN_MS);
            var req;
            try {
                req = window.indexedDB.open(DB, VERSION);
            } catch (e) {
                window.clearTimeout(timer);
                reject(e);
                return;
            }
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv');
                if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'key' });
            };
            req.onsuccess = function () { window.clearTimeout(timer); resolve(req.result); };
            req.onerror = function () { window.clearTimeout(timer); reject(req.error); };
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
        /** Forgets every kept answer and `me` — at sign-out; the queue is not touched. */
        clear: function () {
            return run('kv', 'readwrite', function (s) { return s.clear(); }).catch(function () {});
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
            // The access is gone (deactivated, signed out elsewhere, device revoked): whatever was
            // asked, the person signs in anew — never a screen working on against a 401. The
            // sign-in page's own calls answer 401 only as «not signed in yet».
            if (res.status === 401 && document.body && document.body.getAttribute('data-app-page') !== 'login') {
                window.location.replace('/app/login');
            }
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
    var _owner = null;

    function changed() {
        document.dispatchEvent(new CustomEvent('z77:app-queue'));
    }

    /**
     * The app user signed in now (`appUser` of devices/me, devices/verify), set by the shell. The
     * queue belongs to the browser, not to a person: an entry carries the app user it was
     * captured by and goes out only under that sign-in (security review S1) — after another
     * person signs in on this browser, the entries of before wait as «foreign» until discarded.
     * The server cannot tell (the capture would simply be booked to whoever sends it).
     */
    function owner(appUserId) {
        _owner = appUserId ? String(appUserId) : null;
    }

    /** Captured by the app user signed in now? An entry without a stamp is nobody's — foreign. */
    function isMine(entry) {
        return _owner !== null && entry.appUser === _owner;
    }

    /**
     * Stores a submission before it is sent, stamped with the app user signed in now:
     * {key, screen, endpoint, fields, fileField, file: {bytes, type, name}|null,
     *  summary: {title, amount, text, date}, noun: [one, many], created, state, error, appUser}
     */
    function add(entry) {
        entry.appUser = _owner;
        return store.put(entry).then(function () { changed(); return true; }, function () { return false; });
    }

    function remove(key) {
        return store.remove(key).then(changed);
    }

    /**
     * Sends one entry with its own key. Confirmed → removed. No network / 429 / 5xx → stays
     * waiting. 401 → stays (the shell signs in anew, the entry goes after it). Any other
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

    /**
     * Sends every waiting entry of the app user signed in now, oldest first; stops at the first
     * that cannot go now. Entries of another app user are never sent (S1).
     */
    function flush() {
        if (_busy) return Promise.resolve();
        _busy = true;
        return store.all().then(function (entries) {
            var waiting = entries.filter(function (e) { return e.state !== 'refused' && isMine(e); });
            var step = function (i) {
                if (i >= waiting.length) return null;
                return send(waiting[i], false).then(function (r) {
                    if (r.status === 401) {
                        window.location.replace('/app/login');
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
        owner: owner,
        isMine: isMine,
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

    /**
     * Asks the server who this device is now — at start AND every time the app comes back to the
     * front (owner 2026-11-13: a deactivated app user kept his tiles while the app stayed open).
     * 401 → the sign-in. No network → the last answer kept here, SAID so in the notice: the tiles
     * then show the last known state, and what is captured waits for the server, which decides.
     */
    function askMe() {
        return api.request('GET', 'devices/me').then(function (r) {
            if (r.ok && r.data && r.data.device) {
                store.set('me', r.data.device);
                notice('');
                return r.data.device;
            }
            if (r.status === 401) {
                window.location.replace('/app/login');
                return 'away';
            }
            if (r.status === 0) {
                return store.get('me').then(function (me) {
                    notice(me
                        ? 'Kein Netz — die App zeigt den letzten bekannten Stand.'
                        : 'Kein Netz — die App war auf diesem Gerät noch nie verbunden.');
                    return me || null;
                });
            }
            notice('Der Server hat die App abgewiesen (' + r.status + ').');
            return null;
        });
    }

    /** Back in front: the scopes may have changed or the access may be gone. */
    function recheck() {
        if (!_device) return;
        askMe().then(function (device) {
            if (!device || device === 'away') return;
            _device = device;
            showDevice(device);
            if (!revealScreens(device.scopes || [])) {
                notice('Für deinen App-Zugang ist keine Funktion freigeschaltet. Bitte beim Administrator nachfragen.');
            }
        });
    }

    /**
     * Asks the server BEFORE an action (owner 2026-11-13: a tile asks first, the camera opens
     * after): true when the device holds $scope NOW. The same question as at start and on resume
     * (`askMe()`), so the device, the screens and the notice follow the answer. No network → the
     * last known state decides (the notice says so; what is captured then waits for the server,
     * which checks again — Rule 7). 401 → the sign-in, false.
     */
    function allows(scope) {
        return askMe().then(function (device) {
            if (!device || device === 'away') return false;
            _device = device;
            showDevice(device);
            revealScreens(device.scopes || []);
            return (device.scopes || []).indexOf(scope) !== -1;
        });
    }

    function start() {
        registerWorker();
        askMe().then(function (device) {
            if (device === 'away') return;
            text(q('[data-app-loading]'), '');
            var loading = q('[data-app-loading]');
            if (loading) loading.hidden = true;
            if (!device) return;
            queue.owner(device.appUser);
            showDevice(device);
            if (!revealScreens(device.scopes || [])) {
                notice('Für deinen App-Zugang ist keine Funktion freigeschaltet. Bitte beim Administrator nachfragen.');
            }
            showAccount();
            _device = device;
            _readyFns.splice(0).forEach(function (fn) { fn(device); });
            renderQueue();
            queue.flush();
        });

        document.addEventListener('z77:app-queue', renderQueue);
        window.addEventListener('online', function () { recheck(); queue.flush(); });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') recheck();
        });
        // A page restored from the browser's back/forward cache runs no script anew.
        window.addEventListener('pageshow', function (event) { if (event.persisted) recheck(); });
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
            var waiting = entries.filter(function (e) { return e.state !== 'refused' && queue.isMine(e); });
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
        // Captured under another sign-in on this browser (S1): never sent, only discarded.
        var foreign = !queue.isMine(entry);
        var refused = entry.state === 'refused';
        var s = entry.summary || {};
        var box = el('div', 'za-card ' + (refused || foreign ? 'za-card-refused' : 'za-card-wait'));
        var head = el('span', 'za-row');
        head.appendChild(el('strong', '', s.title || ''));
        head.appendChild(el('span', 'za-mono', s.amount || ''));
        box.appendChild(head);
        var sub = el('span', 'za-row za-muted');
        sub.appendChild(el('span', '', s.text || ''));
        sub.appendChild(el('span', 'za-mono', new Date(entry.created).toTimeString().slice(0, 5)));
        box.appendChild(sub);

        var status = el('span', 'za-card-status');
        status.appendChild(svgIcon(refused || foreign ? 'za-i-alert' : 'za-i-clock', 'za-icon-sm'));
        status.appendChild(el('span', '', foreign ? 'Von einem anderen Zugang erfasst — verwerfen'
            : refused ? refusal(entry.error) : 'wird gesendet, sobald Netz da ist'));
        box.appendChild(status);

        // A refused entry can be corrected or discarded; a waiting one discarded (owner 2026-11-12) —
        // so the sign-out's «Erst senden oder verwerfen» always has its action, also offline.
        var actions = el('span', 'za-card-actions');
        if (refused && !foreign) {
            var edit = el('button', 'za-link', 'Bearbeiten');
            edit.type = 'button';
            edit.addEventListener('click', function () {
                document.dispatchEvent(new CustomEvent('z77:app-edit', { detail: { entry: entry } }));
            });
            actions.appendChild(edit);
        }
        var drop = el('button', 'za-link', 'Verwerfen');
        drop.type = 'button';
        drop.addEventListener('click', function () {
            var question = refused || foreign
                ? 'Diesen Eintrag verwerfen? Er wird nicht gesendet.'
                : 'Diesen wartenden Eintrag verwerfen? Er wird nicht mehr gesendet — was schon unterwegs ist, kann trotzdem ankommen.';
            if (window.confirm(question)) queue.remove(entry.key);
        });
        actions.appendChild(drop);
        box.appendChild(actions);
        return box;
    }

    /** What a person reads for a refusal: the field messages of the target, else a sentence per code. */
    function refusal(error) {
        error = error || {};
        if (error.fields) {
            return Object.keys(error.fields).map(function (k) { return error.fields[k]; }).join(' ');
        }
        switch (error.code) {
            case 'forbidden': return 'Dein App-Zugang darf das nicht (mehr). Bitte beim Administrator nachfragen.';
            case 'idempotency_conflict': return 'Doppelt gesendet mit anderem Inhalt. Bitte neu erfassen.';
            case 'bad_request': return 'Die Anfrage war unvollständig. Bitte nochmals senden.';
            default: return 'Der Server hat den Eintrag nicht angenommen.';
        }
    }

    // ── sign-out ───────────────────────────────────────────────────────────

    /**
     * «Auf diesem Gerät abmelden»: revokes this device on the server and forgets the kept answers.
     * Refused while the queue holds anything unsent — the queue belongs to the browser, and a
     * waiting entry must never go out later under another person's sign-in (ADR-058).
     */
    function showAccount() {
        var box = q('[data-app-account]');
        var button = q('[data-app-logout]');
        if (!box || !button) return;
        box.hidden = false;
        button.addEventListener('click', function () {
            queue.all().then(function (entries) {
                if (entries.length > 0) {
                    notice('Erst senden oder verwerfen: ' + entries.length
                        + (entries.length === 1 ? ' Eintrag ist' : ' Einträge sind') + ' noch nicht gesendet.');
                    return;
                }
                if (!window.confirm('Auf diesem Gerät abmelden? Danach braucht es einen neuen Code per E-Mail.')) return;
                button.disabled = true;
                api.request('POST', 'devices/logout', { idempotencyKey: api.uuid() }).then(function (r) {
                    if (r.ok || r.status === 401) {
                        Promise.race([
                            store.clear(),
                            new Promise(function (resolve) { window.setTimeout(resolve, 2500); })
                        ]).then(function () { window.location.replace('/app/login'); });
                        return;
                    }
                    button.disabled = false;
                    notice(r.status === 0 ? 'Kein Netz — Abmelden geht nur online.' : 'Abmelden hat nicht geklappt (' + r.status + ').');
                });
            });
        });
    }

    // ── sign-in ────────────────────────────────────────────────────────────

    /**
     * The code sign-in (ADR-058): the app e-mail → `devices/login` (the same answer for every
     * address), then the code from the mail → `devices/verify` (the device cookie). One generic
     * text for every refused code, no count of tries left.
     */
    function login() {
        var worker = registerWorker();
        var emailForm = q('[data-app-login-email]');
        var codeForm = q('[data-app-login-code]');
        if (!emailForm || !codeForm) return;
        var emailInput = emailForm.querySelector('input[name="email"]');
        var emailError = emailForm.querySelector('[data-error-for="email"]');
        var send = emailForm.querySelector('[data-app-login-send]');
        var codeInput = codeForm.querySelector('input[name="code"]');
        var codeError = codeForm.querySelector('[data-error-for="code"]');
        var verify = codeForm.querySelector('[data-app-login-verify]');
        var again = codeForm.querySelector('[data-app-login-again]');

        function show(el, message) {
            text(el, message);
            el.hidden = message === '';
        }

        function busy(button, on, label) {
            button.disabled = on;
            button.textContent = label;
        }

        emailForm.addEventListener('submit', function (event) {
            event.preventDefault();
            show(emailError, '');
            var email = emailInput.value.trim();
            if (email === '') {
                show(emailError, 'Bitte die App-E-Mail eingeben.');
                return;
            }
            busy(send, true, 'Wird angefordert …');
            api.request('POST', 'devices/login', { body: { email: email } }).then(function (r) {
                busy(send, false, 'Code anfordern');
                if (r.status === 202) {
                    emailForm.hidden = true;
                    codeForm.hidden = false;
                    codeInput.value = '';
                    show(codeError, '');
                    codeInput.focus();
                    return;
                }
                switch (r.status) {
                    case 0: show(emailError, 'Kein Netz. Bitte nochmals versuchen, sobald das Handy online ist.'); break;
                    case 400: show(emailError, 'Bitte die App-E-Mail eingeben.'); break;
                    case 429: show(emailError, 'Zu viele Anfragen aus diesem Netz. Bitte in einer Stunde nochmals.'); break;
                    default: show(emailError, 'Das hat nicht geklappt (' + r.status + ').');
                }
            });
        });

        codeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            show(codeError, '');
            var code = codeInput.value.replace(/\s+/g, '');
            if (!/^[0-9]{6}$/.test(code)) {
                show(codeError, 'Bitte den 6-stelligen Code aus der E-Mail eingeben.');
                return;
            }
            busy(verify, true, 'Wird angemeldet …');
            api.request('POST', 'devices/verify', { body: { code: code } }).then(function (r) {
                if (r.ok && r.data && r.data.device) {
                    // Another person may have been signed in here: the answers kept for the last
                    // one (templates, `me`) go first (S1); the queue stays — foreign entries wait
                    // there to be discarded, never sent.
                    var saved = store.clear().then(function () { return store.set('me', r.data.device); });
                    // Let the worker take over first, so the start page is kept for offline use.
                    // The cap covers the store too: the device cookie is set, nothing here may
                    // keep the person on this page (Firefox hung in the store, 2026-11-12).
                    Promise.race([
                        saved.then(function () {
                            return worker.then(function (reg) { return reg ? navigator.serviceWorker.ready : null; });
                        }),
                        new Promise(function (resolve) { window.setTimeout(resolve, 2500); })
                    ]).then(function () { window.location.replace('/app/'); });
                    return;
                }
                busy(verify, false, 'Anmelden');
                switch (r.status) {
                    case 0: show(codeError, 'Kein Netz. Bitte nochmals versuchen, sobald das Handy online ist.'); break;
                    case 429: show(codeError, 'Zu viele Versuche aus diesem Netz. Bitte in einer Stunde nochmals.'); break;
                    default: show(codeError, 'Code falsch oder abgelaufen — neuen Code anfordern.');
                }
            });
        });

        again.addEventListener('click', function () {
            codeForm.hidden = true;
            emailForm.hidden = false;
            show(codeError, '');
            emailInput.focus();
        });
    }

    var page = document.body ? document.body.getAttribute('data-app-page') : '';
    if (page === 'start') start();
    else if (page === 'login') login();

    return { ready: ready, allows: allows, notice: notice, refusal: refusal, svgIcon: svgIcon, el: el };
})();
