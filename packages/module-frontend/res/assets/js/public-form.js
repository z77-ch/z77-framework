/**
 * Public form — per-field SERVER validation on blur (JS is transport only).
 *
 * On blur (typed fields) / change (radios, checkboxes) the field is POSTed to
 * the form's data-check-url; the server runs the same validator used on submit
 * and answers {valid, message}. This script only mirrors the verdict into the
 * DOM: error class on the row, hint text (aria-live region announces it) and
 * aria-invalid on the field — no validation logic lives client-side. Honeypots
 * and the hidden CSRF field are never checked. Without JS the form still works
 * fully (submit → server re-render with errors), which is why this stays within
 * the JS budget (conventions.md Rule 7).
 *
 * Markup contract (see partials/publicForm.tpl.php) — a project template may
 * use any classes as long as it keeps these attributes:
 *   data-public-form / data-check-url on the form, data-validate on inputs,
 *   data-form-row on the row container, data-hint-for="{field}" on the hint.
 * The row's error class defaults to 'fe-form__row--error' and can be set per
 * form via data-error-class.
 *
 * On submit the button is disabled (double-click = two mails); if the server
 * re-renders with errors, the fresh page has an enabled button again.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('[data-public-form]');

        Array.prototype.forEach.call(forms, function (form) {
            var url  = form.getAttribute('data-check-url');
            var csrf = form.querySelector('input[name="csrf_token"]');
            if (!url || !csrf) {
                return;
            }
            var errorClass = form.getAttribute('data-error-class') || 'fe-form__row--error';

            function fieldValue(el) {
                if (el.type === 'checkbox') { return el.checked ? '1' : ''; }
                if (el.type === 'radio') {
                    var checked = form.querySelector('input[name="' + el.name + '"]:checked');
                    return checked ? checked.value : '';
                }
                return el.value;
            }

            function apply(name, data) {
                var hint = form.querySelector('[data-hint-for="' + name + '"]');
                if (hint) { hint.textContent = data.valid ? '' : (data.message || ''); }

                // aria-invalid on every input of the field (radio group = all radios)
                var fields = form.querySelectorAll('[name="' + name + '"]');
                Array.prototype.forEach.call(fields, function (f) {
                    if (data.valid) { f.removeAttribute('aria-invalid'); }
                    else { f.setAttribute('aria-invalid', 'true'); }
                });

                var el  = form.querySelector('[name="' + name + '"]');
                var row = el ? el.closest('[data-form-row]') : null;
                if (row) { row.classList.toggle(errorClass, !data.valid); }
            }

            function check(el) {
                var body = new FormData();
                body.append('field', el.name);
                body.append('value', fieldValue(el));
                body.append('csrf_token', csrf.value);

                // X-CSRF-Token header: AccessGuard validates fetch POSTs against
                // the header, not the body (CONTACT-CHECK-001) — the body copy is
                // kept for symmetry with the no-JS submit path.
                fetch(url, {
                    method:  'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token':     csrf.value
                    },
                    body:    body
                })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (data) { apply(el.name, data); }
                    })
                    .catch(function () { /* transport error → keep current state */ });
            }

            // blur for typed fields (capture: blur does not bubble) …
            form.addEventListener('blur', function (event) {
                var el = event.target;
                if (el.matches && el.matches('input[data-validate]:not([type="radio"]):not([type="checkbox"]), textarea[data-validate]')) {
                    check(el);
                }
            }, true);

            // … change for radios + checkboxes (blur is meaningless there)
            form.addEventListener('change', function (event) {
                var el = event.target;
                if (el.matches && el.matches('input[type="radio"][data-validate], input[type="checkbox"][data-validate]')) {
                    check(el);
                }
            });

            // double-submit guard: disable the button once the submit is on its
            // way (the button carries no name/value, so no form data is lost)
            form.addEventListener('submit', function () {
                var submit = form.querySelector('button[type="submit"]');
                if (submit) { submit.disabled = true; }
            });
        });
    });
}());
