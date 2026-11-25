/**
 * «nur Steuer» locks «Soll» — DISPLAY ONLY (VAT return stage S5, owner decision S56-2; board S5-1,
 * parts 1 and 3). In the receipt window and the template window the VAT select carries the token
 * `VM:nur-steuer`; while such an option is chosen, the field «Soll» is read-only and shows the input
 * account of the code (the option's `data-tax-only-account`, VM → 1170) with «automatisch». The
 * SERVER decides: it clears a typed Soll and derives the account from the code on save and at
 * posting (`ReceiptJournalService::update()`, `ReceiptTemplateService::save()`,
 * `ReceiptPostingBuilder`) — this script only keeps the user from typing into a field that is
 * ignored (Rule 7: no rule lives here).
 *
 *   [data-tax-only-select]   the VAT select (its options: [data-tax-only-account] = «nur Steuer»)
 *   [data-tax-only-debit]    «Soll» in the same form
 *   [data-tax-only-note]     shown while locked («automatisch»)
 *   [data-tax-only-hide]     hidden while locked (the typed account's name)
 *
 * One delegated listener (`change`, and `input` — core.js «fill» sets the select and fires it), so a
 * window loaded again needs no init. The value typed before the lock comes back when the lock goes,
 * unless something (the template's «fill») has set the field meanwhile.
 */
var _Z77 = _Z77 || {};
_Z77.receipt = _Z77.receipt || {};

_Z77.receipt.taxOnly = _Z77.receipt.taxOnly || (function () {
    'use strict';

    function sync(select) {
        var form = select.form;
        if (!form) return;
        var debit = form.querySelector('[data-tax-only-debit]');
        if (!debit) return;
        var option = select.options[select.selectedIndex];
        var locked = !!(option && option.hasAttribute('data-tax-only-account'));

        if (locked) {
            if (!debit.hasAttribute('data-tax-only-kept')) debit.setAttribute('data-tax-only-kept', debit.value);
            var account = option.getAttribute('data-tax-only-account') || '';
            debit.value = account;
            debit.setAttribute('data-tax-only-auto', account);
            debit.readOnly = true;
        } else if (debit.hasAttribute('data-tax-only-kept')) {
            if (debit.value === debit.getAttribute('data-tax-only-auto')) debit.value = debit.getAttribute('data-tax-only-kept');
            debit.removeAttribute('data-tax-only-kept');
            debit.removeAttribute('data-tax-only-auto');
            debit.readOnly = false;
        }
        form.querySelectorAll('[data-tax-only-note]').forEach(function (el) { el.hidden = !locked; });
        form.querySelectorAll('[data-tax-only-hide]').forEach(function (el) { el.hidden = locked; });
    }

    function onChoice(e) {
        var target = e.target;
        if (target && target.matches && target.matches('select[data-tax-only-select]')) sync(target);
    }

    document.addEventListener('change', onChoice);
    document.addEventListener('input', onChoice);

    return { sync: sync };
})();
