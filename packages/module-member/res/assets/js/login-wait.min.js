/**
 * B8 waiting page: the device that typed the address asks every few seconds
 * whether the mail was confirmed somewhere else. The server answers only
 * about THIS browser's own request (the record id lives in its session), so
 * there is nothing to pass along here — and nothing to leak.
 *
 * 'session' → follow the redirect the server names (profile, or the TOTP
 * prompt when 2FA is on). 'elsewhere' → the link was opened in another tab
 * of THIS browser, which is signed in now; stop and show the «done» card,
 * whose «Weiter» takes its href from the answer (the other tab already shows
 * the landing — opening it twice is noise). 'dead', or the link's own
 * 15-minute window passed → stop and show the «dead» card.
 *
 * The script writes no text: every word stands in wartenAction.tpl.php, and
 * a state change swaps the WHOLE card (`hidden` on three bare containers),
 * never a line inside the waiting one.
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

    /** Stops asking and shows the card of the given state ('done' | 'dead'). */
    function show(state) {
        window.clearInterval(timer);
        var pending = box.querySelector('[data-login-wait-pending]');
        var card    = box.querySelector('[data-login-wait-' + state + ']');
        if (!card) {
            return; // an old template without the card: keep the waiting one
        }
        if (pending) {
            pending.hidden = true;
        }
        card.hidden = false;
    }

    function ask() {
        waited += INTERVAL / 1000;
        if (waited > LIMIT) {
            show('dead');
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
            } else if (data.state === 'elsewhere') {
                var link = box.querySelector('[data-login-wait-done-link]');
                if (link && data.link) {
                    link.href = data.link;
                }
                show('done');
            } else if (data.state === 'dead') {
                show('dead');
            }
        }).catch(function () {
            // Offline or blocked: stay quiet and try again on the next tick.
        });
    }

    timer = window.setInterval(ask, INTERVAL);
})();
