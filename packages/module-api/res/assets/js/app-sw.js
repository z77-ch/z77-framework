/**
 * The service worker of the app shell (plan A6 / T6, ADR-058) — served by AppController from /app/sw.js,
 * so its scope is /app/ and nothing else of the site.
 *
 *   the shell pages (/app/, /app/login)  network first; the last good answer is the offline copy
 *   the shell's assets (/assets/…)       cache first — their names carry the version (`_at-…`),
 *                                        an older version of the same file is dropped
 *   the manifest and the icons           network first, the copy without network
 *   /api/…                               never touched: the gateway answers or the queue waits
 *
 * The queue of submissions is NOT here: it lives in the page (`_Z77.app.queue`, IndexedDB) and is
 * sent when the app is open and online. Nothing cached here is a secret — the device cookie never
 * reaches a script, and responses of /api are not stored.
 */
// v2 (ADR-058): the pairing page is gone — a new name drops the v1 cache (and its /app/pair copy)
// on every installed phone at activation.
var CACHE = 'z77-app-v2';
var PAGES = ['/app/', '/app/login'];
var STATIC = /^\/(app\/manifest\.webmanifest|web-app-manifest-\d+x\d+\.png|apple-touch-icon\.png|favicon\.ico)$/;
var VERSIONED = /_at-[0-9a-z]+(\.min)?\.(js|css)$/;

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) {
            return k.indexOf('z77-app-') === 0 && k !== CACHE;
        }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

function pageKey(url) {
    return url.pathname === '/app' ? '/app/' : url.pathname;
}

/** Keep one version of a versioned asset: drop the cached siblings of the same base name. */
function dropOlder(cache, url) {
    if (!VERSIONED.test(url.pathname)) return Promise.resolve();
    var base = url.pathname.replace(VERSIONED, '');
    return cache.keys().then(function (requests) {
        return Promise.all(requests.filter(function (r) {
            var p = new URL(r.url).pathname;
            return p !== url.pathname && VERSIONED.test(p) && p.replace(VERSIONED, '') === base;
        }).map(function (r) { return cache.delete(r); }));
    });
}

function networkFirst(request, key) {
    return fetch(request).then(function (response) {
        if (response.ok) {
            var copy = response.clone();
            caches.open(CACHE).then(function (cache) { cache.put(key, copy); });
        }
        return response;
    }, function () {
        return caches.match(key).then(function (hit) { return hit || Response.error(); });
    });
}

function cacheFirst(request, url) {
    return caches.open(CACHE).then(function (cache) {
        return cache.match(request).then(function (hit) {
            if (hit) return hit;
            return fetch(request).then(function (response) {
                if (response.ok) {
                    var copy = response.clone();
                    dropOlder(cache, url).then(function () { cache.put(request, copy); });
                }
                return response;
            });
        });
    });
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') return;
    var url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.indexOf('/api/') === 0) return;

    if (request.mode === 'navigate') {
        if (PAGES.indexOf(pageKey(url)) !== -1) event.respondWith(networkFirst(request, pageKey(url)));
        return;
    }
    if (url.pathname.indexOf('/assets/') === 0) {
        event.respondWith(VERSIONED.test(url.pathname) ? cacheFirst(request, url) : networkFirst(request, request));
        return;
    }
    if (STATIC.test(url.pathname)) {
        event.respondWith(networkFirst(request, request));
    }
});
