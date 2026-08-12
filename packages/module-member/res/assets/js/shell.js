/**
 * Shell header behaviour: the account panel, and the light/dark switch.
 *
 * Talks to the server through `_Z77.core.fetch.post()` — the framework's ONE
 * browser-side transport. It attaches the `X-CSRF-Token` header that
 * `AccessGuard` insists on for Fetch requests, and it dispatches the answer's
 * envelope (a refusal shows up in the flash channel by itself). Hand-rolling a
 * `fetch()` here is what produced FETCH-CSRF-001: the token sat in the body,
 * the guard never saw it, and the switch «only flickered».
 *
 * Two rules run through both halves.
 *
 * 1. The DOM is the truth, not a variable in here. The switch reads the mode
 *    off `<html data-theme>` — falling back to the system's answer when nobody
 *    decided — and writes the mode back onto the same attribute. Nothing is
 *    cached between clicks, so a second tab that changed the setting cannot
 *    leave this one arguing with the page it is standing on.
 *
 * 2. The display moves first, the server confirms after (the immediate-switch
 *    contract of this stack). If the write fails, the attribute goes back to
 *    what it was — the page never keeps a mode the account does not hold.
 */
(function () {
    'use strict';

    var root = document.documentElement;

    // ── light / dark ───────────────────────────────────────────────────────

    var themeButton = document.querySelector('[data-member-theme]');

    /** What the eye sees right now: the explicit choice, else the system's. */
    function currentTheme() {
        var explicit = root.getAttribute('data-theme');
        if (explicit === 'light' || explicit === 'dark') {
            return explicit;
        }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
    }

    /**
     * Back to the ground the page was painted on — including «no attribute at
     * all», which is NOT the same as 'light': absent means the system decides.
     */
    function revert(before) {
        if (before === null) {
            root.removeAttribute('data-theme');
        } else {
            applyTheme(before);
        }
    }

    if (themeButton) {
        themeButton.addEventListener('click', function () {
            var before = root.getAttribute('data-theme');
            var wanted = currentTheme() === 'dark' ? 'light' : 'dark';

            applyTheme(wanted);
            themeButton.disabled = true;

            // A refusal from the server arrives in the envelope's flash channel
            // and core.js paints it into `#flash-messages` on its own — this
            // handler only has to put the DISPLAY back where it was.
            _Z77.core.fetch.post(themeButton.dataset.themeUrl, { theme: wanted })
                .then(function (envelope) {
                    themeButton.disabled = false;

                    if (!envelope || envelope.status === 'error') {
                        revert(before);

                        // No envelope at all = the request never completed
                        // (core.js swallowed a network failure into its message
                        // channel, which this area does not render). Say it in
                        // the channel this area DOES have, rather than leaving
                        // a switch that springs back for no visible reason.
                        if (!envelope) {
                            _Z77.core.flash.show('error',
                                'Die Ansicht wurde nicht gespeichert — bitte laden Sie die Seite neu.');
                        }
                    }
                });
        });
    }

    // ── the two panels ─────────────────────────────────────────────────────
    //
    // TWO, not one (B10 v1.4.1): the four-square switcher carries the areas,
    // the avatar the account. Same behaviour, so one function serves both —
    // and opening either closes the other, because two open panels over one
    // header is a fight for the same space.

    var panels = [];

    function wirePanel(rootSel, triggerSel, panelSel, openClass) {
        var root = document.querySelector(rootSel);
        if (!root) { return; }

        var trigger = root.querySelector(triggerSel);
        var panel   = root.querySelector(panelSel);
        if (!trigger || !panel) { return; }

        var entry = {
            root: root,
            panel: panel,
            trigger: trigger,
            close: function () {
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                if (openClass) { root.classList.remove(openClass); }
            }
        };

        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            var wasOpen = !panel.hidden;
            panels.forEach(function (p) { p.close(); });
            if (!wasOpen) {
                panel.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                if (openClass) { root.classList.add(openClass); }
            }
        });

        panels.push(entry);
    }

    wirePanel('[data-member-areas]', '[data-member-areas-trigger]', '[data-member-areas-panel]', null);
    wirePanel('[data-member-menu]', '[data-member-menu-trigger]', '[data-member-menu-panel]', 'me-account__wrap--open');

    // ── dialogs ────────────────────────────────────────────────────────────
    //
    // A native `<dialog>`: the browser brings the backdrop, the focus trap, the
    // Escape key and the inertness of everything behind it. Rebuilding that by
    // hand is how modals end up focus-trapping nothing.
    //
    // The dialog is already IN the page (server-rendered, closed) — the account
    // form is two fields that this page has anyway, so there is nothing to
    // fetch. `showModal()` is what makes it modal; `show()` would leave the
    // page behind it live.
    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-dialog-open]');
        if (opener) {
            var dialog = document.getElementById(opener.getAttribute('data-dialog-open'));
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
            return;
        }

        var closer = event.target.closest('[data-dialog-close]');
        if (closer && closer.closest('dialog')) {
            // `close()`, not a submit: the form keeps whatever was typed, and
            // the next opening shows it again — which is what «Abbrechen»
            // means to the eye. A reload is what discards it.
            closer.closest('dialog').close();
        }
    });

    // Anywhere outside closes — a menu that survives the next click is a menu
    // in the way.
    document.addEventListener('click', function (event) {
        panels.forEach(function (p) {
            if (!p.panel.hidden && !p.root.contains(event.target)) { p.close(); }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }
        panels.forEach(function (p) {
            if (!p.panel.hidden) { p.close(); p.trigger.focus(); }
        });
    });
})();
