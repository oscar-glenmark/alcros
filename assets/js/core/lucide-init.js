(function () {
    'use strict';

    function refreshLucideIcons() {
        if (typeof lucide === 'undefined' || typeof lucide.createIcons !== 'function') {
            return;
        }
        lucide.createIcons();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshLucideIcons);
    } else {
        refreshLucideIcons();
    }

    window.addEventListener('load', refreshLucideIcons);
})();
