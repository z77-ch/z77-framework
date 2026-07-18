var _Z77 = _Z77 || {};
_Z77.backend = _Z77.backend || {};

_Z77.backend.cache = (function () {
    function init() {
        var debugBtn = document.getElementById('js-debug-toggle');
        if (debugBtn) {
            debugBtn.addEventListener('click', function (e) {
                e.preventDefault();
                _toggleDebug(debugBtn.getAttribute('data-url'));
            });
        }

        var noindexBtn = document.getElementById('js-noindex-toggle');
        if (noindexBtn) {
            noindexBtn.addEventListener('click', function (e) {
                e.preventDefault();
                _toggleNoindex(noindexBtn.getAttribute('data-url'));
            });
        }

        var cacheBtn = document.getElementById('js-clear-cache');
        if (cacheBtn) {
            cacheBtn.addEventListener('click', function (e) {
                e.preventDefault();
                _Z77.core.fetch.post(cacheBtn.getAttribute('data-url'));
            });
        }
    }

    function _toggleDebug(url) {
        _Z77.core.fetch.post(url).then(function (env) {
            if (!env || env.status !== 'success') { return; }
            var on = !!env.data.devMode;
            var indicator = document.getElementById('js-debug-indicator');
            if (indicator) {
                indicator.classList.toggle('backend-service-panel__toggle--on', on);
            }
            var avatar = document.getElementById('js-avatar-btn');
            if (avatar) {
                avatar.classList.toggle('backend-topbar__avatar--open', on);
            }
        });
    }

    function _toggleNoindex(url) {
        _Z77.core.fetch.post(url).then(function (env) {
            if (!env || env.status !== 'success') { return; }
            var on = !!env.data.noindex;
            var indicator = document.getElementById('js-noindex-indicator');
            if (indicator) {
                indicator.classList.toggle('backend-service-panel__toggle--on', on);
            }
            var banner = document.getElementById('js-noindex-banner');
            if (banner) {
                banner.hidden = !on;
            }
        });
    }

    init();

    return {};
})();
