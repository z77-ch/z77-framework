/* z77 password strength meter — live UI hint.
 *
 * Mirrors PasswordPolicy (server) in spirit: graded by LENGTH + blocklist +
 * trivial patterns, NEVER by character-class composition. This is only a hint;
 * the server policy is the authority on save (sets the passwordWeak flag).
 *
 * Loaded lazily via the `load-script` command. Binds to a password input marked
 * `data-z77-password` and renders into the sibling `[data-z77-password-meter]`.
 * Reads the username field live so "password contains username" is reflected.
 */
(function () {
    'use strict';

    var COMMON = [
        'password', 'passwort', 'admin', 'administrator', 'root', 'login',
        'admin1234', 'guest1234', 'password1', 'passwort1', 'geheim',
        'qwertz', 'qwerty', 'letmein', 'welcome', 'changeme', 'default'
    ];

    var TIERS = [
        { cls: 'is-weak',   text: 'unsicher', width: '34%', color: 'var(--be-danger, #dc3545)' },
        { cls: 'is-ok',     text: 'ok',       width: '67%', color: 'var(--be-warning, #f59e0b)' },
        { cls: 'is-strong', text: 'stark',    width: '100%', color: 'var(--be-success, #16a34a)' }
    ];

    _Z77.scriptInit['password-meter'] = function (scope) {
        var input = scope.querySelector('[data-z77-password]');
        var host  = scope.querySelector('[data-z77-password-meter]');
        if (!input || !host) return;

        var userInput = scope.querySelector('[name="username"]');

        // Min length mirrors the server-side PasswordTier (data attribute set by
        // the template); fall back to 12 (the 'strong' default) when absent.
        var minLen = parseInt(input.getAttribute('data-z77-password-min'), 10) || 12;

        // Build the meter DOM once.
        host.innerHTML =
            '<div data-meter-track style="height:6px;border-radius:3px;background:rgba(148,163,184,.25);overflow:hidden">' +
              '<div data-meter-bar style="height:100%;width:0;border-radius:3px;transition:width .15s,background .15s"></div>' +
            '</div>' +
            '<small data-meter-label style="display:block;margin-top:.25rem;font-size:.72rem;color:var(--be-muted,#94a3b8)"></small>';

        var bar   = host.querySelector('[data-meter-bar]');
        var label = host.querySelector('[data-meter-label]');

        function isSequential(s) {
            if (s.length < 4) return false;
            var asc = true, desc = true;
            for (var i = 1; i < s.length; i++) {
                var d = s.charCodeAt(i) - s.charCodeAt(i - 1);
                if (d !== 1)  asc = false;
                if (d !== -1) desc = false;
            }
            return asc || desc;
        }

        function isWeak(pw) {
            var s = pw.toLowerCase().trim();
            if (pw.length < minLen) return true;
            if (/^[0-9]+$/.test(s)) return true;          // digits only
            if (/^(.)\1+$/.test(s)) return true;          // single repeated char
            if (isSequential(s)) return true;             // 12345678 / abcdefgh
            if (COMMON.indexOf(s) !== -1) return true;
            var u = userInput ? userInput.value.toLowerCase().trim() : '';
            if (u.length >= 3 && s.indexOf(u) !== -1) return true;
            return false;
        }

        function tier(pw) {
            if (!pw) return -1;
            if (isWeak(pw)) return 0;
            return pw.length >= 16 ? 2 : 1;
        }

        function render() {
            var t = tier(input.value);
            if (t === -1) { bar.style.width = '0'; label.textContent = ''; return; }
            var st = TIERS[t];
            bar.style.width = st.width;
            bar.style.background = st.color;
            label.textContent = 'Passwortstärke: ' + st.text;
        }

        input.addEventListener('input', render);
        if (userInput) userInput.addEventListener('input', render);
        render();
    };

    /* Full-page static include (e.g. the /setup page): there is no `load-script`
     * trigger, so initialise once on DOM ready. In the lazy/modal path the script
     * is appended AFTER DOMContentLoaded has fired, so this listener never runs
     * there — `load-script` calls the init explicitly with the popup scope. */
    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('[data-z77-password]')) {
            _Z77.scriptInit['password-meter'](document);
        }
    });
}());
