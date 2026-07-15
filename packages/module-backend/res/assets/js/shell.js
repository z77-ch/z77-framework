/* Backend shell (3-column rebuild, Phase 1): drag-resizable columns 1 + 3 and the
 * mobile sandwich / preview drawers. The module switcher, env switcher and avatar
 * panel run on the shared panel-toggle.js (data-panel contract) — not here. */
(function () {
    'use strict';
    var shell = document.querySelector('[data-shell]');
    if (!shell) { return; }

    // ── Drag-resize columns ─────────────────────────────────────────────────────
    function makeResize(handle) {
        var side    = handle.getAttribute('data-shell-resize');          // 'l' | 'r'
        var varName = side === 'l' ? '--shell-c1' : '--shell-c3';
        var min     = side === 'l' ? 190 : 240;
        var max     = side === 'l' ? 460 : 560;
        var dir     = side === 'l' ? 1 : -1;                             // drag right grows col1, shrinks col3
        var dragging = false, startX = 0, startW = 0;

        handle.addEventListener('pointerdown', function (e) {
            dragging = true;
            startX   = e.clientX;
            startW   = parseInt(getComputedStyle(shell).getPropertyValue(varName), 10) || 0;
            handle.classList.add('is-dragging');
            handle.setPointerCapture(e.pointerId);
            e.preventDefault();
        });
        handle.addEventListener('pointermove', function (e) {
            if (!dragging) { return; }
            var w = Math.min(max, Math.max(min, startW + dir * (e.clientX - startX)));
            shell.style.setProperty(varName, w + 'px');
        });
        function end(e) {
            if (!dragging) { return; }
            dragging = false;
            handle.classList.remove('is-dragging');
            try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
        }
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
    }
    shell.querySelectorAll('[data-shell-resize]').forEach(makeResize);

    // ── Mobile drawers ──────────────────────────────────────────────────────────
    function closeDrawers() { shell.classList.remove('is-drawer-l', 'is-drawer-r'); }

    document.querySelectorAll('[data-shell-drawer]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var side = btn.getAttribute('data-shell-drawer');
            shell.classList.remove(side === 'l' ? 'is-drawer-r' : 'is-drawer-l');
            shell.classList.toggle(side === 'l' ? 'is-drawer-l' : 'is-drawer-r');
        });
    });
    shell.querySelectorAll('[data-shell-drawer-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            shell.classList.remove(btn.getAttribute('data-shell-drawer-close') === 'l' ? 'is-drawer-l' : 'is-drawer-r');
        });
    });

    var backdrop = shell.querySelector('[data-shell-backdrop]');
    if (backdrop) { backdrop.addEventListener('click', closeDrawers); }

    window.addEventListener('resize', function () {
        if (window.innerWidth > 767) { closeDrawers(); }
    });
})();
