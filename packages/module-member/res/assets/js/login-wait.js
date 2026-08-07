/**
 * B8 waiting page: the device that typed the address asks every few seconds
 * whether the mail was confirmed somewhere else. The server answers only
 * about THIS browser's own request (the record id lives in its session), so
 * there is nothing to pass along here — and nothing to leak.
 *
 * 'session' → follow the redirect the server names (profile, or the TOTP
 * prompt when 2FA is on). 'dead' → the window closed or the link was used on
 * the other device; stop asking and say so.
 */
(function () {
    'use strict';

    var box = document.querySelector('[data-login-wait]');
    if (!box) {
        return;
    }

    var INTERVAL = 3000;      // spec default
    var LIMIT    = 15 * 60;   // give up with the link's own window
    var waited   = 0;
    var timer    = null;

    function stop(message) {
        window.clearInterval(timer);
        var note = box.querySelector('[data-login-wait-note]');
        if (note) {
            note.textContent = message;
        }
    }

    function ask() {
        waited += INTERVAL / 1000;
        if (waited > LIMIT) {
            stop('Die Anfrage ist abgelaufen. Bitte fordern Sie einen neuen Anmelde-Link an.');
            return;
        }

        window.fetch('/member/main/login/status', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (!data) {
                return; // a hiccup is not an answer — keep asking
            }
            if (data.state === 'session' && data.redirect) {
                window.clearInterval(timer);
                window.location.href = data.redirect;
            } else if (data.state === 'dead') {
                stop('Diese Anfrage gilt nicht mehr. Bitte fordern Sie einen neuen Anmelde-Link an.');
            }
        }).catch(function () {
            // Offline or blocked: stay quiet and try again on the next tick.
        });
    }

    timer = window.setInterval(ask, INTERVAL);
})();
