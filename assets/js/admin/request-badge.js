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

    function applyCounts(counts) {
        var badge = document.getElementById('sidebar-request-badge');
        if (!badge) return;
        updateBadgeEl(badge, parseInt((counts || {}).pending_requests, 10) || 0);
    }

    document.addEventListener('alcros:admin-live', function (e) {
        applyCounts((e.detail || {}).counts);
    });
})();
