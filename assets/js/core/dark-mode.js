(function (global) {
    'use strict';

    var STORAGE_KEY = 'alcros_theme';
    var root = document.documentElement;

    function isDark() {
        return root.classList.contains('alcros-dark');
    }

    function setDark(on, persist) {
        root.classList.toggle('alcros-dark', !!on);
        if (persist !== false) {
            try {
                localStorage.setItem(STORAGE_KEY, on ? 'dark' : 'light');
            } catch (e) { /* ignore */ }
        }
        updateToggleButton();
        try {
            global.dispatchEvent(new CustomEvent('alcros:theme-change', { detail: { dark: !!on } }));
        } catch (e) { /* ignore */ }
        try {
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons();
            }
        } catch (e) { /* ignore */ }
    }

    function toggle() {
        setDark(!isDark());
    }

    function updateToggleButton() {
        var btn = document.getElementById('alcrosThemeToggle');
        if (!btn) return;
        var dark = isDark();
        btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
        btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        btn.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
    }

    function bindToggle() {
        var btn = document.getElementById('alcrosThemeToggle');
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggle();
        });
        updateToggleButton();
    }

    global.AlcrosTheme = {
        isDark: isDark,
        setDark: setDark,
        toggle: toggle,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindToggle);
    } else {
        bindToggle();
    }
})(window);
