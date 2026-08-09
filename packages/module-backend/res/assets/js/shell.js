/* Backend shell: the mobile sandwich drawer for column 1.
 *
 * Column resizing is NOT here any more — it moved to the shared `split.js`
 * (kernel/shared, `z77-split` handle contract), so the shell and every workspace inside it
 * use one drag mechanism instead of two. The old block could only drive two hard-coded
 * variables and could not serve a second caller.
 *
 * Column 3 (preview) was removed with the same change: it had never been switched on, and a
 * detail pane now belongs to the workspace. Only the LEFT drawer remains, so this file no
 * longer carries a generic side parameter.
 *
 * The module switcher, env switcher and avatar panel run on the shared panel-toggle.js
 * (data-panel contract) — not here.
 */
(function () {
    'use strict';
    var shell = document.querySelector('[data-shell]');
    if (!shell) { return; }

    function closeDrawer() { shell.classList.remove('is-drawer-l'); }

    document.querySelectorAll('[data-shell-drawer]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            shell.classList.toggle('is-drawer-l');
        });
    });

    shell.querySelectorAll('[data-shell-drawer-close]').forEach(function (btn) {
        btn.addEventListener('click', closeDrawer);
    });

    var backdrop = shell.querySelector('[data-shell-backdrop]');
    if (backdrop) { backdrop.addEventListener('click', closeDrawer); }

    window.addEventListener('resize', function () {
        if (window.innerWidth > 767) { closeDrawer(); }
    });
})();
