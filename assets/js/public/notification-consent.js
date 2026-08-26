(function () {
    'use strict';

    var STORAGE_KEY = 'alcros_notify_consent';
    var STORAGE_VERSION = '1';
    var PRIVACY_KEY = 'alcros_privacy_accepted';

    var overlay = document.getElementById('alcros-notify-overlay');
    if (!overlay) return;

    function privacyAccepted() {
        try {
            var stored = JSON.parse(localStorage.getItem(PRIVACY_KEY) || 'null');
            return !!(stored && stored.version && stored.accepted === true);
        } catch (e) {
            return false;
        }
    }

    function alreadyDecided() {
        try {
            var stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            return !!(stored && stored.version === STORAGE_VERSION && typeof stored.allowed === 'boolean');
        } catch (e) {
            return false;
        }
    }

    function saveDecision(allowed) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                allowed: !!allowed,
                version: STORAGE_VERSION,
                decidedAt: new Date().toISOString()
            }));
        } catch (e) {}
        document.querySelectorAll('input[name="notify_email"]').forEach(function (input) {
            if (input.type === 'checkbox') {
                input.checked = !!allowed;
            } else {
                input.value = allowed ? '1' : '0';
            }
        });
        document.dispatchEvent(new CustomEvent('alcros:notify-consent', { detail: { allowed: !!allowed } }));
    }

    function closeOverlay() {
        overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        overlay.remove();
    }

    function showOverlay() {
        if (alreadyDecided()) {
            overlay.remove();
            return;
        }
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    var checkbox = document.getElementById('alcros-notify-checkbox');
    var acceptBtn = document.getElementById('alcros-notify-accept');
    var declineBtn = document.getElementById('alcros-notify-decline');

    if (checkbox && acceptBtn) {
        checkbox.addEventListener('change', function () {
            acceptBtn.disabled = !checkbox.checked;
        });
        acceptBtn.addEventListener('click', function () {
            if (!checkbox.checked) return;
            saveDecision(true);
            closeOverlay();
        });
    }
    if (declineBtn) {
        declineBtn.addEventListener('click', function () {
            saveDecision(false);
            closeOverlay();
        });
    }

    if (alreadyDecided()) {
        try {
            var stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            saveDecision(!!(stored && stored.allowed));
        } catch (e) {}
        overlay.remove();
        return;
    }

    if (privacyAccepted()) {
        showOverlay();
    } else {
        document.addEventListener('alcros:privacy-accepted', showOverlay);
    }
})();
