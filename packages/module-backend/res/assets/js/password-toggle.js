/* z77 show-password toggle — user-friendly reveal.
 *
 * Adds an accessible eye button inside every password field within `scope`,
 * switching the input between type=password and type=text. Pure UX, no server
 * contract. Binds to `input[type="password"]` directly — no markup marker
 * needed; the controller decides where it loads (login, setup, user edit).
 *
 * Two delivery paths (same as password-meter):
 *   - static page (login / setup): self-inits on DOMContentLoaded.
 *   - lazy modal (user edit): loaded via the `load-script` command with the
 *     popup body as scope; init is called explicitly after DOMContentLoaded.
 */
(function () {
    'use strict';

    var EYE =
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF =
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function attach(input) {
        if (input._z77ToggleBound) return;
        input._z77ToggleBound = true;

        // Wrap the input so the button can be absolutely positioned over it.
        var wrap = document.createElement('span');
        wrap.className = 'be-password';
        wrap.style.position = 'relative';
        wrap.style.display = 'block';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.style.paddingRight = '2.75rem';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'be-password__toggle';
        btn.setAttribute('aria-pressed', 'false');
        btn.setAttribute('aria-label', 'Passwort anzeigen');
        btn.style.cssText =
            'position:absolute;top:0;right:0;height:100%;width:2.75rem;' +
            'display:flex;align-items:center;justify-content:center;' +
            'padding:0;border:0;background:none;cursor:pointer;' +
            'color:var(--be-muted,#94a3b8);';
        btn.innerHTML = EYE;

        btn.addEventListener('click', function () {
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            btn.setAttribute('aria-label', reveal ? 'Passwort verbergen' : 'Passwort anzeigen');
            btn.innerHTML = reveal ? EYE_OFF : EYE;
            input.focus();
        });

        wrap.appendChild(btn);
    }

    _Z77.scriptInit['password-toggle'] = function (scope) {
        (scope || document).querySelectorAll('input[type="password"]').forEach(attach);
    };

    /* Full-page static include (login / setup): no `load-script` trigger, so
     * initialise once on DOM ready. In the lazy/modal path the script is appended
     * after DOMContentLoaded, so this listener never runs there — `load-script`
     * calls the init explicitly with the popup scope. */
    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('input[type="password"]')) {
            _Z77.scriptInit['password-toggle'](document);
        }
    });
}());
