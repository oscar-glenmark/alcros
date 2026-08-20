<?php
/**
 * First-visit Privacy & Safety agreement modal (shown once per browser via localStorage).
 */
$privacySite = $site ?? getSiteSettings();
$privacyPolicyUrl = getSetting('privacy_policy_url', 'privacy.php');
?>
<div id="alcros-privacy-overlay" class="hidden fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="alcros-privacy-title">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden border border-gray-100">
        <div class="px-6 pt-6 pb-4 border-b border-gray-100">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div>
                    <h2 id="alcros-privacy-title" class="text-lg font-extrabold text-slate-900">Privacy &amp; Safety Notice</h2>
                    <p class="text-[11px] text-gray-500"><?= htmlspecialchars($privacySite['name']) ?> — <?= htmlspecialchars($privacySite['office']) ?></p>
                </div>
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
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 space-y-3">
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" id="alcros-privacy-checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-xs font-semibold text-slate-800">I have read and agree to the Privacy &amp; Safety Policy</span>
            </label>
            <button type="button" id="alcros-privacy-accept" disabled class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl py-3 text-sm font-bold transition">
                Continue to Portal
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

    try {
        var stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        if (stored && stored.version === STORAGE_VERSION && stored.accepted === true) {
            overlay.remove();
            return;
        }
    } catch (e) {}

    var checkbox = document.getElementById('alcros-privacy-checkbox');
    var acceptBtn = document.getElementById('alcros-privacy-accept');

    overlay.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');

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

            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            overlay.remove();
            document.dispatchEvent(new CustomEvent('alcros:privacy-accepted'));
        });
    }
})();
</script>
