(function () {
    'use strict';

    var STORAGE_KEY = 'alcros_privacy_accepted';
    var STORAGE_VERSION = '1';

    var overlay = document.getElementById('alcros-privacy-overlay');
    if (!overlay) return;

    var closeBtn = document.getElementById('alcros-privacy-close');
    var consentFooter = document.getElementById('alcros-privacy-consent-footer');
    var viewFooter = document.getElementById('alcros-privacy-view-footer');
    var dismissBtn = document.getElementById('alcros-privacy-dismiss');
    var checkbox = document.getElementById('alcros-privacy-checkbox');
    var acceptBtn = document.getElementById('alcros-privacy-accept');
    var isConsentMode = false;
    var isVisible = false;

    function setMode(consent) {
        isConsentMode = consent;
        if (consentFooter) consentFooter.classList.toggle('hidden', !consent);
        if (viewFooter) viewFooter.classList.toggle('hidden', consent);
        if (closeBtn) closeBtn.classList.toggle('hidden', consent);
    }

    function openModal(mode) {
        mode = mode || 'view';
        setMode(mode === 'consent');
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
        }, 250);
    }

    window.AlcrosPrivacy = {
        open: function () { openModal('view'); },
        close: closeModal
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (dismissBtn) dismissBtn.addEventListener('click', closeModal);

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay && !isConsentMode) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isVisible && !isConsentMode) closeModal();
    });

    document.querySelectorAll('[data-open-privacy]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            openModal('view');
        });
    });

    if (checkbox && acceptBtn) {
        checkbox.addEventListener('change', function () {
            acceptBtn.disabled = !checkbox.checked;
        });

        acceptBtn.addEventListener('click', function () {
            if (!checkbox.checked) return;

            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    accepted: true,
                    version: STORAGE_VERSION,
                    acceptedAt: new Date().toISOString()
                }));
            } catch (e) {}

            closeModal();
            document.dispatchEvent(new CustomEvent('alcros:privacy-accepted'));
        });
    }

    function runPrivacyAutoShow() {
        try {
            var stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (stored && stored.version === STORAGE_VERSION && stored.accepted === true) {
                return;
            }
        } catch (e) {}

        openModal('consent');
    }

    if (document.getElementById('alcros-maintenance-overlay')) {
        document.addEventListener('alcros:maintenance-acknowledged', runPrivacyAutoShow, { once: true });
    } else {
        runPrivacyAutoShow();
    }
})();
