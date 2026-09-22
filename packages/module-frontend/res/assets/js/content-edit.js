/* z77 page editor — the website side (ADR-045 §4)
 *
 * Loaded ONLY for a user with at least `editor` on a full page
 * (AbstractFrontendController::html()). Visitors never get this file.
 *
 * Contract (markup from the page templates):
 *   [data-content-edit="<slot editor URL>"]   root element of one blueprint slot,
 *   [data-content-edit-label="<slot label>"]  printed by ContentView::editAttribute()
 *
 * What it does:
 *   - one «Bearbeiten» button per marked element, over its top-right corner. The
 *     buttons live in their own fixed layer, NOT inside the marked element: the
 *     page's layout, its positioning contexts and :first-child rules stay as the
 *     visitor gets them. They follow the element on scroll/resize; an element
 *     that is not rendered (display:none, zero size) gets no visible button.
 *   - the button opens a modal with an <iframe> of the slot editor (a bare
 *     backend page with its own CSS — the site's stylesheet cannot reach it).
 *     Close: the × button, Esc, or the editor's «Abbrechen» (message
 *     z77:content-edit-close) — never a backdrop click (POPUP-CLOSE-001).
 *   - after a save the editor sends z77:content-saved (core command
 *     `post-message`) and this page reloads — what you then see is what the
 *     server renders from the stored document.
 *   Messages are accepted only from this page's own origin AND from the open
 *   iframe's window.
 *
 * Styles are injected here (class prefix z77-ce-, reset with `all: unset`): the
 * project loads none of the framework's stylesheets, and a site's own button or
 * link rules must not reach these controls.
 */
