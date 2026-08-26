(function () {
    'use strict';

    var INTERVAL_MS = 60000;
    var lastRun = 0;

    function ping() {
        if (document.hidden) return;
        var now = Date.now();
        if (now - lastRun < INTERVAL_MS - 1000) return;
        lastRun = now;
        fetch('api/appointment_reminders.php', { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ping);
    } else {
        ping();
    }
    setInterval(ping, INTERVAL_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) ping();
    });
})();
