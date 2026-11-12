/**
 * _Z77.app.receipt — the receipt screen of the app shell (board «z77 App» 2026-11-12; E2, E5–E7,
 * E10). Tiles → capture → confirmation on one page (`res/view/templates/App/receipt.tpl.php`).
 *
 *   tiles     the active receipt templates (`GET receipt-templates`) + «Ohne Vorlage»; a tap
 *             opens the camera at once (a file input clicked inside the tap)
 *   capture   the photo, scaled on the phone to at most 1600 px as JPEG (plan B3 — no HEIC on the
 *             wire, T2), Betrag, Datum (today), Text, «Bezahlt mit» (`GET payment-methods`)
 *   send      through `_Z77.app.queue`: stored first, sent with its own Idempotency-Key, removed
 *             when the server confirms — no network = it waits and goes later (E7)
 *
 * Rule 7: the checks here are hints; `POST /api/v1/receipts` checks every value again and its
 * German field messages are what the form shows. No account on the phone (E2): the template is a
 * code, the payment method is a code. Server text goes into the page as textContent only.
 */
var _Z77 = _Z77 || {};
_Z77.app = _Z77.app || {};

_Z77.app.receipt = (function () {
    var root = document.querySelector('[data-app-screen="receipt"]');
    if (!root || !_Z77.app.shell) return {};

    var api = _Z77.app.api;
    var queue = _Z77.app.queue;
    var store = _Z77.app.store;
    var shell = _Z77.app.shell;

    var MAX_SIDE = 1600;
    var QUALITY = 0.85;
    var NOUN = ['Beleg', 'Belege'];
    var DEFAULT_ICON = 'receipt';
    var KEY_METHOD = 'receipt:payment_method';
    var KEY_SENT = 'receipt:sent';

    var state = { templates: [], template: null, photo: null, editing: null };

    function $(selector) { return root.querySelector(selector); }

    var views = root.querySelectorAll('[data-receipt-view]');
    var tiles = $('[data-receipt-tiles]');
    var form = $('[data-receipt-view="capture"]');
    var photoInput = $('[data-receipt-photo-input]');
    var preview = $('[data-receipt-preview]');
    var amount = $('[data-receipt-amount]');
    var date = $('[data-receipt-date]');
    var text = $('[data-receipt-text]');
    var submit = $('[data-receipt-submit]');

    function show(view) {
        views.forEach(function (v) { v.hidden = v.getAttribute('data-receipt-view') !== view; });
        window.scrollTo(0, 0);
    }

    function iconKey(key) {
        return key && document.getElementById('receipt-icon-' + key) ? key : DEFAULT_ICON;
    }

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    function swissDate(iso) {
        var p = String(iso || '').split('-');
        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : '';
    }

    // ── tiles ──────────────────────────────────────────────────────────────

    function renderTiles(templates) {
        state.templates = templates;
        tiles.querySelectorAll('[data-receipt-generated]').forEach(function (n) { n.remove(); });
        var fallback = tiles.querySelector('[data-receipt-template=""]');
        templates.forEach(function (t) {
            var button = shell.el('button', 'za-tile');
            button.type = 'button';
            button.setAttribute('data-receipt-template', t.code);
            button.setAttribute('data-receipt-generated', '');
            var badge = shell.el('span', 'za-tile-icon');
            badge.appendChild(shell.svgIcon('receipt-icon-' + iconKey(t.icon)));
            button.appendChild(badge);
            button.appendChild(shell.el('span', '', t.label));
            tiles.insertBefore(button, fallback);
        });
    }

    function tileNotice(message) {
        var n = $('[data-receipt-tiles-notice]');
        n.textContent = message;
        n.hidden = message === '';
    }

    tiles.addEventListener('click', function (event) {
        var button = event.target.closest('[data-receipt-template]');
        if (!button) return;
        var code = button.getAttribute('data-receipt-template');
        var template = null;
        state.templates.forEach(function (t) { if (t.code === code) template = t; });
        choose(template);
        show('capture');
        if (!state.photo) photoInput.click(); // inside the tap: the camera may open
        else amount.focus();
    });

    function choose(template) {
        state.template = template;
        $('[data-receipt-template-input]').value = template ? template.code : '';
        $('[data-receipt-chip-label]').textContent = template ? template.label : 'Ohne Vorlage';
        $('[data-receipt-chip-icon]').setAttribute('href', '#receipt-icon-' + iconKey(template && template.icon));
        $('[data-receipt-no-template]').hidden = template !== null;
    }

    // ── «Bezahlt mit» ──────────────────────────────────────────────────────

    function renderMethods(methods, preferred) {
        var box = $('[data-receipt-payment-options]');
        while (box.firstChild) box.removeChild(box.firstChild);
        methods.forEach(function (m) {
            var option = shell.el('label', 'za-choice-option');
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'payment_method';
            radio.value = m.code;
            radio.checked = m.code === preferred;
            option.appendChild(radio);
            option.appendChild(shell.el('span', '', m.label));
            box.appendChild(option);
        });
        $('[data-receipt-payment]').hidden = methods.length === 0;
    }

    function selectMethod(code) {
        form.querySelectorAll('input[name="payment_method"]').forEach(function (r) { r.checked = r.value === code; });
    }

    function chosenMethod() {
        var picked = form.querySelector('input[name="payment_method"]:checked');
        return picked ? picked.value : '';
    }

    // ── photo ──────────────────────────────────────────────────────────────

    /** The photo as JPEG, longest side ≤ MAX_SIDE, drawn into the preview canvas (no blob: URL — CSP). */
    function scale(file) {
        var decode = window.createImageBitmap
            ? window.createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return window.createImageBitmap(file); })
            : Promise.reject(new Error('createImageBitmap unavailable'));
        return decode.then(function (bitmap) {
            var factor = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
            preview.width = Math.max(1, Math.round(bitmap.width * factor));
            preview.height = Math.max(1, Math.round(bitmap.height * factor));
            preview.getContext('2d').drawImage(bitmap, 0, 0, preview.width, preview.height);
            if (bitmap.close) bitmap.close();
            return new Promise(function (resolve, reject) {
                preview.toBlob(function (blob) { blob ? resolve(blob) : reject(new Error('toBlob failed')); }, 'image/jpeg', QUALITY);
            });
        });
    }

    function showPhoto(has) {
        preview.hidden = !has;
        $('[data-receipt-photo-empty]').hidden = has;
        $('[data-receipt-photo-again]').hidden = !has;
    }

    photoInput.addEventListener('change', function () {
        var file = photoInput.files && photoInput.files[0];
        if (!file) return;
        fieldError('photo', '');
        scale(file).then(function (blob) {
            state.photo = blob;
            showPhoto(true);
            amount.focus();
        }, function () {
            state.photo = null;
            showPhoto(false);
            fieldError('photo', 'Dieses Bild kann das Handy nicht lesen. Bitte nochmals aufnehmen.');
        });
        photoInput.value = ''; // the same picture may be chosen again
    });

    // ── form ───────────────────────────────────────────────────────────────

    function fieldError(field, message) {
        var target = form.querySelector('[data-error-for="' + field + '"]') || form.querySelector('[data-error-for="_"]');
        target.textContent = message;
        target.hidden = message === '';
    }

    function clearErrors() {
        form.querySelectorAll('[data-error-for]').forEach(function (n) { n.textContent = ''; n.hidden = true; });
    }

    function showErrors(error) {
        if (error && error.fields) {
            Object.keys(error.fields).forEach(function (field) { fieldError(field, error.fields[field]); });
            return;
        }
        fieldError('_', shell.refusal(error));
    }

    function reset() {
        form.reset();
        clearErrors();
        state.photo = null;
        state.editing = null;
        showPhoto(false);
        date.value = today();
        date.max = today();
        store.get(KEY_METHOD).then(function (code) { if (code) selectMethod(code); });
    }

    $('[data-receipt-back]').addEventListener('click', function () { reset(); show('tiles'); });
    $('[data-receipt-change]').addEventListener('click', function () { show('tiles'); });
    $('[data-receipt-next]').addEventListener('click', function () { reset(); show('tiles'); });
    $('[data-receipt-finish]').addEventListener('click', function () { reset(); show('tiles'); });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearErrors();
        var missing = false;
        if (!state.photo) { fieldError('photo', 'Das Foto fehlt.'); missing = true; }
        if (amount.value.trim() === '') { fieldError('amount', 'Der Betrag fehlt.'); missing = true; }
        if (missing) return;

        submit.disabled = true;
        submit.textContent = 'Wird gesendet …';
        var method = chosenMethod();
        if (method) store.set(KEY_METHOD, method);
        var previous = state.editing;

        state.photo.arrayBuffer().then(function (bytes) {
            var entry = {
                key: api.uuid(),
                screen: 'receipt',
                endpoint: 'receipts',
                fields: {
                    amount: amount.value.trim(),
                    date: date.value,
                    text: text.value.trim(),
                    template: state.template ? state.template.code : '',
                    payment_method: method
                },
                fileField: 'photo',
                file: { bytes: bytes, type: 'image/jpeg', name: 'beleg.jpg' },
                summary: {
                    title: state.template ? state.template.label : 'Ohne Vorlage',
                    amount: amount.value.trim(),
                    text: text.value.trim(),
                    date: date.value
                },
                noun: NOUN,
                created: Date.now(),
                state: 'waiting'
            };
            return queue.add(entry).then(function (stored) {
                return queue.send(entry, true).then(function (r) { return { r: r, entry: entry, stored: stored }; });
            });
        }).then(function (done) {
            var r = done.r;
            if (r.ok || api.isTransient(r)) {
                if (previous) queue.remove(previous.key);
            }
            if (r.ok) {
                confirmed(done.entry.summary, r.data, false);
            } else if (api.isTransient(r) && done.stored) {
                confirmed(done.entry.summary, null, true);
            } else if (r.status === 401) {
                window.location.replace('/app/login');
            } else if (api.isTransient(r)) {
                fieldError('_', 'Kein Netz, und dieses Gerät kann nichts zwischenspeichern. Bitte später senden.');
            } else {
                showErrors(r.error);
            }
        }).catch(function () {
            fieldError('_', 'Der Beleg konnte nicht vorbereitet werden. Bitte nochmals senden.');
        }).then(function () {
            submit.disabled = false;
            submit.textContent = 'Senden';
        });
    });

    function confirmed(summary, data, waiting) {
        var receipt = data && data.receipt ? data.receipt : null;
        $('[data-receipt-done-title]').textContent = waiting ? 'Beleg gespeichert' : (data && data.message) || 'Beleg erfasst';
        $('[data-receipt-done-label]').textContent = summary.title;
        $('[data-receipt-done-amount]').textContent = receipt ? receipt.amount : summary.amount;
        $('[data-receipt-done-text]').textContent = receipt ? receipt.text : summary.text;
        $('[data-receipt-done-date]').textContent = swissDate(receipt ? receipt.date : summary.date);
        $('[data-receipt-done-note]').textContent = waiting
            ? 'Kein Netz — wird gesendet, sobald Netz da ist.'
            : 'Liegt im Belegjournal. Verbucht wird Ende Monat im Backend.';
        $('[data-receipt-done-mark]').classList.toggle('za-done-wait', waiting);
        $('[data-receipt-done-mark] use').setAttribute('href', waiting ? '#za-i-clock' : '#za-i-check');
        reset();
        show('done');
    }

    // ── «Heute gesendet» ───────────────────────────────────────────────────

    function recordSent(summary, data) {
        var receipt = data && data.receipt ? data.receipt : null;
        store.get(KEY_SENT).then(function (sent) {
            var day = today();
            var items = sent && sent.day === day ? sent.items : [];
            var now = new Date();
            items.push({
                time: ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2),
                title: summary.title,
                amount: receipt ? receipt.amount : summary.amount
            });
            return store.set(KEY_SENT, { day: day, items: items });
        }).then(renderSent);
    }

    function renderSent() {
        store.get(KEY_SENT).then(function (sent) {
            var items = sent && sent.day === today() ? sent.items : [];
            var box = $('[data-receipt-sent]');
            var list = $('[data-receipt-sent-list]');
            while (list.firstChild) list.removeChild(list.firstChild);
            items.forEach(function (item) {
                var li = shell.el('li');
                li.appendChild(shell.el('span', '', item.time + ' ' + item.title));
                li.appendChild(shell.el('span', 'za-mono', item.amount));
                list.appendChild(li);
            });
            $('[data-receipt-sent-count]').textContent = String(items.length);
            box.hidden = items.length === 0;
        });
    }

    document.addEventListener('z77:app-sent', function (event) {
        var entry = event.detail && event.detail.entry;
        if (entry && entry.screen === 'receipt') recordSent(entry.summary, event.detail.data);
    });

    // ── a refused entry from the queue, opened for correction ──────────────

    document.addEventListener('z77:app-edit', function (event) {
        var entry = event.detail && event.detail.entry;
        if (!entry || entry.screen !== 'receipt') return;
        reset();
        state.editing = entry;
        var template = null;
        state.templates.forEach(function (t) { if (t.code === entry.fields.template) template = t; });
        choose(template);
        amount.value = entry.fields.amount || '';
        date.value = entry.fields.date || today();
        text.value = entry.fields.text || '';
        selectMethod(entry.fields.payment_method || '');
        show('capture');
        showErrors(entry.error);
        if (entry.file) {
            scale(new Blob([entry.file.bytes], { type: entry.file.type })).then(function (blob) {
                state.photo = blob;
                showPhoto(true);
            }, function () { showPhoto(false); });
        }
    });

    // ── start ──────────────────────────────────────────────────────────────

    shell.ready(function () {
        reset();
        choose(null);
        renderSent();
        api.cached('receipt-templates').then(function (r) {
            if (r.ok) {
                renderTiles(r.data.templates || []);
                tileNotice('');
            } else if (r.status === 403) {
                tileNotice('Dein App-Zugang darf keine Belege erfassen. Bitte beim Administrator nachfragen.');
            } else {
                tileNotice('Die Vorlagen sind nicht geladen — «Ohne Vorlage» geht immer.');
            }
        });
        Promise.all([api.cached('payment-methods'), store.get(KEY_METHOD)]).then(function (both) {
            var r = both[0];
            renderMethods(r.ok ? (r.data.payment_methods || []) : [], both[1] || '');
        });
    });

    return {};
})();
