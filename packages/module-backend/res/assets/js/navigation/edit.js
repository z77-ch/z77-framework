/* z77 navigation edit popup
 *
 * Loaded lazily via the `load-script` command from NavigationController::edit().
 * Re-invoked on every popup open: the init function runs against the freshly
 * mounted modal body — it must not assume singleton state, only read/write the
 * scope it was given.
 *
 * Responsibilities:
 *   - toggle routing section visibility when a ref target is selected
 *
 * (The params editor was removed with Phase 4 — Navigation has no own params;
 *  public URLs live in NavigationAlias, see ADR-015.)
 */
(function () {
    'use strict';

    _Z77.scriptInit['navigation-edit'] = function (scope) {
        initRefToggle(scope);
    };

    function initRefToggle(scope) {
        var refSelect = scope.querySelector('select[name="ref"]');
        if (!refSelect) return;

        var routingSection = scope.querySelector('[data-section="routing"]');
        var routingInputs  = scope.querySelectorAll(
            '[name="module"],[name="group"],[name="controller"],[name="action"]'
        );

        function apply() {
            var hasRef = refSelect.value !== '';
            if (routingSection) {
                routingSection.style.display = hasRef ? 'none' : '';
            }
            if (hasRef) {
                routingInputs.forEach(function (i) { i.value = ''; });
            }
        }

        refSelect.addEventListener('change', apply);
        apply();
    }
}());
