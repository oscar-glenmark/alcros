(function () {
    'use strict';

    function refreshLucideIcons() {
        if (typeof lucide === 'undefined' || typeof lucide.createIcons !== 'function') {
            return;
        }
        var root = document.querySelector('.admin-main') || document.querySelector('main') || document.body;
        var nodes = root.querySelectorAll('[data-lucide]');
        if (!nodes.length) {
            return;
        }
        lucide.createIcons({ nameAttr: 'data-lucide', nodes: Array.prototype.slice.call(nodes) });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshLucideIcons);
    } else {
        refreshLucideIcons();
    }

    window.addEventListener('load', refreshLucideIcons);
})();
