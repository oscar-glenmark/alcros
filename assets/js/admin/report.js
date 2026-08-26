(function () {
    'use strict';

    var btn = document.getElementById('reportDownloadBtn');
    var panel = document.getElementById('reportDownloadPanel');
    var menu = document.getElementById('reportDownloadMenu');
    if (!btn || !panel) return;

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('hidden');
    });

    document.addEventListener('click', function (e) {
        if (menu && !menu.contains(e.target)) {
            panel.classList.add('hidden');
        }
    });
})();
