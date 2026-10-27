(function () {
    'use strict';

    function refreshFullscreenIcon(btn, isFullscreen) {
        var icon = btn.querySelector('[data-lucide]');
        if (!icon) {
            return;
        }
        icon.setAttribute('data-lucide', isFullscreen ? 'minimize' : 'maximize');
        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
            lucide.createIcons({ nameAttr: 'data-lucide', nodes: [icon] });
        }
        var label = isFullscreen ? 'Exit full screen' : 'Enter full screen';
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', isFullscreen ? 'Exit full screen' : 'Full screen');
        btn.classList.toggle('is-fullscreen-active', isFullscreen);
    }

    function bindFullscreenToggle() {
        var btn = document.getElementById('display-fullscreen-btn');
        if (!btn) {
            return;
        }

        function syncFullscreenState() {
            refreshFullscreenIcon(btn, !!document.fullscreenElement);
        }

        btn.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(function () { /* user gesture or policy */ });
                return;
            }
            var root = document.documentElement;
            var request = root.requestFullscreen || root.webkitRequestFullscreen;
            if (typeof request === 'function') {
                request.call(root).catch(function () { /* unsupported or blocked */ });
            }
        });

        document.addEventListener('fullscreenchange', syncFullscreenState);
        document.addEventListener('webkitfullscreenchange', syncFullscreenState);
        syncFullscreenState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindFullscreenToggle);
    } else {
        bindFullscreenToggle();
    }

    setInterval(function () {
        var clock = document.getElementById('clock');
        if (!clock) return;
        var now = new Date();
        var h = now.getHours() % 12 || 12;
        var m = now.getMinutes().toString().padStart(2, '0');
        clock.textContent = h.toString().padStart(2, '0') + ':' + m + ' ' + (now.getHours() >= 12 ? 'PM' : 'AM');
    }, 1000);
})();
