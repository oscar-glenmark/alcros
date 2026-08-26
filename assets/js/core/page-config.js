(function () {
    'use strict';

    function readConfig(id) {
        var el = document.getElementById(id || 'page-config');
        if (!el) return {};
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (e) {
            return {};
        }
    }

    window.AlcrosPage = {
        readConfig: readConfig
    };
})();
