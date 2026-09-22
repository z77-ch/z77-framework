/* z77 page editor — the backend side (ADR-045 §4)
 *
 * Loaded only by ContentController::slotAction on its bare page, which the
 * website shows in an iframe (content-edit.js of module-frontend). Two jobs:
 *
 *   1. Start the block editor on the full page. In the shell the editor is a
 *      modal and `load-script` runs its init; here the page is loaded whole, so
 *      the init runs once on DOMContentLoaded against the page body
 *      ([data-z77-popup-body], html-bare-skeleton). A form re-rendered after a
 *      failed save brings its own `load-script` command, as in the shell.
 *   2. Closing: «Abbrechen» ([data-ce-slot-close]) and Esc ask the parent window
 *      to close the editor — {type: 'z77:content-edit-close'}, same origin only.
 *      Esc is handled here because a key pressed inside the iframe never reaches
 *      the parent document.
 *
 * Saving is not handled here: the server answers a successful save with the
 * core command `post-message` {type: 'z77:content-saved'}.
 */
(function () {
    'use strict';

    function closeEditor() {
        if (window.parent === window) return;
        window.parent.postMessage({ type: 'z77:content-edit-close' }, window.location.origin);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var body = document.querySelector('[data-z77-popup-body]');
        var init = _Z77.scriptInit['content-editor'];
        if (body && typeof init === 'function') init(body);
    });

    // Delegated: the cancel button is replaced with the form after a failed save.
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('[data-ce-slot-close]')) closeEditor();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeEditor();
    });
})();
