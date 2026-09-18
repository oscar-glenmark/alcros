(function () {
    'use strict';

    function updateBadgeEl(badge, count) {
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function init() {
        if (!window.AlcrosPoll) return;

        var badge = document.getElementById('sidebar-request-badge');
        if (!badge) return;

        AlcrosPoll.pollJson('api/request_summary.php', {}, 60000, function (data) {
            updateBadgeEl(badge, parseInt(data.pending_count, 10) || 0);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
