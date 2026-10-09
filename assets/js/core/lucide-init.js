(function () {
    'use strict';

    function refreshLucideIcons() {
        if (typeof lucide === 'undefined' || typeof lucide.createIcons !== 'function') {
            return;
        }
        var root = document.querySelector('.admin-main') || document.querySelector('main') || document.body;
        var options = { nameAttr: 'data-lucide' };
        if (root && root !== document.body) {
            options.root = root;
        }
        lucide.createIcons(options);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshLucideIcons);
    } else {
        refreshLucideIcons();
    }

    window.addEventListener('load', refreshLucideIcons);
})();
