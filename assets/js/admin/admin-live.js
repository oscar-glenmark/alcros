(function () {
    'use strict';

    if (!window.AlcrosPoll) return;

    var INTERVAL_MS = (window.AlcrosPollConfig && AlcrosPollConfig.adminBadgeMs) || 15000;

    function pollParams() {
        var params = {};
        if (document.getElementById('notif-page-list')) {
            params.limit = 50;
        }
        return params;
    }

    function dispatchLive(data) {
        document.dispatchEvent(new CustomEvent('alcros:admin-live', { detail: data || {} }));
    }

    function startPolling() {
        AlcrosPoll.pollJson('api/admin_live.php', pollParams, INTERVAL_MS, function (data) {
            dispatchLive(data);
            AlcrosPoll.markLiveIndicator();
        });
    }

    window.AlcrosAdminLive = {
        intervalMs: INTERVAL_MS,
        refresh: function () {
            fetch(AlcrosPoll.buildUrl('api/admin_live.php', pollParams()), {
                credentials: 'same-origin',
                cache: 'no-store'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || data.ok === false) return;
                    dispatchLive(data);
                    AlcrosPoll.markLiveIndicator();
                })
                .catch(function () { /* ignore */ });
        }
    };

    document.addEventListener('DOMContentLoaded', startPolling);
})();
