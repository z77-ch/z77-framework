/* `z77-split` — drag-resizable panes + narrow-screen detail overlay.
 *
 * Shared across viewAreas (ADR-018 revision 2026-08-08): the same file serves the backend
 * shell, a workspace inside it, and an embedded fragment in a frontend host. It replaces the
 * hard-wired resize block that used to live in the backend's shell.js, which could only ever
 * drive two fixed column variables ('--shell-c1' / '--shell-c3') and therefore could not
 * serve a second caller.
 *
 * Contract — every handle brings its own parameters, nothing is inferred from a module:
 *   [data-z77-split-root]                    the element the width variable is written to
 *   [data-z77-split="--var"]                 handle; names the custom property it resizes
 *   [data-z77-split-min] / [-max]            px bounds (defaults below)
 *   [data-z77-split-dir="1|-1"]              optional; only needed when the handle is not a
 *                                            sibling of the pane it resizes (the shell's
 *                                            resizer is a grid overlay, not a flex sibling)
 *   [data-z77-split-open="selector"]         opens the detail overlay of the matching root
 *   [data-z77-split-close]                   closes it (backdrop and close buttons)
 *
 * The width variable may be declared in ANY unit (the DMS uses rem, the shell px) — the drag
 * start is measured off the pane, not parsed off the token. See `currentPx`.
 *
 * No build step, no framework — same shape as panel-toggle.js.
 */
(function () {
    'use strict';

    var MIN_DEFAULT = 120;
    var MAX_DEFAULT = 720;

    /* A pane whose width the variable actually controls. The `--grow` pane never qualifies:
     * the CSS ignores its width variable, so it is neither a drag target nor a direction hint. */
    function sizablePane(el) {
        return el
            && el.classList
            && el.classList.contains('z77-split__pane')
            && !el.classList.contains('z77-split__pane--grow')
            ? el : null;
    }

    /* The pane a handle resizes — the sizable neighbour before it, else the one after it.
     * Same rule as `direction()`, so the two can never disagree. Null for a handle that is
     * not a sibling of its pane (the shell's grid overlay). */
    function targetPane(handle) {
        return sizablePane(handle.previousElementSibling)
            || sizablePane(handle.nextElementSibling);
    }

    /* Start width in PIXELS.
     *
     * `getComputedStyle` does NOT resolve a custom property — it hands back the raw token, so
     * a markup-declared `--z77-split-1: 16rem` parses to 16, not 256, and the first drag snaps
     * straight to the minimum. Only a value this file wrote itself is reliably px. For anything
     * else (rem in the markup, or the variable unset and the CSS default in force) the pane's
     * own box is the truth — measuring it needs no unit table and no assumption about which
     * default the stylesheet picked. */
    function currentPx(root, name, handle) {
        var raw = getComputedStyle(root).getPropertyValue(name).trim();
        if (/px$/.test(raw)) {
            return parseFloat(raw) || 0;
        }
        var pane = targetPane(handle);
        return pane ? pane.getBoundingClientRect().width : (parseFloat(raw) || 0);
    }

    /* Dragging right must GROW a pane that sits before the handle and SHRINK one after it.
     * When the handle sits between panes that is readable from the DOM. */
    function direction(handle) {
        var explicit = handle.getAttribute('data-z77-split-dir');
        if (explicit) {
            return parseInt(explicit, 10) === -1 ? -1 : 1;
        }
        return sizablePane(handle.previousElementSibling) ? 1 : -1;
    }

    function wireHandle(handle) {
        var root = handle.closest('[data-z77-split-root]');
        var name = handle.getAttribute('data-z77-split');
        if (!root || !name) { return; }

        var min = parseInt(handle.getAttribute('data-z77-split-min'), 10) || MIN_DEFAULT;
        var max = parseInt(handle.getAttribute('data-z77-split-max'), 10) || MAX_DEFAULT;
        var dir = direction(handle);
        var dragging = false, startX = 0, startWidth = 0;

        handle.addEventListener('pointerdown', function (e) {
            dragging   = true;
            startX     = e.clientX;
            startWidth = currentPx(root, name, handle);
            handle.classList.add('is-dragging');
            handle.setPointerCapture(e.pointerId);
            e.preventDefault();
        });

        handle.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            var width = Math.min(max, Math.max(min, startWidth + dir * (e.clientX - startX)));
            root.style.setProperty(name, width + 'px');
        });

        function end(e) {
            if (!dragging) { return; }
            dragging = false;
            handle.classList.remove('is-dragging');
            try { handle.releasePointerCapture(e.pointerId); } catch (ignored) {}
        }

        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
    }

    /* Detail overlay (narrow container). Delegated, so panes replaced by a fetch refresh
     * keep working without re-wiring. */
    function wireOverlay() {
        document.addEventListener('click', function (e) {
            if (!e.target || !e.target.closest) { return; }   // text/document targets

            var closer = e.target.closest('[data-z77-split-close]');
            if (closer) {
                var openRoot = closer.closest('[data-z77-split-root]');
                if (openRoot) { openRoot.removeAttribute('data-z77-split-detail'); }
                return;
            }

            var opener = e.target.closest('[data-z77-split-open]');
            if (!opener) { return; }
            var selector = opener.getAttribute('data-z77-split-open');
            var target   = selector ? document.querySelector(selector)
                                    : opener.closest('[data-z77-split-root]');
            if (target) { target.setAttribute('data-z77-split-detail', ''); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            document.querySelectorAll('[data-z77-split-detail]').forEach(function (root) {
                root.removeAttribute('data-z77-split-detail');
            });
        });
    }

    document.querySelectorAll('[data-z77-split]').forEach(wireHandle);
    wireOverlay();
})();