(function () {
    'use strict';

    var MSG_SAVED = 'z77:content-saved';
    var MSG_CLOSE = 'z77:content-edit-close';

    var CSS = ''
        + '.z77-ce-layer{position:fixed;top:0;left:0;width:0;height:0;z-index:2147483000}'
        + '.z77-ce-btn{all:unset;box-sizing:border-box;position:fixed;display:inline-flex;align-items:center;gap:.35em;'
        + 'padding:.3em .7em;border-radius:999px;background:#1f2937;color:#fff;'
        + 'font:600 13px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;letter-spacing:0;'
        + 'box-shadow:0 2px 8px rgba(0,0,0,.3);cursor:pointer;white-space:nowrap;opacity:.88}'
        + '.z77-ce-btn:hover,.z77-ce-btn:focus-visible{opacity:1;background:#4f46e5}'
        + '.z77-ce-btn:focus-visible{outline:2px solid #fff;outline-offset:2px}'
        + '.z77-ce-btn[hidden]{display:none}'
        + '.z77-ce-frame{position:fixed;box-sizing:border-box;border:2px dashed #4f46e5;border-radius:4px;pointer-events:none}'
        + '.z77-ce-frame[hidden]{display:none}'
        + '.z77-ce-modal{all:unset;box-sizing:border-box;position:fixed;inset:0;z-index:2147483001;display:flex;'
        + 'align-items:center;justify-content:center;background:rgba(0,0,0,.45)}'
        + '.z77-ce-panel{position:relative;box-sizing:border-box;width:min(760px,96vw);height:min(calc(100vh - 6rem),900px);'
        + 'margin-top:2.5rem;background:#fff;border-radius:8px;box-shadow:0 20px 60px rgba(0,0,0,.35)}'
        + '.z77-ce-iframe{display:block;width:100%;height:100%;border:0;border-radius:8px;background:#fff}'
        // The close button sits ABOVE the panel's top-right corner, on the
        // backdrop: inside, it would cover the editor's own header.
        + '.z77-ce-close{all:unset;box-sizing:border-box;position:absolute;top:-2.4rem;right:0;width:2rem;height:2rem;'
        + 'display:flex;align-items:center;justify-content:center;border-radius:999px;cursor:pointer;'
        + 'font:400 22px/1 system-ui,sans-serif;color:#111827;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.3)}'
        + '.z77-ce-close:hover,.z77-ce-close:focus-visible{background:#e5e7eb}'
        + '.z77-ce-close:focus-visible{outline:2px solid #4f46e5;outline-offset:2px}';

    var layer = null;
    var frame = null;
    var items = [];          // {el, btn}
    var modal = null;        // {root, iframe, opener, overflow}
    var queued = false;

    function injectStyle() {
        var style = document.createElement('style');
        style.setAttribute('data-z77-ce', '');
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function overlaps(a, b) {
        return a.left < b.left + b.width && b.left < a.left + a.width
            && a.top < b.top + b.height && b.top < a.top + a.height;
    }

    /* Button over the element's top-right corner, kept inside the visible part
     * of the element while it is scrolled through. Two elements can share that
     * corner (a fixed badge over a section, a section inside a section): a
     * button that would cover one placed before it (DOM order) moves to its
     * left; with no room left, one row down. Returns the box it took, or null. */
    function place(item, taken) {
        var r = item.el.getBoundingClientRect();
        var visible = r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < window.innerHeight;
        item.btn.hidden = !visible;
        if (!visible) return null;

        var box = { width: item.btn.offsetWidth, height: item.btn.offsetHeight };
        var right = Math.min(r.right, document.documentElement.clientWidth) - 8;
        box.top  = Math.min(Math.max(r.top, 0) + 8, r.bottom - box.height - 8);
        box.left = Math.max(r.left + 8, right - box.width);

        for (var i = 0; i < taken.length; i++) {
            if (!overlaps(box, taken[i])) continue;
            box.left = taken[i].left - box.width - 6;
            if (box.left < Math.max(r.left, 0) + 8) {
                box.left = Math.max(r.left + 8, right - box.width);
                box.top  = taken[i].top + taken[i].height + 6;
            }
            i = -1;   // re-check against every box taken so far
        }

        item.btn.style.top  = Math.round(box.top) + 'px';
        item.btn.style.left = Math.round(box.left) + 'px';
        return box;
    }

    function placeAll() {
        queued = false;
        var taken = [];
        items.forEach(function (item) {
            var box = place(item, taken);
            if (box) taken.push(box);
        });
    }

    function schedule() {
        if (queued) return;
        queued = true;
        window.requestAnimationFrame(placeAll);
    }

    /* Dashed outline of the element while its button is hovered or focused. */
    function showFrame(el) {
        var r = el.getBoundingClientRect();
        frame.style.top    = Math.round(r.top) + 'px';
        frame.style.left   = Math.round(r.left) + 'px';
        frame.style.width  = Math.round(r.width) + 'px';
        frame.style.height = Math.round(r.height) + 'px';
        frame.hidden = false;
    }

    function hideFrame() {
        frame.hidden = true;
    }

    function open(url, label, opener) {
        if (modal) return;

        var root = document.createElement('div');
        root.className = 'z77-ce-modal';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Bearbeiten: ' + label);

        var panel = document.createElement('div');
        panel.className = 'z77-ce-panel';

        var iframe = document.createElement('iframe');
        iframe.className = 'z77-ce-iframe';
        iframe.title = 'Bearbeiten: ' + label;
        iframe.src = url;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'z77-ce-close';
        close.setAttribute('aria-label', 'Schliessen');
        close.textContent = '×';
        close.addEventListener('click', closeModal);

        // No close on a backdrop click (framework rule POPUP-CLOSE-001, fetch.md):
        // a stray click or a text selection dragged out of the iframe would
        // discard the edit. Closing is deliberate: ×, Esc or «Abbrechen».

        panel.appendChild(iframe);
        panel.appendChild(close);
        root.appendChild(panel);
        document.body.appendChild(root);

        modal = {
            root: root,
            iframe: iframe,
            opener: opener,
            overflow: document.documentElement.style.overflow
        };
        document.documentElement.style.overflow = 'hidden';
        hideFrame();
        iframe.focus();
    }

    function closeModal() {
        if (!modal) return;
        var opener = modal.opener;
        document.documentElement.style.overflow = modal.overflow;
        modal.root.parentNode.removeChild(modal.root);
        modal = null;
        if (opener) opener.focus();
    }

    function onMessage(e) {
        if (!modal || e.origin !== window.location.origin || e.source !== modal.iframe.contentWindow) return;
        var type = e.data && e.data.type;
        if (type === MSG_SAVED) {
            window.location.reload();
        } else if (type === MSG_CLOSE) {
            closeModal();
        }
    }

    function init() {
        var marked = document.querySelectorAll('[data-content-edit]');
        if (!marked.length) return;

        injectStyle();
        layer = document.createElement('div');
        layer.className = 'z77-ce-layer';
        frame = document.createElement('div');
        frame.className = 'z77-ce-frame';
        frame.hidden = true;
        layer.appendChild(frame);

        Array.prototype.forEach.call(marked, function (el) {
            var url   = el.getAttribute('data-content-edit');
            var label = el.getAttribute('data-content-edit-label') || '';
            if (!url) return;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'z77-ce-btn';
            btn.textContent = 'Bearbeiten';
            btn.setAttribute('aria-label', 'Bearbeiten: ' + label);
            btn.title = 'Bearbeiten: ' + label;
            btn.addEventListener('click', function () { open(url, label, btn); });
            btn.addEventListener('mouseenter', function () { showFrame(el); });
            btn.addEventListener('focus', function () { showFrame(el); });
            btn.addEventListener('mouseleave', hideFrame);
            btn.addEventListener('blur', hideFrame);

            layer.appendChild(btn);
            items.push({ el: el, btn: btn });
        });

        document.body.appendChild(layer);
        placeAll();

        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule);
        window.addEventListener('load', schedule);
        if (typeof ResizeObserver === 'function') {
            var ro = new ResizeObserver(schedule);
            items.forEach(function (item) { ro.observe(item.el); });
        }

        window.addEventListener('message', onMessage);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal) closeModal();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
