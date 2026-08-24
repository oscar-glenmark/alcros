<?php
/**
 * Privacy & Safety floating panel (first-visit consent + on-demand view).
 */
$privacySite = $site ?? getSiteSettings();
$privacyPolicyUrl = getSetting('privacy_policy_url', 'privacy.php');
?>
<style>
    #alcros-privacy-overlay {
        transition: opacity 0.25s ease;
    }
    #alcros-privacy-overlay.is-open {
        opacity: 1;
    }
    #alcros-privacy-panel {
        transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.25s ease;
    }
    #alcros-privacy-overlay.is-open #alcros-privacy-panel {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
</style>
<div id="alcros-privacy-overlay" class="hidden fixed inset-0 z-[9999] flex items-end sm:items-center justify-center p-4 sm:p-6 bg-slate-900/70 backdrop-blur-sm opacity-0" role="dialog" aria-modal="true" aria-labelledby="alcros-privacy-title">
    <div id="alcros-privacy-panel" class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden border border-gray-100 translate-y-8 scale-95 opacity-0 sm:translate-y-4">
        <div class="px-6 pt-6 pb-4 border-b border-gray-100">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h2 id="alcros-privacy-title" class="text-lg font-extrabold text-slate-900">Privacy &amp; Safety Notice</h2>
                        <p class="text-[11px] text-gray-500 truncate"><?= htmlspecialchars($privacySite['name']) ?> — <?= htmlspecialchars($privacySite['office']) ?></p>
                    </div>
                </div>
                <button type="button" id="alcros-privacy-close" class="hidden shrink-0 p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="px-6 py-4 overflow-y-auto text-[12px] text-gray-600 leading-relaxed space-y-3 flex-1">
            <p>Welcome to the <?= htmlspecialchars($privacySite['name']) ?> online portal. Before you continue, please read how we collect, use, and protect your personal information in accordance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>.</p>
            <ul class="list-disc pl-5 space-y-1.5">
                <li>We collect personal data only for legitimate civil registry services such as document requests, appointments, and queue management.</li>
                <li>Information you submit (name, contact details, valid IDs, and related records) is processed securely and accessed only by authorized LGU personnel.</li>
                <li>Tracking codes and appointment references are provided so you can monitor your request status transparently.</li>
                <li>We do not sell or share your data with third parties except as required by law or with your consent.</li>
                <li>You may contact our office regarding your data rights, corrections, or concerns about how your information is handled.</li>
            </ul>
            <p>By using this portal, you acknowledge that you understand our privacy practices and agree to the safe, lawful processing of your personal data.</p>
            <p class="text-[11px]">Read the full policy: <a href="<?= htmlspecialchars($privacyPolicyUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 font-semibold hover:underline">Privacy &amp; Safety Policy</a></p>
        </div>
        <div id="alcros-privacy-consent-footer" class="px-6 py-4 border-t border-gray-100 bg-gray-50 space-y-3">
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" id="alcros-privacy-checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-xs font-semibold text-slate-800">I have read and agree to the Privacy &amp; Safety Policy</span>
            </label>
            <button type="button" id="alcros-privacy-accept" disabled class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl py-3 text-sm font-bold transition">
                Continue to Portal
            </button>
        </div>
        <div id="alcros-privacy-view-footer" class="hidden px-6 py-4 border-t border-gray-100 bg-gray-50">
            <button type="button" id="alcros-privacy-dismiss" class="w-full border border-gray-200 hover:bg-white text-slate-700 rounded-xl py-3 text-sm font-bold transition">
                Close
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    var STORAGE_KEY = 'alcros_privacy_accepted';
    var STORAGE_VERSION = '1';

    var overlay = document.getElementById('alcros-privacy-overlay');
    if (!overlay) return;

    var panel = document.getElementById('alcros-privacy-panel');
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

    try {
        var stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        if (stored && stored.version === STORAGE_VERSION && stored.accepted === true) {
            return;
        }
    } catch (e) {}

    openModal('consent');
})();
</script>
