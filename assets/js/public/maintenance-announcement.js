(function () {
    'use strict';

    var STORAGE_KEY = 'alcros_maintenance_ack';
    var overlay = document.getElementById('alcros-maintenance-overlay');
    if (!overlay) return;

    var ackBtn = document.getElementById('alcros-maintenance-ack');
    var isVisible = false;

    function alreadyAcknowledged() {
        try {
            return sessionStorage.getItem(STORAGE_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function saveAcknowledged() {
        try {
            sessionStorage.setItem(STORAGE_KEY, '1');
        } catch (e) {}
    }

    function openModal() {
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        requestAnimationFrame(function () {
            overlay.classList.add('is-open');
        });
        isVisible = true;
    }

    function closeModal() {
        if (!isVisible) return;
        overlay.classList.remove('is-open');
        isVisible = false;
        window.setTimeout(function () {
            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            document.dispatchEvent(new CustomEvent('alcros:maintenance-acknowledged'));
        }, 250);
    }

    if (ackBtn) {
        ackBtn.addEventListener('click', function () {
            saveAcknowledged();
            closeModal();
        });
    }

    if (alreadyAcknowledged()) {
        overlay.remove();
        document.dispatchEvent(new CustomEvent('alcros:maintenance-acknowledged'));
        return;
    }

    openModal();
})();
