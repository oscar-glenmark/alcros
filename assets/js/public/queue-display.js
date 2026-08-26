(function () {
    'use strict';

    setInterval(function () {
        var clock = document.getElementById('clock');
        if (!clock) return;
        var now = new Date();
        var h = now.getHours() % 12 || 12;
        var m = now.getMinutes().toString().padStart(2, '0');
        clock.textContent = h.toString().padStart(2, '0') + ':' + m + ' ' + (now.getHours() >= 12 ? 'PM' : 'AM');
    }, 1000);
})();
