var _Z77 = _Z77 || {};
_Z77.backend = _Z77.backend || {};

_Z77.backend.appearance = (function () {
    var root = document.documentElement;

    function _palette()      { return root.dataset.bePalette; }
    function _isDark()       { return root.dataset.beTheme === 'dark'; }
    function _getFontScale() {
        var v = parseFloat(root.style.getPropertyValue('--be-font-scale'));
        return isNaN(v) ? 1.0 : v;
    }

    function _setPalette(p)       { root.dataset.bePalette = p; }
    function _setTheme(dark)      { root.dataset.beTheme   = dark ? 'dark' : 'light'; }
    function _setFontScale(scale) { root.style.setProperty('--be-font-scale', scale); }

    function _save(palette, dark, fontScale) {
        _Z77.core.fetch.post('/backend/system/system/save-preferences', {
            palette:   palette,
            darkMode:  dark,
            fontScale: fontScale,
        });
    }

    function _syncButtons() {
        var palette = _palette();
        var btns    = document.querySelectorAll('[data-palette-btn]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.toggle(
                'backend-service-panel__palette-btn--active',
                btns[i].getAttribute('data-palette-btn') === palette
            );
        }
        var indicator = document.getElementById('js-dark-indicator');
        if (indicator) {
            indicator.classList.toggle('backend-service-panel__toggle--on', _isDark());
        }
    }

    function init() {
        var btns = document.querySelectorAll('[data-palette-btn]');
        var i;

        for (i = 0; i < btns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var p = btn.getAttribute('data-palette-btn');
                    _setPalette(p);
                    _syncButtons();
                    _save(p, _isDark(), _getFontScale());
                });
            })(btns[i]);
        }

        var darkBtn = document.getElementById('js-dark-toggle');
        if (darkBtn) {
            darkBtn.addEventListener('click', function () {
                var dark = !_isDark();
                _setTheme(dark);
                _syncButtons();
                _save(_palette(), dark, _getFontScale());
            });
        }

        var toggleBtn  = document.getElementById('js-appearance-toggle');
        var toggleBody = document.getElementById('js-appearance-body');
        if (toggleBtn && toggleBody) {
            toggleBtn.addEventListener('click', function () {
                var open = toggleBody.hidden;
                toggleBody.hidden = !open;
                toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        var fontSlider = document.getElementById('js-font-scale');
        if (fontSlider) {
            fontSlider.addEventListener('input', function () {
                _setFontScale(fontSlider.value / 100);
            });
            fontSlider.addEventListener('change', function () {
                _save(_palette(), _isDark(), fontSlider.value / 100);
            });
        }
    }

    init();

    return {};
})();
