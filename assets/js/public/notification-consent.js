(function () {
    'use strict';

    var STORAGE_KEY = 'alcros_notify_consent';
    var STORAGE_VERSION = '2';
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

    function readStoredDecision() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (e) {
            return null;
        }
    }

    function alreadyDecided() {
        var stored = readStoredDecision();
        return !!(stored && stored.version === STORAGE_VERSION && typeof stored.allowed === 'boolean');
    }

    function syncNotifyFormFields(emailAllowed, smsAllowed) {
        document.querySelectorAll('input[name="notify_email"]').forEach(function (input) {
            if (input.type === 'checkbox') {
                input.checked = !!emailAllowed;
            } else {
                input.value = emailAllowed ? '1' : '0';
            }
        });
        document.querySelectorAll('input[name="notify_sms"]').forEach(function (input) {
            if (input.type === 'checkbox') {
                input.checked = !!smsAllowed;
            } else {
                input.value = smsAllowed ? '1' : '0';
            }
        });
    }

    function saveDecision(emailAllowed, smsAllowed) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                allowed: !!emailAllowed,
                smsAllowed: !!smsAllowed,
                version: STORAGE_VERSION,
                decidedAt: new Date().toISOString()
            }));
        } catch (e) {}

        syncNotifyFormFields(!!emailAllowed, !!smsAllowed);
        document.dispatchEvent(new CustomEvent('alcros:notify-consent', {
            detail: { allowed: !!emailAllowed, smsAllowed: !!smsAllowed }
        }));
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

    function refreshAcceptButton() {
        if (!acceptBtn) return;
        var emailChecked = checkbox && checkbox.checked;
        var smsChecked = smsCheckbox && smsCheckbox.checked;
        acceptBtn.disabled = !(emailChecked || smsChecked);
    }

    var checkbox = document.getElementById('alcros-notify-checkbox');
    var smsCheckbox = document.getElementById('alcros-notify-sms-checkbox');
    var acceptBtn = document.getElementById('alcros-notify-accept');
    var declineBtn = document.getElementById('alcros-notify-decline');

    if (checkbox) {
        checkbox.addEventListener('change', refreshAcceptButton);
    }
    if (smsCheckbox) {
        smsCheckbox.addEventListener('change', refreshAcceptButton);
    }
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function () {
            var emailAllowed = !!(checkbox && checkbox.checked);
            var smsAllowed = !!(smsCheckbox && smsCheckbox.checked);
            if (!emailAllowed && !smsAllowed) return;
            saveDecision(emailAllowed, smsAllowed);
            closeOverlay();
        });
    }
    if (declineBtn) {
        declineBtn.addEventListener('click', function () {
            saveDecision(false, false);
            closeOverlay();
        });
    }

    if (alreadyDecided()) {
        var stored = readStoredDecision();
        saveDecision(!!(stored && stored.allowed), !!(stored && stored.smsAllowed));
        overlay.remove();
        return;
    }

    var legacyStored = readStoredDecision();
    if (legacyStored && legacyStored.version !== STORAGE_VERSION && typeof legacyStored.allowed === 'boolean') {
        saveDecision(!!legacyStored.allowed, false);
        overlay.remove();
        return;
    }

    if (privacyAccepted()) {
        showOverlay();
    } else {
        document.addEventListener('alcros:privacy-accepted', showOverlay);
    }
})();
